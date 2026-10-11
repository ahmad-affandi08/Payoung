<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Kueri\TemplateTerbit;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanSatuanStandarBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\SiapkanTemplateSektorBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Referensi\Model\SatuanStandar;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

function MasukSebagaiTemplate(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function UrlVersi(string $kode, int $versi, string $akhiran = ''): string
{
    return BantuanPengelola::Url("/template-sektor/{$kode}/versi/{$versi}{$akhiran}");
}

function AmbilVersiUji(string $kode, int $versi): TemplateSektorVersi
{
    return TemplateSektorVersi::query()
        ->whereHas('TemplateSektor', fn ($kueri) => $kueri->where('Kode', $kode))
        ->where('Versi', $versi)
        ->sole();
}

/**
 * @return array<string, mixed>
 */
function AmbilIsiBisnisUji(string $kode, int $versi = 1): array
{
    return array_intersect_key(AmbilVersiUji($kode, $versi)->Isi, array_flip([
        'ModeKasir', 'ModeKasirDefault', 'KunciFitur', 'Kategori', 'KodeSatuan', 'Pengaturan',
        'StasiunDapur', 'AlasanVoid', 'AlasanPenyesuaian', 'LaporanUnggulan', 'ProdukContoh',
    ]));
}

/**
 * @return array<string, mixed>
 */
function AmbilIsiAkunUji(string $kode, int $versi = 1): array
{
    return array_intersect_key(AmbilVersiUji($kode, $versi)->Isi, array_flip(['Akun', 'PemetaanAkun', 'KelompokPajak']));
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanSatuanStandarBawaan::class)->Jalankan();
    app(SiapkanPajakBawaan::class)->Jalankan();
    app(SiapkanKatalogBawaan::class)->Jalankan();
    app(SiapkanTemplateSektorBawaan::class)->Jalankan();
});

describe('Izin template sektor (BR-P03.5)', function (): void {
    it('semua peran bisa melihat, tetapi hanya pemilik bagian yang bisa mengubahnya', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis));
        $this->get(BantuanPengelola::Url('/template-sektor'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Pengelola/TemplateSektor/Daftar')->has('Template', 19));
        $this->get(UrlVersi('FNB-CAF', 1))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/TemplateSektor/Editor')
                ->where('Versi.Status', 'Draf')
                ->has('Pilihan.PeranAkun', 41)); // v3.61: + KliringTukarBarang
        $this->put(UrlVersi('FNB-CAF', 1, '/isi-bisnis'), AmbilIsiBisnisUji('FNB-CAF'))->assertForbidden();
        $this->post(UrlVersi('FNB-CAF', 1, '/validasi'))->assertForbidden();

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));
        $this->put(UrlVersi('FNB-CAF', 1, '/isi-bisnis'), AmbilIsiBisnisUji('FNB-CAF'))->assertSessionHasNoErrors();
        $this->put(UrlVersi('FNB-CAF', 1, '/akun'), AmbilIsiAkunUji('FNB-CAF'))->assertForbidden();
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertForbidden();

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));
        $this->put(UrlVersi('FNB-CAF', 1, '/akun'), AmbilIsiAkunUji('FNB-CAF'))->assertSessionHasNoErrors();
        $this->put(UrlVersi('FNB-CAF', 1, '/isi-bisnis'), AmbilIsiBisnisUji('FNB-CAF'))->assertForbidden();
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertForbidden();

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->put(UrlVersi('FNB-CAF', 1, '/akun'), AmbilIsiAkunUji('FNB-CAF'))->assertForbidden();
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();

        expect(AmbilVersiUji('FNB-CAF', 1)->Status)->toBe(StatusTemplateSektor::Terbit);
    });

    it('versi milik template lain tidak bisa diakses lewat URL template ini', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis));

        $this->get(UrlVersi('FNB-CAF', 2))->assertNotFound();
        $this->get(UrlVersi('XXX-YYY', 1))->assertNotFound();
    });
});

describe('Menyunting draf template (P-03 langkah 2)', function (): void {
    it('isi bisnis dan akun disimpan terpisah tanpa saling menimpa, dicatat di log audit, dan divalidasi ulang', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));
        $isi = AmbilIsiBisnisUji('FNB-QSR');
        $isi['Kategori'][] = 'Sambal';
        $isi['Pengaturan']['PersenBiayaLayanan'] = '5';
        $this->put(UrlVersi('FNB-QSR', 1, '/isi-bisnis'), $isi)->assertSessionHasNoErrors();

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));
        $akun = AmbilIsiAkunUji('FNB-QSR');
        $akun['Akun'][] = ['Kode' => '4-1030', 'Nama' => 'Penjualan Paket Hemat', 'Tipe' => 'Pendapatan', 'SaldoNormal' => 'Kredit', 'Kontra' => false];
        unset($akun['PemetaanAkun']['HutangPbjt']);
        $this->put(UrlVersi('FNB-QSR', 1, '/akun'), $akun)->assertSessionHasNoErrors();

        $versi = AmbilVersiUji('FNB-QSR', 1);
        expect($versi->Isi['Kategori'])->toContain('Sambal')
            ->and($versi->Isi['Pengaturan']['PersenBiayaLayanan'])->toBe('5')
            ->and(array_column($versi->Isi['Akun'], 'Kode'))->toContain('4-1030')
            ->and($versi->CekLolosValidasi())->toBeFalse()
            ->and(array_column($versi->HasilValidasi['Galat'] ?? [], 'Pesan'))->toBe(['Peran "Hutang PB1/PBJT" belum dipetakan ke akun.']);

        $logIsi = LogAuditPengelola::query()->where('Aksi', 'template.isi.ubah')->sole();
        expect(array_keys($logIsi->NilaiBaru ?? []))->toBe(['Kategori', 'Pengaturan'])
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.akun.ubah')->count())->toBe(1);
    });

    it('menolak isi dengan bentuk salah sebelum disimpan', function (array $ubah, string $bidang): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));
        $isi = array_replace_recursive(AmbilIsiBisnisUji('FNB-QSR'), $ubah);

        $this->put(UrlVersi('FNB-QSR', 1, '/isi-bisnis'), $isi)->assertSessionHasErrors($bidang);
    })->with([
        'mode kasir tidak dikenal' => [['ModeKasir' => ['Terbang']], 'ModeKasir.0'],
        'kelipatan desimal' => [['Pengaturan' => ['PembulatanTunai' => ['Kelipatan' => '0.5']]], 'Pengaturan.PembulatanTunai.Kelipatan'],
        'metode HPP tidak dikenal' => [['Pengaturan' => ['MetodeHpp' => 'Lifo']], 'Pengaturan.MetodeHpp'],
        'harga produk contoh bertitik ribuan' => [['ProdukContoh' => [0 => ['Harga' => '16.000']]], 'ProdukContoh.0.Harga'],
        'harga produk contoh berupa number' => [['ProdukContoh' => [1 => ['Harga' => 18000]]], 'ProdukContoh.1.Harga'],
        'jenis produk contoh paket' => [['ProdukContoh' => [2 => ['Jenis' => 'Paket']]], 'ProdukContoh.2.Jenis'],
        'nama produk contoh kosong' => [['ProdukContoh' => [3 => ['Nama' => '']]], 'ProdukContoh.3.Nama'],
    ]);

    it('produk contoh disimpan rapi (nama dipangkas, kategori kosong = null), dicatat di log audit, dan divalidasi', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis));
        $this->get(UrlVersi('FNB-QSR', 1))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->has('Versi.Isi.ProdukContoh', 12)
                ->where('Pilihan.JenisProdukContoh', [
                    ['Nilai' => 'Stok', 'Label' => 'Barang dengan stok'],
                    ['Nilai' => 'NonStok', 'Label' => 'Tanpa stok (persediaan tidak dihitung)'],
                    ['Nilai' => 'Jasa', 'Label' => 'Jasa'],
                ]));

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));
        $isi = AmbilIsiBisnisUji('FNB-QSR');
        $this->put(UrlVersi('FNB-QSR', 1, '/isi-bisnis'), $isi)->assertSessionHasNoErrors();
        expect(LogAuditPengelola::query()->where('Aksi', 'template.isi.ubah')->count())->toBe(0);

        $isi['ProdukContoh'][] = ['Nama' => '  Nasi Uduk Komplit Ayam Goreng Serundeng Tempe Orek  ', 'Kategori' => '', 'Harga' => '27500.50', 'KodeSatuan' => 'PORSI', 'Jenis' => 'NonStok'];
        $this->put(UrlVersi('FNB-QSR', 1, '/isi-bisnis'), $isi)->assertSessionHasNoErrors();

        $versi = AmbilVersiUji('FNB-QSR', 1);
        expect($versi->Isi['ProdukContoh'])->toHaveCount(13)
            ->and($versi->Isi['ProdukContoh'][12])->toEqual([
                'Nama' => 'Nasi Uduk Komplit Ayam Goreng Serundeng Tempe Orek',
                'Kategori' => null,
                'Harga' => '27500.50',
                'KodeSatuan' => 'PORSI',
                'Jenis' => 'NonStok',
            ])
            ->and($versi->CekLolosValidasi())->toBeTrue()
            ->and(array_keys(LogAuditPengelola::query()->where('Aksi', 'template.isi.ubah')->sole()->NilaiBaru ?? []))->toBe(['ProdukContoh']);

        $isi['ProdukContoh'][12]['KodeSatuan'] = 'LUSIN';
        $this->put(UrlVersi('FNB-QSR', 1, '/isi-bisnis'), $isi)->assertSessionHasNoErrors();

        expect(array_column(AmbilVersiUji('FNB-QSR', 1)->HasilValidasi['Galat'] ?? [], 'Pesan'))
            ->toBe(['Produk contoh Nasi Uduk Komplit Ayam Goreng Serundeng Tempe Orek: satuan LUSIN tidak ada di daftar satuan template.']);
    });
});

describe('Kunci peran lama pada versi terbit (BR-P03.4, DesainF01 H1)', function (): void {
    it('versi terbit berkunci lama tidak ditulis ulang, tampil dengan kunci baru, dan duplikasinya memakai kunci baru', function (): void {
        $versi = AmbilVersiUji('FNB-CAF', 1);
        $isi = $versi->Isi;
        $isi['PemetaanAkun']['PiutangSettlement'] = $isi['PemetaanAkun']['PiutangPencairan'];
        $isi['PemetaanAkun']['Waste'] = $isi['PemetaanAkun']['SusutPersediaan'];
        unset($isi['PemetaanAkun']['PiutangPencairan'], $isi['PemetaanAkun']['SusutPersediaan'], $isi['ProdukContoh']);
        $versi->update(['Isi' => $isi]);

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();

        $this->get(UrlVersi('FNB-CAF', 1))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Versi.Status', 'Terbit')
                ->where('Versi.Isi.PemetaanAkun.PiutangPencairan', '1-1300')
                ->where('Versi.Isi.PemetaanAkun.SusutPersediaan', '5-1200')
                ->missing('Versi.Isi.PemetaanAkun.PiutangSettlement')
                ->missing('Versi.Isi.PemetaanAkun.Waste')
                ->where('Versi.Isi.ProdukContoh', []));

        $terbit = AmbilVersiUji('FNB-CAF', 1);
        expect($terbit->Isi['PemetaanAkun'])->toHaveKeys(['PiutangSettlement', 'Waste'])
            ->and($terbit->Isi)->not->toHaveKey('ProdukContoh');

        $this->post(UrlVersi('FNB-CAF', 1, '/duplikasi'))->assertSessionHasNoErrors();

        $draf = AmbilVersiUji('FNB-CAF', 2);
        expect($draf->Isi['PemetaanAkun'])->toHaveKeys(['PiutangPencairan', 'SusutPersediaan'])
            ->and($draf->Isi['PemetaanAkun'])->not->toHaveKey('PiutangSettlement')
            ->and($draf->Isi['PemetaanAkun'])->not->toHaveKey('Waste')
            ->and($draf->CekLolosValidasi())->toBeTrue()
            ->and(AmbilVersiUji('FNB-CAF', 1)->Isi['PemetaanAkun'])->toHaveKey('PiutangSettlement');
    });
});

describe('Terbitkan template (BR-P03.3, BR-P03.4)', function (): void {
    it('BR-P03.3: tidak terbit bila validasi gagal, dan hasil validasinya tetap tersimpan', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));

        $this->post(UrlVersi('RTL-GEN', 1, '/terbitkan'))->assertSessionHasErrors('Umum');

        $versi = AmbilVersiUji('RTL-GEN', 1);
        expect($versi->Status)->toBe(StatusTemplateSektor::Draf)
            ->and($versi->HasilValidasi['Lolos'] ?? null)->toBeFalse()
            ->and(array_column($versi->HasilValidasi['Galat'] ?? [], 'Bagian'))->toBe(['KelompokPajak']);
    });

    it('BR-P03.3: validasi dijalankan ulang saat terbit walau draf sebelumnya lolos', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(UrlVersi('FNB-QSR', 1, '/validasi'))->assertSessionHasNoErrors();
        expect(AmbilVersiUji('FNB-QSR', 1)->CekLolosValidasi())->toBeTrue();

        SatuanStandar::query()->where('Kode', 'PORSI')->update(['Aktif' => false]);
        $this->post(UrlVersi('FNB-QSR', 1, '/terbitkan'))->assertSessionHasErrors('Umum');

        expect(AmbilVersiUji('FNB-QSR', 1)->Status)->toBe(StatusTemplateSektor::Draf);
    });

    it('terbit lalu duplikasi menjadi draf versi 2; versi lama menjadi Usang saat versi 2 terbit', function (): void {
        TarifPajak::query()->create([
            'IdJenisPajak' => JenisPajak::query()->where('Kode', 'Ppn')->value('Id'),
            'Tarif' => '12', 'PengaliDppPembilang' => 11, 'PengaliDppPenyebut' => 12,
            'BerlakuMulai' => '2026-01-01', 'Status' => StatusDataMaster::Terbit,
        ]);
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiTemplate($this, $teknis);
        $this->post(UrlVersi('RTL-GEN', 1, '/terbitkan'))->assertSessionHasNoErrors();

        $this->post(UrlVersi('RTL-GEN', 1, '/duplikasi'))->assertRedirect(UrlVersi('RTL-GEN', 2));
        $this->post(UrlVersi('RTL-GEN', 1, '/duplikasi'))->assertSessionHasErrors('Umum');

        $draf = AmbilVersiUji('RTL-GEN', 2);
        expect($draf->Status)->toBe(StatusTemplateSektor::Draf)
            ->and($draf->IdVersiAsal)->toBe(AmbilVersiUji('RTL-GEN', 1)->Id)
            ->and($draf->Isi)->toBe(AmbilVersiUji('RTL-GEN', 1)->Isi);

        $this->post(UrlVersi('RTL-GEN', 2, '/terbitkan'))->assertSessionHasNoErrors();

        $lama = AmbilVersiUji('RTL-GEN', 1);
        $baru = AmbilVersiUji('RTL-GEN', 2);
        expect($lama->Status)->toBe(StatusTemplateSektor::Usang)
            ->and($lama->DiusangkanPada)->not->toBeNull()
            ->and($baru->Status)->toBe(StatusTemplateSektor::Terbit)
            ->and($baru->IdPenggunaPengelolaPenerbit)->toBe($teknis->Id)
            ->and(TemplateSektor::query()->where('Kode', 'RTL-GEN')->sole()->VersiTerbit?->Versi)->toBe(2)
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.terbitkan')->count())->toBe(2);
    });

    it('BR-P03.4: versi terbit tidak bisa diubah, divalidasi-ubah, atau diduplikasi dari draf', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(UrlVersi('FNB-CAF', 1, '/duplikasi'))->assertSessionHasErrors('Umum');
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();

        $isi = AmbilIsiBisnisUji('FNB-CAF');
        $this->put(UrlVersi('FNB-CAF', 1, '/isi-bisnis'), $isi)->assertSessionHasErrors('Umum');
        $this->put(UrlVersi('FNB-CAF', 1, '/akun'), AmbilIsiAkunUji('FNB-CAF'))->assertSessionHasErrors('Umum');
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasErrors('Umum');
    });
});

describe('Versi usang (BR-P03.4)', function (): void {
    it('versi usang tidak bisa diubah; duplikasi dicatat di log audit', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();
        $this->post(UrlVersi('FNB-CAF', 1, '/duplikasi'))->assertSessionHasNoErrors();
        $this->post(UrlVersi('FNB-CAF', 2, '/terbitkan'))->assertSessionHasNoErrors();

        expect(AmbilVersiUji('FNB-CAF', 1)->Status)->toBe(StatusTemplateSektor::Usang);
        $this->put(UrlVersi('FNB-CAF', 1, '/isi-bisnis'), AmbilIsiBisnisUji('FNB-CAF'))->assertSessionHasErrors('Umum');
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasErrors('Umum');

        $log = LogAuditPengelola::query()->where('Aksi', 'template.versi.duplikasi')->sole();
        expect($log->NilaiBaru)->toBe(['Kode' => 'FNB-CAF', 'Versi' => 2, 'VersiAsal' => 1]);
    });

    it('Dukungan dan Mitra & Penjualan boleh melihat tetapi tidak mengubah', function (PeranPengelolaBawaan $peran): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota($peran));

        $this->get(BantuanPengelola::Url('/template-sektor'))->assertOk();
        $this->post(UrlVersi('FNB-CAF', 1, '/duplikasi'))->assertForbidden();
    })->with([PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::MitraPenjualan]);
});

describe('Hapus draf (BR-P03.2)', function (): void {
    it('versi terbit tidak bisa dihapus; draf bisa dihapus dan template kosong ikut terhapus', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();
        $this->delete(UrlVersi('FNB-CAF', 1))->assertSessionHasErrors('Umum');

        $this->post(UrlVersi('FNB-CAF', 1, '/duplikasi'))->assertSessionHasNoErrors();
        $this->delete(UrlVersi('FNB-CAF', 2))->assertRedirect(BantuanPengelola::Url('/template-sektor'));
        expect(TemplateSektorVersi::query()->whereHas('TemplateSektor', fn ($kueri) => $kueri->where('Kode', 'FNB-CAF'))->count())->toBe(1);

        $this->delete(UrlVersi('FNB-QSR', 1))->assertSessionHasNoErrors();
        expect(TemplateSektor::query()->where('Kode', 'FNB-QSR')->exists())->toBeFalse()
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.draf.hapus')->count())->toBe(2)
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.hapus')->count())->toBe(1);
    });
});

describe('Buat template baru (P-03 langkah 1)', function (): void {
    it('Konten & Legal membuat template dari template dasar; isi disalin sebagai draf versi 1', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));

        $this->post(BantuanPengelola::Url('/template-sektor'), [
            'Kode' => 'FNB-KTR', 'Nama' => 'Katering', 'Keterangan' => 'Jasa boga, katering harian', 'KodeTemplateDasar' => 'FNB-CAF',
        ])->assertRedirect(UrlVersi('FNB-KTR', 1));

        $versi = AmbilVersiUji('FNB-KTR', 1);
        expect($versi->Status)->toBe(StatusTemplateSektor::Draf)
            ->and($versi->Isi)->toBe(AmbilVersiUji('FNB-CAF', 1)->Isi)
            ->and($versi->HasilValidasi)->not->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.buat')->count())->toBe(1);
    });

    it('template tanpa dasar dimulai kosong dan belum lolos validasi', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));

        $this->post(BantuanPengelola::Url('/template-sektor'), ['Kode' => 'SVC-RNT', 'Nama' => 'Rental alat'])->assertSessionHasNoErrors();

        expect(AmbilVersiUji('SVC-RNT', 1)->CekLolosValidasi())->toBeFalse();
    });

    it('menolak kode tidak valid, kode yang sudah ada, dan pembuat tanpa izin', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal));
        $this->post(BantuanPengelola::Url('/template-sektor'), ['Kode' => 'fnb-ktr', 'Nama' => 'Katering'])->assertSessionHasErrors('Kode');
        $this->post(BantuanPengelola::Url('/template-sektor'), ['Kode' => 'FNB-CAF', 'Nama' => 'Kafe'])->assertSessionHasErrors('Kode');

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));
        $this->post(BantuanPengelola::Url('/template-sektor'), ['Kode' => 'FNB-KTR', 'Nama' => 'Katering'])->assertForbidden();
    });
});

describe('Nonaktifkan sektor (D-47, BR-P03.2)', function (): void {
    it('sektor terbit bisa dinonaktifkan lalu diaktifkan lagi; hilang dari pilihan tenant baru, audit tercatat', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();
        $tenant = app(TemplateTerbit::class);
        expect($tenant->AmbilKode())->toContain('FNB-CAF');

        $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/nonaktifkan'))->assertSessionHasNoErrors();
        expect(TemplateSektor::query()->where('Kode', 'FNB-CAF')->value('DinonaktifkanPada'))->not->toBeNull()
            ->and($tenant->AmbilKode())->not->toContain('FNB-CAF')
            ->and($tenant->Cari('FNB-CAF'))->toBeNull()
            // Versi tetap tersimpan dan terbaca untuk outlet yang sudah memakainya (BR-P03.1).
            ->and($tenant->CariVersi(AmbilVersiUji('FNB-CAF', 1)->Id))->not->toBeNull();

        $this->get(BantuanPengelola::Url('/template-sektor'))->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Template', fn ($daftar) => collect($daftar)->firstWhere('Kode', 'FNB-CAF')['DinonaktifkanPada'] !== null));

        $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/aktifkan'))->assertSessionHasNoErrors();
        expect($tenant->AmbilKode())->toContain('FNB-CAF')
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.nonaktifkan')->count())->toBe(1)
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.aktifkan')->count())->toBe(1);

        // Idempoten: mengaktifkan yang sudah aktif tidak menambah audit.
        $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/aktifkan'))->assertSessionHasNoErrors();
        expect(LogAuditPengelola::query()->where('Aksi', 'template.aktifkan')->count())->toBe(1);
    });

    it('template yang belum pernah terbit tidak bisa dinonaktifkan', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));

        $this->post(BantuanPengelola::Url('/template-sektor/FNB-QSR/nonaktifkan'))->assertSessionHasErrors('Umum');
        expect(TemplateSektor::query()->where('Kode', 'FNB-QSR')->value('DinonaktifkanPada'))->toBeNull();
    });

    it('hanya peran penerbit yang boleh; peran lain 403', function (): void {
        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(UrlVersi('FNB-CAF', 1, '/terbitkan'))->assertSessionHasNoErrors();

        foreach ([PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::Analis] as $peran) {
            MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota($peran));
            $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/nonaktifkan'))->assertForbidden();
            $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/aktifkan'))->assertForbidden();
        }

        MasukSebagaiTemplate($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/template-sektor/FNB-CAF/nonaktifkan'))->assertSessionHasNoErrors();
        expect(TemplateSektor::query()->where('Kode', 'FNB-CAF')->value('DinonaktifkanPada'))->not->toBeNull();
    });
});
