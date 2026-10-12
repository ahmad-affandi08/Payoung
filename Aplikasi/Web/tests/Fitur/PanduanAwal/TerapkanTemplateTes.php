<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pajak\Model\KelompokPajak;
use App\Domain\Pajak\Model\KelompokPajakDetail;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Tenant\Model\OutletFitur;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * Jumlah baris hasil template di tenant aktif.
 *
 * @return array<string, int>
 */
function HitungDataTemplateUji(): array
{
    return [
        'Akun' => Akun::query()->count(),
        'PemetaanAkun' => PemetaanAkun::query()->count(),
        'Kategori' => Kategori::query()->count(),
        'Satuan' => Satuan::query()->count(),
        'KelompokPajak' => KelompokPajak::query()->count(),
        'KelompokPajakDetail' => KelompokPajakDetail::query()->count(),
        'OutletFitur' => OutletFitur::query()->count(),
        'MetodePembayaran' => MetodePembayaran::query()->count(),
    ];
}

describe('F-01 langkah 2: terapkan template sektor', function (): void {
    it('BR-01.2: COA inti + ekstensi sektor menjadi Akun tenant (tipe, saldo normal, Sistem) dan semua peran akun terpetakan', function (): void {
        $versi = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        $akunTemplate = collect($versi->Isi['Akun']);
        $akun = Akun::query()->get()->keyBy('Kode');
        // D-106: penerapan template juga menyiapkan akun beban biaya umum (bukan akun Sistem, bukan bagian template).
        $kodeBiaya = collect(SiapkanKategoriBiayaBawaan::KATEGORI)->map(fn (array $a): string => $a[0])->unique()->sort()->values()->all();
        expect($akun->keys()->sort()->values()->all())->toBe($akunTemplate->pluck('Kode')->merge($kodeBiaya)->sort()->values()->all())
            ->and($akun->only($kodeBiaya)->every(fn (Akun $a): bool => ! $a->Sistem && $a->Jenis->value === 'Beban'))->toBeTrue()
            // 46 sejak v2.74 (PiutangBelumDifakturkan 1-1470, BR-12.2), 47 sejak v2.97 (PendapatanPengiriman 4-3100, F-17 bagian 3),
            // 49 sejak v3.42 (Giro Mundur Diterima 1-1480 & Hutang Giro 2-1180, F-12 giro), 50 sejak v3.61 (Kliring Tukar
            // Barang 2-1800, K-11), 58 sejak D-106 (delapan akun beban biaya umum 6-2100…6-2800), 59 sejak E8 (Beban Piutang Tak
            // Tertagih 6-5100).
            ->and($akun->count())->toBe(59)
            ->and($akun->has('4-1010'))->toBeTrue()
            ->and($akun->has('4-1020'))->toBeTrue();

        foreach ($akunTemplate as $baris) {
            $satu = $akun->get($baris['Kode']);
            expect($satu->Jenis->value)->toBe($baris['Tipe'])
                ->and($satu->SaldoNormal->value)->toBe($baris['SaldoNormal'])
                ->and($satu->Sistem)->toBeTrue()
                ->and($satu->Nama)->toBe($baris['Nama']);
        }

        $pemetaan = PemetaanAkun::query()->whereNull('IdOutlet')->with('Akun')->get()->keyBy('Kunci');
        foreach (PeranAkun::cases() as $peran) {
            expect($pemetaan->has($peran->value))->toBeTrue("Peran {$peran->value} belum dipetakan")
                ->and($pemetaan->get($peran->value)->Akun->Jenis)->toBe($peran->AmbilTipeAkun());
        }
        expect($pemetaan->count())->toBe(count(PeranAkun::cases()));
    });

    it('menyalin kategori, satuan standar aktif, kelompok pajak (jenis pajak, tanpa angka tarif), fitur outlet, pengaturan tenant, dan Tunai', function (): void {
        $versi = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        $hasil = BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        expect(Kategori::query()->orderBy('Urutan')->pluck('Nama')->all())->toBe($versi->Isi['Kategori'])
            ->and(Satuan::query()->pluck('KodeStandar')->sort()->values()->all())->toBe(collect($versi->Isi['KodeSatuan'])->sort()->values()->all())
            ->and(Satuan::query()->where('KodeStandar', 'KG')->sole()->BolehDesimal)->toBeTrue();

        $kelompok = KelompokPajak::query()->with('Detail.JenisPajak')->sole();
        expect($kelompok->Nama)->toBe('Makan & minum')
            ->and($kelompok->Detail->sole()->JenisPajak->Kode)->toBe('PbjtMakananMinuman')
            ->and($kelompok->Detail->sole()->DasarPengenaan->value)->toBe('SubtotalPlusLayanan')
            ->and($kelompok->Detail->sole()->IdTarifPajak)->toBeNull();

        expect(OutletFitur::query()->where('IdOutlet', $outlet->Id)->pluck('KunciFitur')->sort()->values()->all())
            ->toBe(collect($versi->Isi['KunciFitur'])->sort()->values()->all());

        $pengaturan = $tenant->fresh()?->Pengaturan ?? [];
        expect($pengaturan['PembulatanTunai'])->toEqual(['Kelipatan' => 100, 'Arah' => 'Bawah'])
            ->and($pengaturan['StokBolehMinus'])->toBeTrue()
            ->and($pengaturan['MetodeHpp'])->toBe('RataRata')
            ->and($pengaturan['Sektor'])->toBe(['FNB-CAF']);

        expect(MetodePembayaran::query()->where('Jenis', 'Tunai')->count())->toBe(1)
            ->and($hasil->jumlahAkun)->toBe(51)
            ->and($hasil->namaTemplate)->toBe('Kafe / kedai kopi');
    });

    it('BR-01.1 idempoten: diterapkan dua kali menghasilkan data yang sama dan tidak mencatat log penerapan kedua', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $jumlahPertama = HitungDataTemplateUji();
        $pengaturanPertama = $tenant->fresh()?->Pengaturan;

        $hasilKedua = BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'FNB-CAF');

        expect(HitungDataTemplateUji())->toBe($jumlahPertama)
            ->and($tenant->fresh()?->Pengaturan)->toBe($pengaturanPertama)
            ->and($hasilKedua->CekAdaPerubahan())->toBeFalse()
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'template-sektor.terapkan')->count())->toBe(1);
    });

    it('kirim ganda dengan instance Outlet basi memberi hasil yang sama (kunci baris Tenant)', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        $basi = Outlet::query()->findOrFail($outlet->Id);

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $jumlah = HitungDataTemplateUji();
        BantuanPanduanAwal::Terapkan($basi, 'FNB-CAF');

        expect(HitungDataTemplateUji())->toBe($jumlah);
    });

    it('BR-01.1 aditif: akun yang diganti nama & pengaturan yang diubah tenant tidak ditimpa; template kedua hanya menambah yang belum ada', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        Akun::query()->where('Kode', '1-1100')->update(['Nama' => 'Kas Laci Kasir Depan']);
        Tenant::query()->whereKey($tenant->Id)->update(['Pengaturan' => json_encode([...$tenant->fresh()?->Pengaturan ?? [], 'StokBolehMinus' => false])]);
        $kategoriSebelum = Kategori::query()->pluck('Nama')->all();
        $akunSebelum = Akun::query()->count();

        BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'RTL-GEN');

        $rtl = BantuanPanduanAwal::IsiTemplateAwal('RTL-GEN');
        $kategoriSesudah = Kategori::query()->pluck('Nama')->all();
        $kategoriBaru = array_values(array_filter($rtl['Kategori'], fn (string $nama) => ! in_array(mb_strtolower($nama), array_map('mb_strtolower', $kategoriSebelum), true)));

        expect(Akun::query()->where('Kode', '1-1100')->sole()->Nama)->toBe('Kas Laci Kasir Depan')
            ->and(($tenant->fresh()?->Pengaturan ?? [])['StokBolehMinus'])->toBeFalse()
            ->and(array_diff($kategoriSebelum, $kategoriSesudah))->toBe([])
            ->and(count($kategoriSesudah))->toBe(count($kategoriSebelum) + count($kategoriBaru))
            ->and(Kategori::query()->whereRaw('LOWER(Nama) = ?', ['minuman'])->count())->toBeLessThanOrEqual(1)
            ->and(KelompokPajak::query()->orderBy('Id')->pluck('Nama')->all())->toBe(['Makan & minum', 'Barang kena PPN'])
            ->and(Akun::query()->count())->toBeGreaterThanOrEqual($akunSebelum)
            ->and(($tenant->fresh()?->Pengaturan ?? [])['Sektor'])->toBe(['FNB-CAF', 'RTL-GEN']);
        expect(Outlet::query()->findOrFail($outlet->Id)->TemplateSektor)->toBe('RTL-GEN');
    });

    it('penjaga unik: Akun (IdTenant, Kode) ganda ditolak database', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        expect(fn () => Akun::query()->create(['Kode' => '1-1100', 'Nama' => 'Kas Ganda', 'Jenis' => 'Aset', 'SaldoNormal' => 'Debit']))
            ->toThrow(QueryException::class);
    });

    it('BR-P03.1: template & versi dicatat di outlet; versi terbit baru tidak mengubah tenant sampai diterapkan lagi', function (): void {
        $versi1 = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        $outlet->refresh();
        expect($outlet->TemplateSektor)->toBe('FNB-CAF')
            ->and($outlet->IdTemplateSektorVersi)->toBe($versi1->Id)
            ->and($outlet->TemplateSektorDiterapkanPada)->not->toBeNull();

        $isi = BantuanPanduanAwal::IsiTemplateAwal('FNB-CAF');
        $versi2 = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF', ['Kategori' => [...$isi['Kategori'], 'Kue & roti']]);

        expect($outlet->fresh()?->IdTemplateSektorVersi)->toBe($versi1->Id)
            ->and(Kategori::query()->where('Nama', 'Kue & roti')->exists())->toBeFalse();

        $hasil = BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'FNB-CAF');
        expect($outlet->fresh()?->IdTemplateSektorVersi)->toBe($versi2->Id)
            ->and($hasil->jumlahKategori)->toBe(1)
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.template.ubah')->count())->toBe(2);
    });

    it('hanya versi Terbit yang bisa diterapkan: Draf, Usang, atau kode tidak dikenal ditolak dengan galat KodeTemplate', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        $draf = TemplateSektor::query()->create(['Kode' => 'FNB-QSR', 'Nama' => 'Restoran cepat saji']);
        TemplateSektorVersi::query()->create(['IdTemplateSektor' => $draf->Id, 'Versi' => 1, 'Status' => StatusTemplateSektor::Draf, 'Isi' => BantuanPanduanAwal::IsiTemplateAwal('FNB-QSR')]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        foreach (['FNB-QSR', 'TIDAK-ADA'] as $kode) {
            BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => $kode])
                ->assertSessionHasErrors(['KodeTemplate' => 'Template ini belum tersedia. Pilih template lain.']);
        }

        expect(fn () => BantuanPanduanAwal::Terapkan($outlet, 'FNB-QSR'))->toThrow(PelanggaranAturanBisnis::class);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Akun::query()->count())->toBe(0);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'RTL-GEN', 'SektorLain' => ['TIDAK-ADA']])
            ->assertSessionHasErrors('SektorLain.0');
    });

    it('lewat HTTP: menerapkan template, mencatat sektor tambahan, lalu ke langkah pajak dengan pesan ringkasan', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF', 'SektorLain' => ['RTL-GEN']])
            ->assertRedirect('/kelola/panduan-awal/pajak')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Template Kafe / kedai kopi versi 1 diterapkan: 51 akun, 5 kategori, 7 satuan, 1 kelompok pajak ditambahkan.');

        expect(($tenant->fresh()?->Pengaturan ?? [])['Sektor'])->toBe(['FNB-CAF', 'RTL-GEN']);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF'])
            ->assertSessionHas('Kilat', 'Template sudah diterapkan, tidak ada data baru.');
    });

    it('audit #23: retail bukan PKP tanpa pajak daerah melewati langkah Pajak otomatis; F&B tetap ke langkah Pajak', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'RTL-GEN'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/panduan-awal/produk');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $progres = ProgresPanduanAwal::query()->sole();
        expect($progres->AmbilLangkahWajibBelumSelesai())->not->toContain(LangkahPanduan::Pajak);
    });

    it('§25 no. 16a: kunci lama PiutangSettlement/Waste di versi terbit dibaca sebagai PiutangPencairan/SusutPersediaan', function (): void {
        $isi = BantuanPanduanAwal::IsiTemplateAwal('FNB-CAF');
        $pemetaan = $isi['PemetaanAkun'];
        $pemetaan['PiutangSettlement'] = $pemetaan['PiutangPencairan'];
        $pemetaan['Waste'] = $pemetaan['SusutPersediaan'];
        unset($pemetaan['PiutangPencairan'], $pemetaan['SusutPersediaan']);
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF', ['PemetaanAkun' => $pemetaan]);
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        $kunci = PemetaanAkun::query()->pluck('Kunci')->all();
        expect($kunci)->toContain('PiutangPencairan')
            ->and($kunci)->toContain('SusutPersediaan')
            ->and($kunci)->not->toContain('PiutangSettlement')
            ->and($kunci)->not->toContain('Waste')
            ->and(count($kunci))->toBe(count(PeranAkun::cases()));
    });
});

describe('v3.80 template sektor tambahan', function (): void {
    it('setiap template baru bisa diterapkan: mode kasir, kategori, satuan, dan semua peran akun terpetakan', function (string $kode, string $modeBawaan, string $kategori): void {
        BantuanPanduanAwal::TerbitkanTemplate($kode);
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant("Usaha Uji {$kode}");

        BantuanPanduanAwal::Terapkan($outlet, $kode);

        $pos = OutletFitur::query()->where('IdOutlet', $outlet->Id)->where('KunciFitur', 'pos.retail')->sole();
        expect($pos->Konfigurasi['ModeKasirDefault'] ?? null)->toBe($modeBawaan)
            ->and(Kategori::query()->where('Nama', $kategori)->exists())->toBeTrue()
            ->and(Satuan::query()->count())->toBe(count(BantuanPanduanAwal::IsiTemplateAwal($kode)['KodeSatuan']));

        $pemetaan = PemetaanAkun::query()->whereNull('IdOutlet')->pluck('Kunci')->all();
        foreach (PeranAkun::cases() as $peran) {
            expect($pemetaan)->toContain($peran->value);
        }
    })->with([
        ['FNB-RST', 'Meja', 'Makanan utama'],
        ['FNB-BAK', 'Retail', 'Kue ulang tahun'],
        ['RTL-FSH', 'Retail', 'Hijab & kerudung'],
        ['RTL-ELC', 'Retail', 'Jasa servis'],
        ['RTL-PHR', 'Retail', 'Obat keras'],
        ['RTL-BLD', 'Retail', 'Besi & baja'],
        ['WHS-DST', 'Grosir', 'Sembako'],
        ['SVC-SLN', 'Layanan', 'Perawatan rambut'],
        ['SVC-LDR', 'Layanan', 'Kiloan'],
        ['SVC-GEN', 'Layanan', 'Fotokopi & cetak'],
        // K25: Bengkel (jasa servis + sparepart).
        ['SVC-WRK', 'Layanan', 'Jasa Servis'],
    ]);
});
