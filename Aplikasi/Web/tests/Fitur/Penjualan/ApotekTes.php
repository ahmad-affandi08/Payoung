<?php

declare(strict_types=1);

use App\Domain\Katalog\Data\KonteksKatalogPos;
use App\Domain\Katalog\Enum\GolonganObat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\ProdukUntukPos;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\PanduanAwal\Aksi\TambahkanProdukContoh;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ResepPenjualan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Sektor Apotek bagian 1 (PRD §9.5, §19 "Apoteker", K-26): golongan obat di produk, resep & apoteker di Penjualan.Buat
 * (pelanggaran = diterima + tinjauan, karena penjualan bisa offline), laporan obat wajib resep, dan data pendukung SIPNAP.
 * Dasar: PMK 73/2016 (pelayanan resep, OWA), UU 35/2009 & PMK 3/2015 (psikotropika/narkotika).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Obat ber-batch dengan stok awal satu batch (kedaluwarsa 1 tahun lagi). */
function BuatObatUji(array $k, string $nama, GolonganObat $golongan, bool $owa = false, string $batch = 'BT-2601', string $harga = '12000.00'): Produk
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $produk = BantuanKatalog::BuatProduk([
        'Nama' => $nama,
        'Pelacakan' => PelacakanProduk::Batch,
        'GolonganObat' => $golongan,
        'ObatWajibApotek' => $owa,
    ], $harga);
    BantuanStokAwal::BuatDanPosting($k['Gudang'], [BantuanStokAwal::Baris($produk, '100', '8000', $batch, CarbonImmutable::now()->addYear()->toDateString())], $k['Pemilik']->Id);

    return $produk;
}

/** @return array<string, mixed> */
function ResepUji(array $timpa = []): array
{
    return array_replace([
        'NomorResep' => 'R/2026/10/0042',
        'TanggalResep' => CarbonImmutable::now()->toDateString(),
        'NamaDokter' => 'dr. Siti Rahmawati, Sp.A',
        'NoSipDokter' => '503/SIP/2025/0117',
        'NamaPasien' => 'Budi Santoso Wibowo',
        'UmurPasien' => '34 tahun',
        'AlamatPasien' => 'Jl. Slamet Riyadi No. 120, Laweyan, Surakarta',
    ], $timpa);
}

/**
 * @param  array<string, mixed>  $opsi
 * @param  array<string, mixed>  $timpa
 */
function JualObatUji(object $tes, array $k, array $opsi, array $timpa = []): Penjualan
{
    $item = BantuanPenjualan::Item($k, $opsi, $timpa);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
}

describe('Produk obat: golongan, OWA, prekursor (form produk)', function (): void {
    it('obat bergolongan wajib Batch & kedaluwarsa (termasuk obat bebas), dengan Batch tersimpan; OWA hanya untuk obat keras', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Apotek Sehat Sentosa Solo');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $isi = fn (array $timpa): array => BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], array_replace([
            'Nama' => 'Amoxicillin 500 mg Kapsul',
            'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '1200']], defaultJual: true)],
        ], $timpa));

        $masuk()->post('/kelola/produk', $isi(['GolonganObat' => 'Keras', 'Pelacakan' => 'Tidak']))->assertSessionHasErrors('Pelacakan');
        $masuk()->post('/kelola/produk', $isi(['Nama' => 'Paracetamol 500 mg', 'GolonganObat' => 'Bebas', 'Pelacakan' => 'Tidak']))->assertSessionHasErrors('Pelacakan');

        $keras = $isi(['GolonganObat' => 'Keras', 'ObatWajibApotek' => true, 'Prekursor' => true, 'Pelacakan' => 'Batch']);
        $masuk()->post('/kelola/produk', $keras)->assertSessionHasNoErrors();
        $bebas = $isi(['Nama' => 'Paracetamol 500 mg Tablet', 'GolonganObat' => 'Bebas', 'ObatWajibApotek' => true, 'Pelacakan' => 'Batch']);
        $masuk()->post('/kelola/produk', $bebas)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $p = Produk::query()->where('Uuid', $keras['Uuid'])->sole();
        $b = Produk::query()->where('Uuid', $bebas['Uuid'])->sole();
        expect($p->GolonganObat)->toBe(GolonganObat::Keras)
            ->and($p->ObatWajibApotek)->toBeTrue()
            ->and($p->Prekursor)->toBeTrue()
            ->and($p->CekWajibResep())->toBeFalse()
            ->and($b->ObatWajibApotek)->toBeFalse()
            ->and($b->CekWajibResep())->toBeFalse();

        // Klien lama tanpa bidang GolonganObat tidak menghapus isian obat; mengosongkan = bukan obat lagi.
        $tanpa = $keras;
        unset($tanpa['GolonganObat'], $tanpa['ObatWajibApotek'], $tanpa['Prekursor']);
        $masuk()->put("/kelola/produk/{$keras['Uuid']}", $tanpa)->assertSessionHasNoErrors();
        expect($p->refresh()->GolonganObat)->toBe(GolonganObat::Keras);
        $masuk()->put("/kelola/produk/{$keras['Uuid']}", [...$keras, 'GolonganObat' => null])->assertSessionHasNoErrors();
        expect($p->refresh()->GolonganObat)->toBeNull()->and($p->ObatWajibApotek)->toBeFalse()->and($p->Prekursor)->toBeFalse();
    });

    it('jenis tanpa stok (Jasa) tidak boleh bergolongan obat. Golongan tidak dikenal ditolak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Apotek Sehat Sentosa Solo');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Tuslah racik', 'Jenis' => 'Jasa', 'GolonganObat' => 'Keras']);

        $masuk()->post('/kelola/produk', $form)->assertSessionHasErrors('GolonganObat');
        $masuk()->post('/kelola/produk', [...$form, 'Jenis' => 'Stok', 'Pelacakan' => 'Batch', 'GolonganObat' => 'Jamu'])->assertSessionHasErrors('GolonganObat');
    });

    it('katalog POS membawa GolonganObat, ObatWajibApotek, Prekursor, dan WajibResep (aditif)', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Apotek Sehat Sentosa Solo');
        $amox = BantuanKatalog::BuatProduk(['Nama' => 'Amoxicillin 500 mg', 'Pelacakan' => 'Batch', 'GolonganObat' => GolonganObat::Keras], null, $t['Pcs']);
        $mef = BantuanKatalog::BuatProduk(['Nama' => 'Asam Mefenamat 500 mg', 'Pelacakan' => 'Batch', 'GolonganObat' => GolonganObat::Keras, 'ObatWajibApotek' => true], null, $t['Pcs']);
        $diaz = BantuanKatalog::BuatProduk(['Nama' => 'Diazepam 2 mg', 'Pelacakan' => 'Batch', 'GolonganObat' => GolonganObat::Psikotropika, 'Prekursor' => false], null, $t['Pcs']);
        $sabun = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Antiseptik 100 g'], null, $t['Pcs']);

        $produk = collect(app(ProdukUntukPos::class)->AmbilBagian(new KonteksKatalogPos($t['Tenant']->Id, $t['Outlet']->Id, null))['Produk'])->keyBy('Uuid');

        expect($produk[$amox->Uuid])->toMatchArray(['GolonganObat' => 'Keras', 'ObatWajibApotek' => false, 'Prekursor' => false, 'WajibResep' => true])
            ->and($produk[$mef->Uuid])->toMatchArray(['GolonganObat' => 'Keras', 'ObatWajibApotek' => true, 'WajibResep' => false])
            ->and($produk[$diaz->Uuid])->toMatchArray(['GolonganObat' => 'Psikotropika', 'WajibResep' => true])
            ->and($produk[$sabun->Uuid])->toMatchArray(['GolonganObat' => null, 'ObatWajibApotek' => false, 'WajibResep' => false]);
    });
});

describe('Peran Apoteker & izin apotek (§19)', function (): void {
    it('Apoteker bawaan: jual + obat keras + resep + laporan penjualan; Kasir (asisten apoteker) tanpa izin apotek', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Apotek Sehat Sentosa Solo');
        $apoteker = collect(PeranTenantBawaan::Apoteker->AmbilIzin())->map->value->all();
        $kasir = collect(PeranTenantBawaan::Kasir->AmbilIzin())->map->value->all();

        expect($apoteker)->toEqualCanonicalizing([
            IzinTenant::ProdukLihat->value, IzinTenant::PenjualanBuat->value, IzinTenant::PelangganLihat->value,
            IzinTenant::LaporanPenjualanLihat->value, IzinTenant::ApotekObatKerasJual->value, IzinTenant::ApotekResepLihat->value,
        ])
            ->and($kasir)->not->toContain(IzinTenant::ApotekObatKerasJual->value)
            ->and(BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::Apoteker)->Nama)->toBe('Apoteker');
    });
});

describe('Penjualan.Buat dengan resep (BR apotek, diterima + tinjauan)', function (): void {
    it('apoteker menjual obat keras dengan resep: diterima tanpa tinjauan, resep tersimpan, data pasien terenkripsi, snapshot golongan', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras);

        $p = JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => [['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00']]], ['Resep' => ResepUji()]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();
        $r = ResepPenjualan::query()->where('IdPenjualan', $p->Id)->sole();
        $mentah = DB::table('ResepPenjualan')->where('Id', $r->Id)->first();

        expect($p->PerluTinjauan)->toBeFalse()
            ->and($p->IdApoteker)->toBe($apoteker->Id)
            ->and($d->GolonganObat)->toBe('Keras')
            ->and($d->DenganResep)->toBeTrue()
            ->and($r->NomorResep)->toBe('R/2026/10/0042')
            ->and($r->NamaPasien)->toBe('Budi Santoso Wibowo')
            ->and($r->IdApoteker)->toBe($apoteker->Id)
            ->and((string) $mentah->NamaPasien)->not->toContain('Budi')
            ->and(Crypt::decryptString((string) $mentah->AlamatPasien))->toContain('Slamet Riyadi')
            ->and($r->toArray())->not->toHaveKey('NamaPasien')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('obat keras tanpa resep: diterima + ResepTidakLengkap (stok tetap berkurang)', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras);

        $p = JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => [['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00']]]);

        expect($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('ResepTidakLengkap')->toContain('Amoxicillin')
            ->and($p->AlasanTinjauan)->not->toContain('ApotekerTidakBerwenang')
            ->and(ResepPenjualan::query()->count())->toBe(0)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('kasir biasa (asisten) menjual obat keras: ApotekerTidakBerwenang, dengan UuidApoteker berizin = sah dan tercatat', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras);
        $baris = ['Baris' => [['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00']]];

        $tanpa = JualObatUji($this, $k, $baris, ['Resep' => ResepUji()]);
        // Supervisor (tanpa izin apoteker) yang dikirim sebagai apoteker tidak dianggap apoteker.
        $salah = JualObatUji($this, $k, $baris, ['Resep' => ResepUji(['UuidApoteker' => $k['Supervisor']->Uuid])]);
        $sah = JualObatUji($this, $k, $baris, ['Resep' => ResepUji(), 'UuidApoteker' => $apoteker->Uuid]);

        expect($tanpa->AlasanTinjauan)->toContain('ApotekerTidakBerwenang')
            ->and($tanpa->IdApoteker)->toBeNull()
            ->and(ResepPenjualan::query()->where('IdPenjualan', $tanpa->Id)->exists())->toBeTrue()
            ->and($salah->AlasanTinjauan)->toContain('ApotekerTidakBerwenang')
            ->and($sah->PerluTinjauan)->toBeFalse()
            ->and($sah->IdApoteker)->toBe($apoteker->Id);
    });

    it('Obat Wajib Apotek: apoteker tanpa resep = sah (tercatat apotekernya). Kasir biasa = ApotekerTidakBerwenang', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $mef = BuatObatUji($k, 'Asam Mefenamat 500 mg Tablet', GolonganObat::Keras, owa: true);
        $baris = [['Produk' => $mef, 'Jumlah' => '10', 'Harga' => '800.00']];

        $owa = JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => $baris]);
        $kasir = JualObatUji($this, $k, ['Baris' => $baris]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $owa->Id)->sole();

        expect($owa->PerluTinjauan)->toBeFalse()
            ->and($owa->IdApoteker)->toBe($apoteker->Id)
            ->and($d->ObatWajibApotek)->toBeTrue()
            ->and($d->DenganResep)->toBeFalse()
            ->and($kasir->AlasanTinjauan)->toContain('ApotekerTidakBerwenang')->not->toContain('ResepTidakLengkap');
    });

    it('obat bebas oleh kasir biasa tanpa resep: tanpa tinjauan, tanpa apoteker', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $para = BuatObatUji($k, 'Paracetamol 500 mg Tablet', GolonganObat::Bebas, harga: '600.00');

        $p = JualObatUji($this, $k, ['Baris' => [['Produk' => $para, 'Jumlah' => '10', 'Harga' => '600.00']]]);

        expect($p->PerluTinjauan)->toBeFalse()->and($p->IdApoteker)->toBeNull();
    });

    it('psikotropika: resep tanpa alamat pasien = ResepTidakLengkap. Tanda DenganResep per baris dihormati', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $diaz = BuatObatUji($k, 'Diazepam 2 mg Tablet', GolonganObat::Psikotropika, batch: 'DZ-01');
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras);

        $tanpaAlamat = JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => [['Produk' => $diaz, 'Jumlah' => '5', 'Harga' => '2500.00']]], ['Resep' => ResepUji(['AlamatPasien' => null])]);
        $sebagian = JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => [
            ['Produk' => $diaz, 'Jumlah' => '5', 'Harga' => '2500.00', 'DenganResep' => true],
            ['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00', 'DenganResep' => false],
        ]], ['Resep' => ResepUji()]);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $sebagian->Id)->orderBy('Urutan')->pluck('DenganResep')->all();

        expect($tanpaAlamat->AlasanTinjauan)->toContain('ResepTidakLengkap')->toContain('alamat pasien')
            ->and($sebagian->AlasanTinjauan)->toContain('ResepTidakLengkap')->toContain('Amoxicillin')->not->toContain('Diazepam')
            ->and($detail)->toBe([true, false]);
    });

    it('blok Resep tanpa isian wajib ditolak DataTidakValid. Kiriman ganda = Duplikat dengan satu resep (idempoten)', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras);
        $opsi = ['Kasir' => $apoteker, 'Baris' => [['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00']]];

        $rusak = BantuanPenjualan::Item($k, $opsi, ['Resep' => ResepUji(['NamaDokter' => ''])]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$rusak])[0][0])->toBe('Ditolak');

        $item = BantuanPenjualan::Item($k, $opsi, ['Resep' => ResepUji()]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ResepPenjualan::query()->count())->toBe(1)->and(Penjualan::query()->count())->toBe(1);
    });
});

/** Stok tersedia produk di lokasi Toko uji. */
function StokObatUji(array $k, Produk $produk): string
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return (string) SaldoStok::query()->where('IdProduk', $produk->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
}

/**
 * Baris jasa racik + blok Racikan (komponen untuk satu racikan).
 *
 * @param  list<array{0: Produk, 1: string}>  $komponen
 * @return array<string, mixed>
 */
function BarisRacikanUji(Produk $jasaRacik, array $komponen, array $timpa = []): array
{
    return [
        'Produk' => $jasaRacik,
        'Jumlah' => '1',
        'Harga' => '45000.00',
        'Racikan' => array_replace([
            'Nama' => 'Puyer batuk pilek anak',
            'JumlahKemasan' => 10,
            'AturanPakai' => '3 x 1 bungkus sesudah makan',
            'Komponen' => array_map(fn (array $c): array => ['UuidProduk' => $c[0]->Uuid, 'Jumlah' => $c[1]], $komponen),
        ], $timpa),
    ];
}

describe('Racikan apotek (bagian 3): resep racik sebagai resep sementara pada baris jasa racik', function (): void {
    it('apoteker + resep: komponen berkurang FEFO dari batch, HPP baris = Σ komponen, golongan terkuat & DenganResep, snapshot komposisi. Kirim ulang Duplikat. Void mengembalikan stok. Retur ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $ctm = BuatObatUji($k, 'CTM Chlorpheniramine 4 mg Tablet', GolonganObat::BebasTerbatas);
        $dexa = BuatObatUji($k, 'Dexamethasone 0,5 mg Tablet', GolonganObat::Keras);
        // Paracetamol dua batch: yang kedaluwarsa lebih dulu (3 tablet) diambil lebih dulu (FEFO).
        $para = BantuanKatalog::BuatProduk(['Nama' => 'Paracetamol 500 mg Tablet', 'Pelacakan' => PelacakanProduk::Batch, 'GolonganObat' => GolonganObat::Bebas], '500.00');
        BantuanStokAwal::BuatDanPosting($k['Gudang'], [
            BantuanStokAwal::Baris($para, '100', '8000', 'BT-2601', CarbonImmutable::now()->addYear()->toDateString()),
            BantuanStokAwal::Baris($para, '3', '7000', 'BT-2512', CarbonImmutable::now()->addMonths(2)->toDateString()),
        ], $k['Pemilik']->Id);
        $racik = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Racik Puyer (per resep)', 'Jenis' => JenisProduk::Jasa], '15000.00');

        $item = BantuanPenjualan::Item($k, ['Kasir' => $apoteker, 'Baris' => [BarisRacikanUji($racik, [[$para, '5'], [$ctm, '2'], [$dexa, '2']])]], ['Resep' => ResepUji()]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect($p->PerluTinjauan)->toBeFalse()
            ->and($p->IdApoteker)->toBe($apoteker->Id)
            ->and($d->GolonganObat)->toBe('Keras')
            ->and($d->ObatWajibApotek)->toBeFalse()
            ->and($d->DenganResep)->toBeTrue()
            ->and($d->Racikan['Nama'])->toBe('Puyer batuk pilek anak')
            ->and($d->Racikan['JumlahKemasan'])->toBe(10)
            ->and(array_column($d->Racikan['Komponen'], 'JumlahDasar'))->toBe(['5.0000', '2.0000', '2.0000'])
            ->and(array_column($d->Racikan['Komponen'], 'GolonganObat'))->toBe(['Bebas', 'BebasTerbatas', 'Keras'])
            // HPP rata-rata bergerak (batch hanya membagi jumlah): paracetamol (100 × 8.000 + 3 × 7.000) ÷ 103 × 5
            // = 39.854,37, ditambah CTM 2 × 8.000 dan dexamethasone 2 × 8.000.
            ->and($d->TotalHpp)->toBe('71854.37')
            ->and(BatchStok::query()->where('IdProduk', $para->Id)->orderBy('NomorBatch')->pluck('JumlahSisa', 'NomorBatch')->all())->toBe(['BT-2512' => '0.0000', 'BT-2601' => '98.0000'])
            ->and(StokObatUji($k, $para))->toBe('98.0000')
            ->and(StokObatUji($k, $ctm))->toBe('98.0000')
            ->and(StokObatUji($k, $dexa))->toBe('98.0000')
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdProduk', $para->Id)->count())->toBe(2)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        // Back-office: komposisi racikan tampil di rincian penjualan.
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get("/kelola/penjualan/{$p->Uuid}")->assertOk()
            ->assertInertia(fn ($h) => $h->where('Baris.0.Racikan.Nama', 'Puyer batuk pilek anak')
                ->where('Baris.0.Racikan.Komponen.2.NamaProduk', 'Dexamethasone 0,5 mg Tablet')
                ->where('Baris.0.Racikan.Komponen.2.Jumlah', '2.0000'));

        // Obat racikan tidak bisa diretur: pencarian struk untuk retur memberi sisa 0 dan penanda Racikan.
        $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))->assertOk()
            ->assertJsonPath('Baris.0.Racikan', true)
            ->assertJsonPath('Baris.0.JumlahBisaDiretur', '0.0000')
            ->assertJsonPath('Baris.0.NilaiBisaDiretur', '0.00');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']])]))
            ->toBe([['Ditolak', 'ReturRacikanTidakDidukung']]);

        // Void mengembalikan semua komponen ke batch asalnya.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);
        expect(StokObatUji($k, $para))->toBe('103.0000')
            ->and(StokObatUji($k, $dexa))->toBe('100.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('racikan berisi obat keras oleh kasir biasa tanpa resep: diterima + ResepTidakLengkap & ApotekerTidakBerwenang atas nama racikannya. Racikan obat bebas tanpa tinjauan', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $para = BuatObatUji($k, 'Paracetamol 500 mg Tablet', GolonganObat::Bebas);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras, owa: true);
        $racik = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Racik Kapsul', 'Jenis' => JenisProduk::Jasa], '20000.00');

        // Komponen OWA pun tetap wajib resep di dalam racikan (racikan berasal dari resep dokter).
        $keras = JualObatUji($this, $k, ['Baris' => [BarisRacikanUji($racik, [[$para, '2'], [$amox, '3']], ['Nama' => 'Kapsul racik anak'])]]);
        $bebas = JualObatUji($this, $k, ['Baris' => [BarisRacikanUji($racik, [[$para, '2']], ['Nama' => 'Puyer demam'])]]);

        expect($keras->PerluTinjauan)->toBeTrue()
            ->and($keras->AlasanTinjauan)->toContain('ResepTidakLengkap')->toContain('Racikan Kapsul racik anak')
            ->and($keras->AlasanTinjauan)->toContain('ApotekerTidakBerwenang')
            ->and(StokObatUji($k, $amox))->toBe('97.0000')
            ->and($bebas->PerluTinjauan)->toBeFalse()
            ->and(PenjualanDetail::query()->where('IdPenjualan', $bebas->Id)->sole()->GolonganObat)->toBe('Bebas');
    });

    it('racikan tidak valid ditolak: bukan jasa racik, komponen tanpa stok/seri/ganda/tidak dikenal, kemasan 0, tanpa komponen', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $para = BuatObatUji($k, 'Paracetamol 500 mg Tablet', GolonganObat::Bebas);
        $racik = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Racik Puyer', 'Jenis' => JenisProduk::Jasa], '15000.00');
        $tuslah = BantuanKatalog::BuatProduk(['Nama' => 'Tuslah Resep', 'Jenis' => JenisProduk::Jasa], '3000.00');
        $termometer = BantuanKatalog::BuatProduk(['Nama' => 'Termometer Digital', 'Pelacakan' => PelacakanProduk::Seri], '45000.00');
        $kirim = fn (array $baris): array => BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::Item($k, ['Baris' => [$baris]])]);

        expect($kirim(BarisRacikanUji($para, [[$para, '1']])))->toBe([['Ditolak', 'RacikanBukanJasa']])
            ->and($kirim(BarisRacikanUji($racik, [[$tuslah, '1']])))->toBe([['Ditolak', 'KomponenRacikanTidakValid']])
            ->and($kirim(BarisRacikanUji($racik, [[$termometer, '1']])))->toBe([['Ditolak', 'PelacakanBelumDidukung']])
            ->and($kirim(BarisRacikanUji($racik, [[$para, '1'], [$para, '2']])))->toBe([['Ditolak', 'KomponenRacikanGanda']])
            ->and($kirim(BarisRacikanUji($racik, [[$para, '1']], ['JumlahKemasan' => 0])))->toBe([['Ditolak', 'DataTidakValid']])
            ->and($kirim(BarisRacikanUji($racik, [], ['Komponen' => []])))->toBe([['Ditolak', 'DataTidakValid']])
            ->and($kirim(BarisRacikanUji($racik, [], ['Komponen' => [['UuidProduk' => (string) Str::ulid(), 'Jumlah' => '1']]])))->toBe([['Ditolak', 'ProdukTidakDikenal']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->count())->toBe(0)->and(StokObatUji($k, $para))->toBe('100.0000');
    });
});

describe('Laporan apotek: obat wajib resep & data pendukung SIPNAP', function (): void {
    it('daftar obat wajib resep: batch & resep tampil. Data pasien utuh hanya untuk apotek.resep.lihat. Ekspor CSV. Isolasi tenant. Kasir ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        $apoteker = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Apoteker);
        $amox = BuatObatUji($k, 'Amoxicillin 500 mg Kapsul', GolonganObat::Keras, batch: 'AMX-2601');
        $para = BuatObatUji($k, 'Paracetamol 500 mg Tablet', GolonganObat::Bebas, harga: '600.00');
        JualObatUji($this, $k, ['Kasir' => $apoteker, 'Baris' => [
            ['Produk' => $amox, 'Jumlah' => '10', 'Harga' => '1200.00'],
            ['Produk' => $para, 'Jumlah' => '10', 'Harga' => '600.00'],
        ]], ['Resep' => ResepUji()]);

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $pemilik = $this->getJson('/kelola/laporan/apotek')->assertOk()->json('Data');
        expect($pemilik)->toHaveCount(1)
            ->and($pemilik[0])->toMatchArray([
                'NamaProduk' => 'Amoxicillin 500 mg Kapsul', 'Golongan' => 'Keras', 'Jumlah' => '10.0000', 'Batch' => 'AMX-2601',
                'NomorResep' => 'R/2026/10/0042', 'NamaPasien' => 'Budi Santoso Wibowo', 'NamaApoteker' => $apoteker->Nama, 'PasienTersamar' => false,
            ]);

        $csv = $this->get('/kelola/laporan/apotek/ekspor')->assertOk()->streamedContent();
        expect($csv)->toContain('Nomor resep')->toContain('AMX-2601')->toContain('Budi Santoso Wibowo');

        $akuntan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::Masuk($this, $akuntan, $k['Tenant']->Id);
        $samar = $this->getJson('/kelola/laporan/apotek')->assertOk()->json('Data.0');
        expect($samar['NamaPasien'])->toBe('B*** S*** W***')
            ->and($samar['AlamatPasien'])->toBeNull()
            ->and($samar['PasienTersamar'])->toBeTrue()
            ->and($this->get('/kelola/laporan/apotek/ekspor')->streamedContent())->not->toContain('Budi');

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->getJson('/kelola/laporan/apotek')->assertForbidden();

        $lain = BantuanPenjualan::Siapkan($this, 'Apotek Lain Kartasura');
        BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id);
        expect($this->getJson('/kelola/laporan/apotek')->assertOk()->json('Meta.Total'))->toBe(0);
    });

    it('data pendukung SIPNAP per bulan dari MutasiStok: stok awal + masuk − keluar = akhir; hanya psikotropika & narkotika; CSV', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Apotek Sehat Sentosa Solo');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Ledger langsung lewat CatatMutasiStok (tanpa batch) supaya angka per jenis mutasi bisa dipilih persis.
        $diaz = BantuanKatalog::BuatProduk(['Nama' => 'Diazepam 2 mg Tablet', 'GolonganObat' => GolonganObat::Psikotropika, 'Prekursor' => true], '2500.00');
        $amox = BantuanKatalog::BuatProduk(['Nama' => 'Amoxicillin 500 mg Kapsul', 'GolonganObat' => GolonganObat::Keras], '1200.00');
        $g = $k['Gudang']->Id;
        $catat = fn (Produk $p, string $jumlah, ?string $nilai, JenisMutasi $jenis, JenisReferensiMutasi $ref, string $tanggal) => BantuanBuku::Catat(
            [BantuanBuku::BuatBaris('B/1', $p->Id, $g, $jumlah, $nilai, $jenis)],
            $ref,
            null,
            $tanggal,
        );
        $catat($diaz, '100', '200000', JenisMutasi::StokAwal, JenisReferensiMutasi::StokAwal, '2026-08-10');
        $catat($amox, '50', '400000', JenisMutasi::StokAwal, JenisReferensiMutasi::StokAwal, '2026-08-10');
        $catat($diaz, '50', '100000', JenisMutasi::PenerimaanPembelian, JenisReferensiMutasi::PenerimaanBarang, '2026-09-05');
        $catat($diaz, '-30', null, JenisMutasi::Penjualan, JenisReferensiMutasi::Penjualan, '2026-09-10');
        $catat($diaz, '2', '4000', JenisMutasi::ReturPenjualan, JenisReferensiMutasi::ReturPenjualan, '2026-09-12');
        $catat($diaz, '-5', null, JenisMutasi::Susut, JenisReferensiMutasi::BahanTerbuang, '2026-09-15');
        $catat($diaz, '3', '6000', JenisMutasi::OpnameLebih, JenisReferensiMutasi::StokOpname, '2026-09-20');
        $catat($diaz, '-7', null, JenisMutasi::Penjualan, JenisReferensiMutasi::Penjualan, '2026-10-01');

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get('/kelola/laporan/apotek?tab=sipnap&bulan=2026-09')->assertOk()->assertInertia(fn ($h) => $h
            ->component('Kelola/Laporan/Apotek')
            ->where('Sipnap.Bulan', '2026-09')
            ->has('Sipnap.Baris', 1)
            ->where('Sipnap.Baris.0', fn ($b): bool => $b['NamaProduk'] === 'Diazepam 2 mg Tablet'
                && $b['Golongan'] === 'Psikotropika' && $b['Prekursor'] === true
                && $b['StokAwal'] === '100.0000' && $b['PemasukanPemasok'] === '50.0000' && $b['PemasukanLain'] === '3.0000'
                && $b['PengeluaranPenjualan'] === '28.0000' && $b['PengeluaranLain'] === '5.0000' && $b['StokAkhir'] === '120.0000'));

        $csv = $this->get('/kelola/laporan/apotek/sipnap/ekspor?bulan=2026-09')->assertOk()->streamedContent();
        expect($csv)->toContain('"Stok awal","Pemasukan dari pemasok"')->toContain('2026-09')->toContain('120.0000')->not->toContain('Amoxicillin');

        // Bulan Oktober: stok awal = akhir September.
        $this->get('/kelola/laporan/apotek?tab=sipnap&bulan=2026-10')->assertInertia(fn ($h) => $h
            ->where('Sipnap.Baris.0.StokAwal', '120.0000')->where('Sipnap.Baris.0.StokAkhir', '113.0000'));
    });
});

describe('Template sektor Apotek RTL-PHR', function (): void {
    it('produk contoh obat dibuat bergolongan & berpelacakan Batch. Embalase/tuslah sebagai jasa', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('RTL-PHR');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant('Apotek Sehat Sentosa Solo');
        BantuanPanduanAwal::Terapkan($outlet, 'RTL-PHR');
        BantuanOrganisasi::AturKonteks($outlet->IdTenant);

        app(TambahkanProdukContoh::class)->Jalankan($outlet->refresh(), [
            ['Nama' => 'Amoxicillin 500 mg Kapsul (strip isi 10)', 'Harga' => '12000'],
            ['Nama' => 'Embalase', 'Harga' => '1000'],
        ]);

        $amox = Produk::query()->where('Nama', 'Amoxicillin 500 mg Kapsul (strip isi 10)')->sole();
        $embalase = Produk::query()->where('Nama', 'Embalase')->sole();
        expect($amox->GolonganObat)->toBe(GolonganObat::Keras)
            ->and($amox->Pelacakan)->toBe(PelacakanProduk::Batch)
            ->and($embalase->Jenis)->toBe(JenisProduk::Jasa)
            ->and($embalase->GolonganObat)->toBeNull();
    });
});
