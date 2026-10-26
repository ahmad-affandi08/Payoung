<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\PaketSesi;
use App\Domain\Katalog\Model\PaketSesiProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Aksi\HanguskanSesiKedaluwarsa;
use App\Domain\Pelanggan\Enum\JenisMutasiSesi;
use App\Domain\Pelanggan\Enum\StatusPemakaianSesi;
use App\Domain\Pelanggan\Enum\StatusSaldoSesi;
use App\Domain\Pelanggan\Model\MutasiSesi;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PemakaianSesi;
use App\Domain\Pelanggan\Model\SaldoSesi;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16d bagian 2 (PRD "Rincian F-16d", CRM-04, J-16.2/J-16.3): paket sesi. Paket = produk Jasa dengan definisi
 * `PaketSesi`; dijual di kasir (wajib pelanggan, jumlah bulat) → `SaldoSesi` + Cr Pendapatan Diterima Dimuka sebesar
 * nilai bersih baris; dipakai lewat outbox `Sesi.Pakai` (offline, idempoten) → Dr Pendapatan Diterima Dimuka, Cr
 * Pendapatan Jasa per sesi (sesi terakhir mengakui sisa); void membatalkan sisa & membalik pengakuan; baris paket tidak
 * bisa diretur; sisa dikembalikan/dihanguskan di back-office; hangus otomatis saat masa berlaku lewat. Invarian:
 * −saldo akun Pendapatan Diterima Dimuka = Σ NilaiTersisa = Σ MutasiSesi.Nilai, SisaSesi = Σ MutasiSesi.JumlahSesi.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Salon: paket "Creambath 10x" (Jasa, Rp 1.000.000, 10 sesi, berlaku 90 hari) untuk layanan Creambath & Hair Mask; Ani.
 *
 * @return array<string, mixed>
 */
function SiapkanPaketSesi(TestCase $tes, string $namaUsaha = 'Salon Cantik Sesi Solo', int $jumlahSesi = 10, ?int $masaBerlaku = 90): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $paket = BantuanKatalog::BuatProduk(['Nama' => "Paket Creambath Rambut Panjang {$jumlahSesi}x Sesi", 'Jenis' => JenisProduk::Jasa]);
    $creambath = BantuanKatalog::BuatProduk(['Nama' => 'Creambath Rambut Panjang Aroma Ginseng', 'Jenis' => JenisProduk::Jasa]);
    $masker = BantuanKatalog::BuatProduk(['Nama' => 'Hair Mask Keratin Perawatan Rambut Rusak', 'Jenis' => JenisProduk::Jasa]);
    $potong = BantuanKatalog::BuatProduk(['Nama' => 'Potong Rambut Wanita Model Layer', 'Jenis' => JenisProduk::Jasa]);
    $definisi = PaketSesi::query()->create(['IdProduk' => $paket->Id, 'JumlahSesi' => $jumlahSesi, 'MasaBerlakuHari' => $masaBerlaku, 'SemuaProdukJasa' => false, 'Aktif' => true]);
    PaketSesiProduk::query()->create(['IdPaketSesi' => $definisi->Id, 'IdProduk' => $creambath->Id]);
    PaketSesiProduk::query()->create(['IdPaketSesi' => $definisi->Id, 'IdProduk' => $masker->Id]);

    return $k + [
        'Paket' => $paket,
        'Definisi' => $definisi,
        'Creambath' => $creambath,
        'Masker' => $masker,
        'Potong' => $potong,
        'Ani' => Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']),
    ];
}

/**
 * Jual paket ke Ani (tunai). Hasil: penjualan & saldo sesinya.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $baris
 * @param  array<string, mixed>  $opsi
 * @return array{0: Penjualan, 1: SaldoSesi}
 */
function JualPaketSesi(TestCase $tes, array $k, string $harga = '1000000.00', array $baris = [], array $opsi = []): array
{
    $item = BantuanPenjualan::Item($k, [
        'Baris' => [array_replace(['Produk' => $k['Paket'], 'Jumlah' => '1', 'Harga' => $harga], $baris)],
    ] + $opsi, ['UuidPelanggan' => $k['Ani']->Uuid]);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->firstOrFail();

    return [$penjualan, SaldoSesi::query()->where('IdPenjualan', $penjualan->Id)->sole()];
}

/**
 * Item outbox `Sesi.Pakai`.
 *
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPakaiSesi(array $k, SaldoSesi $saldo, ?Produk $produk = null, int $jumlah = 1, ?string $uuid = null): array
{
    return [
        'Jenis' => 'Sesi.Pakai',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => [
            'UuidSaldoSesi' => $saldo->Uuid,
            'UuidProduk' => ($produk ?? $k['Creambath'])->Uuid,
            'Jumlah' => $jumlah,
            'UuidPengguna' => $k['Kasir']->Uuid,
            'DibuatPada' => CarbonImmutable::now()->subMinute()->utc()->toIso8601ZuluString(),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $k
 * @return list<array{0: string, 1: string|null}>
 */
function PakaiSesi(TestCase $tes, array $k, SaldoSesi $saldo, ?Produk $produk = null, int $jumlah = 1): array
{
    $hasil = BantuanKasir::KirimRingkas($tes, $k['Token'], [ItemPakaiSesi($k, $saldo, $produk, $jumlah)]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $hasil;
}

function MuatSaldo(SaldoSesi $s): SaldoSesi
{
    return SaldoSesi::query()->whereKey($s->Id)->firstOrFail();
}

/**
 * Invarian paket sesi tenant: jurnal seimbang; −saldo akun Pendapatan Diterima Dimuka = Σ NilaiTersisa = Σ Nilai mutasi;
 * per saldo: SisaSesi = Σ JumlahSesi mutasi, NilaiTersisa = Σ Nilai mutasi, SisaSetelah = jumlah berjalan.
 *
 * @return list<string>
 */
function PeriksaInvarianSesi(int $idTenant): array
{
    $galat = PemeriksaInvarian::PeriksaJurnalSeimbang($idTenant);
    $tersisa = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`NilaiTersisa`), 0) AS DECIMAL(18,2)) AS S FROM `SaldoSesi` WHERE `IdTenant` = ?', [$idTenant])->S;
    $mutasi = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`Nilai`), 0) AS DECIMAL(18,2)) AS S FROM `MutasiSesi` WHERE `IdTenant` = ?', [$idTenant])->S;
    $akun = BantuanPembelian::SaldoPeran($idTenant, PeranAkun::PendapatanDiterimaDimuka);

    if (! BigDecimal::of($tersisa)->isEqualTo(BigDecimal::of($mutasi))) {
        $galat[] = "Σ NilaiTersisa {$tersisa} ≠ Σ MutasiSesi.Nilai {$mutasi}";
    }

    if (! BigDecimal::of($akun)->isEqualTo(BigDecimal::of($tersisa)->negated())) {
        $galat[] = "Saldo akun PendapatanDiterimaDimuka {$akun} ≠ −Σ NilaiTersisa {$tersisa}";
    }

    foreach (DB::table('SaldoSesi')->where('IdTenant', $idTenant)->get() as $s) {
        $sesi = 0;
        $nilai = BigDecimal::zero();

        foreach (DB::table('MutasiSesi')->where('IdSaldoSesi', $s->Id)->orderBy('Id')->get() as $m) {
            $sesi += (int) $m->JumlahSesi;
            $nilai = $nilai->plus((string) $m->Nilai);

            if ($sesi !== (int) $m->SisaSetelah) {
                $galat[] = "MutasiSesi {$m->Id} SisaSetelah {$m->SisaSetelah} ≠ {$sesi}";
            }
        }

        if ($sesi !== (int) $s->SisaSesi || ! $nilai->isEqualTo((string) $s->NilaiTersisa)) {
            $galat[] = "SaldoSesi {$s->Id} sisa {$s->SisaSesi}/{$s->NilaiTersisa} ≠ mutasi {$sesi}/{$nilai}";
        }
    }

    return $galat;
}

describe('F-16d paket sesi: beli di kasir (J-16.2)', function (): void {
    it('saldo sesi dibuat, nilai bersih setelah diskon masuk Pendapatan Diterima Dimuka (bukan pendapatan). Kirim ulang = Duplikat', function (): void {
        $k = SiapkanPaketSesi($this);
        $item = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Paket'], 'Jumlah' => '1', 'Harga' => '1000000.00', 'DiskonManual' => ['Persen' => '10']]],
            'Penyetuju' => $k['Supervisor'],
        ], ['UuidPelanggan' => $k['Ani']->Uuid]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $saldo = SaldoSesi::query()->sole();
        expect($penjualan->PerluTinjauan)->toBeFalse()
            ->and($saldo->IdPelanggan)->toBe($k['Ani']->Id)
            ->and($saldo->JumlahSesi)->toBe(10)
            ->and($saldo->SisaSesi)->toBe(10)
            ->and($saldo->NilaiAwal)->toBe('900000.00')
            ->and($saldo->NilaiTersisa)->toBe('900000.00')
            ->and($saldo->Status)->toBe(StatusSaldoSesi::Aktif)
            ->and($saldo->BerlakuSampai?->toDateString())->toBe($penjualan->TanggalBisnis->addDays(90)->toDateString())
            ->and(MutasiSesi::query()->sole()->Jenis)->toBe(JenisMutasiSesi::Beli)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanDiterimaDimuka))->toBe('-900000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::DiskonPenjualan))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet))->toBe('900000.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
    });

    it('jumlah 2 = 2 × sesi paket, tanpa pelanggan atau jumlah pecahan ditolak. Pelanggan belum dikenal = diterima + tinjauan', function (): void {
        $k = SiapkanPaketSesi($this, jumlahSesi: 5);
        [, $saldo] = JualPaketSesi($this, $k, '400000.00', ['Jumlah' => '2']);
        expect($saldo->JumlahSesi)->toBe(10)->and($saldo->NilaiAwal)->toBe('800000.00');

        $tanpa = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Paket'], 'Jumlah' => '1', 'Harga' => '400000.00']]]);
        $pecahan = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Paket'], 'Jumlah' => '1.5', 'Harga' => '400000.00']]], ['UuidPelanggan' => $k['Ani']->Uuid]);
        $asing = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Paket'], 'Jumlah' => '1', 'Harga' => '400000.00']]], ['UuidPelanggan' => BantuanKasir::Uuid()]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]))->toBe([['Ditolak', 'PaketSesiTanpaPelanggan']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$pecahan]))->toBe([['Ditolak', 'JumlahPaketSesiTidakValid']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$asing]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $jual = Penjualan::query()->where('Uuid', $asing['Uuid'])->sole();
        expect($jual->PerluTinjauan)->toBeTrue()
            ->and($jual->AlasanTinjauan)->toContain('PaketSesi')
            ->and(SaldoSesi::query()->where('IdPenjualan', $jual->Id)->sole()->IdPelanggan)->toBeNull();
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
    });
});

describe('F-16d paket sesi: pemakaian dari kasir (J-16.3)', function (): void {
    it('tiap sesi mengakui NilaiTersisa ÷ SisaSesi, sesi terakhir mengakui sisa. Habis = status Habis. Kirim ulang = Duplikat', function (): void {
        $k = SiapkanPaketSesi($this, jumlahSesi: 3);
        [, $saldo] = JualPaketSesi($this, $k, '100000.00');

        $item = ItemPakaiSesi($k, $saldo);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PemakaianSesi::query()->sole()->NilaiDiakui)->toBe('33333.33')
            ->and(MuatSaldo($saldo)->SisaSesi)->toBe(2);

        expect(PakaiSesi($this, $k, $saldo, $k['Masker']))->toBe([['Diterima', null]])
            ->and(PakaiSesi($this, $k, $saldo))->toBe([['Diterima', null]]);

        $akhir = MuatSaldo($saldo);
        expect($akhir->SisaSesi)->toBe(0)
            ->and($akhir->NilaiTersisa)->toBe('0.00')
            ->and($akhir->Status)->toBe(StatusSaldoSesi::Habis)
            ->and(PemakaianSesi::query()->orderBy('Id')->pluck('NilaiDiakui')->all())->toBe(['33333.33', '33333.34', '33333.33'])
            ->and(PemakaianSesi::query()->where('PerluTinjauan', true)->count())->toBe(0)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('-100000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanDiterimaDimuka))->toBe('0.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);

        // Sisa 0 (misal dua perangkat offline): tetap diterima, tidak dipotong, ditinjau.
        expect(PakaiSesi($this, $k, $saldo))->toBe([['Diterima', null]]);
        $lebih = PemakaianSesi::query()->orderByDesc('Id')->firstOrFail();
        expect($lebih->PerluTinjauan)->toBeTrue()
            ->and($lebih->AlasanTinjauan)->toContain('SesiTidakAktif')
            ->and($lebih->NilaiDiakui)->toBe('0.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
    });

    it('layanan di luar paket & sesi melebihi sisa: diterima + tinjauan. Saldo/produk tidak dikenal ditolak', function (): void {
        $k = SiapkanPaketSesi($this, jumlahSesi: 2);
        [, $saldo] = JualPaketSesi($this, $k, '300000.00');

        expect(PakaiSesi($this, $k, $saldo, $k['Potong']))->toBe([['Diterima', null]]);
        expect(PemakaianSesi::query()->sole()->AlasanTinjauan)->toContain('ProdukDiluarPaket');

        expect(PakaiSesi($this, $k, $saldo, jumlah: 3))->toBe([['Diterima', null]]);
        $kurang = PemakaianSesi::query()->orderByDesc('Id')->firstOrFail();
        expect($kurang->AlasanTinjauan)->toContain('SesiKurang')
            ->and($kurang->NilaiDiakui)->toBe('150000.00')
            ->and(MuatSaldo($saldo)->Status)->toBe(StatusSaldoSesi::Habis);

        $palsu = ItemPakaiSesi($k, $saldo);
        $palsu['Data']['UuidSaldoSesi'] = BantuanKasir::Uuid();
        $produkPalsu = ItemPakaiSesi($k, $saldo);
        $produkPalsu['Data']['UuidProduk'] = BantuanKasir::Uuid();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$palsu, $produkPalsu]))->toBe([['Ditolak', 'SaldoSesiTidakDikenal'], ['Ditolak', 'ProdukTidakDikenal']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
    });

    it('API POS saldo sesi pelanggan (online) berisi paket aktif & layanan yang boleh ditukar. Pelanggan tenant lain 404', function (): void {
        $k = SiapkanPaketSesi($this);
        [, $saldo] = JualPaketSesi($this, $k);
        PakaiSesi($this, $k, $saldo);

        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$k['Ani']->Uuid}/sesi")->assertOk()
            ->assertJsonPath('Berlaku', true)
            ->assertJsonPath('Paket.0.Uuid', $saldo->Uuid)
            ->assertJsonPath('Paket.0.SisaSesi', 9)
            ->assertJsonPath('Paket.0.SemuaProdukJasa', false)
            ->assertJsonCount(2, 'Paket.0.ProdukBerlaku');

        $lain = SiapkanPaketSesi($this, 'Salon Lain Sesi Klaten');
        $this->withToken($lain['Token'])->getJson("/api/pos/v1/pelanggan/{$k['Ani']->Uuid}/sesi")->assertNotFound();

        // Katalog POS menandai produk paket.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = collect($this->withToken($k['Token'])->getJson('/api/pos/v1/katalog')->assertOk()->json('Produk'));
        expect($produk->firstWhere('Uuid', $k['Paket']->Uuid)['PaketSesi'] ?? null)->toBe(['JumlahSesi' => 10, 'MasaBerlakuHari' => 90, 'Aktif' => true])
            ->and($produk->firstWhere('Uuid', $k['Creambath']->Uuid))->toHaveKey('PaketSesi', null);
    });

    it('paket langganan tanpa fitur pelanggan.paket-sesi: pemakaian ditolak FiturTidakAktif', function (): void {
        $k = SiapkanPaketSesi($this);
        [, $saldo] = JualPaketSesi($this, $k);
        Langganan::query()->where('IdTenant', $k['Tenant']->Id)->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->value('Id')]);
        cache()->flush();

        expect(PakaiSesi($this, $k, $saldo))->toBe([['Ditolak', 'FiturTidakAktif']]);
    });
});

describe('F-16d paket sesi: void, retur, tutup sisa, hangus', function (): void {
    it('void setelah 2 sesi dipakai: sisa dibatalkan, pengakuan dibalik. Semua akun kembali nol', function (): void {
        $k = SiapkanPaketSesi($this);
        [$jual, $saldo] = JualPaketSesi($this, $k);
        PakaiSesi($this, $k, $saldo);
        PakaiSesi($this, $k, $saldo);
        expect(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('-200000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $akhir = MuatSaldo($saldo);
        expect($akhir->Status)->toBe(StatusSaldoSesi::Dibatalkan)
            ->and($akhir->SisaSesi)->toBe(0)
            ->and($akhir->NilaiTersisa)->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanDiterimaDimuka))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet))->toBe('0.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);

        // Pemakaian offline yang tiba setelah void: diterima + tinjauan, tidak dipotong.
        expect(PakaiSesi($this, $k, $saldo))->toBe([['Diterima', null]]);
        expect(PemakaianSesi::query()->orderByDesc('Id')->firstOrFail()->AlasanTinjauan)->toContain('SesiTidakAktif');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
    });

    it('baris paket sesi tidak bisa diretur', function (): void {
        $k = SiapkanPaketSesi($this);
        [$jual] = JualPaketSesi($this, $k);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']])]))
            ->toBe([['Ditolak', 'ReturPaketSesiTidakDidukung']]);
    });

    it('back-office: kembalikan sisa ke kas (Dr Diterima Dimuka Cr Kas), void sesudahnya ditolak. Batalkan pemakaian. Audit & izin', function (): void {
        $k = SiapkanPaketSesi($this);
        [$jual, $saldo] = JualPaketSesi($this, $k);
        PakaiSesi($this, $k, $saldo);
        PakaiSesi($this, $k, $saldo);
        $salah = PemakaianSesi::query()->orderByDesc('Id')->firstOrFail();

        // Kasir tanpa izin pelanggan.sesi.kelola.
        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post("/kelola/pelanggan/pemakaian-sesi/{$salah->Uuid}/batal", ['Alasan' => 'Salah pilih pelanggan'])->assertForbidden();

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->post("/kelola/pelanggan/pemakaian-sesi/{$salah->Uuid}/batal", ['Alasan' => 'Salah pilih pelanggan'])->assertSessionHasNoErrors()->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PemakaianSesi::query()->whereKey($salah->Id)->firstOrFail()->Status)->toBe(StatusPemakaianSesi::Dibatalkan)
            ->and(MuatSaldo($saldo)->SisaSesi)->toBe(9)
            ->and(MuatSaldo($saldo)->NilaiTersisa)->toBe('900000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('-100000.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
        $this->post("/kelola/pelanggan/pemakaian-sesi/{$salah->Uuid}/batal", ['Alasan' => 'Salah pilih pelanggan'])->assertSessionHasErrors('Status');

        $this->get("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/SaldoSesi/Detail')
            ->where('Saldo.SisaSesi', 9)->has('Saldo.Mutasi', 4)->has('Saldo.Pemakaian', 2)->where('Izin.KelolaSesi', true)->has('AkunKasBank'));

        $kas = BantuanPembelian::AkunKas()->Uuid;
        $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Refund', 'Alasan' => 'abc', 'UuidAkun' => $kas])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Refund', 'Alasan' => 'Pelanggan pindah ke luar kota'])->assertSessionHasErrors('UuidAkun');
        $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Refund', 'Alasan' => 'Pelanggan pindah ke luar kota', 'UuidAkun' => $kas])
            ->assertSessionHasNoErrors()->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(MuatSaldo($saldo)->Status)->toBe(StatusSaldoSesi::Dibatalkan)
            ->and(MutasiSesi::query()->where('Jenis', JenisMutasiSesi::Refund->value)->sole()->Nilai)->toBe('-900000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanDiterimaDimuka))->toBe('0.00')
            ->and(DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->where('Peristiwa', 'pelanggan.sesi-tutup')->count())->toBe(1)
            ->and(DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->where('Peristiwa', 'pelanggan.sesi-pakai-batal')->count())->toBe(1);
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Ditolak', 'VoidPaketSesiDitutup']]);

        // Tautan sumber jurnal.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $mutasi = MutasiSesi::query()->where('Jenis', JenisMutasiSesi::Refund->value)->sole();
        $this->get("/kelola/pelanggan/mutasi-sesi/{$mutasi->Uuid}")->assertRedirect("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}");
        $this->get("/kelola/pelanggan/pemakaian-sesi/{$salah->Uuid}")->assertRedirect("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}");
    });

    it('hangus otomatis setelah masa berlaku lewat: sisa nilai → Pendapatan Lain; idempoten', function (): void {
        $k = SiapkanPaketSesi($this, masaBerlaku: 30);
        [, $saldo] = JualPaketSesi($this, $k);
        PakaiSesi($this, $k, $saldo);
        $hanguskan = app(HanguskanSesiKedaluwarsa::class);

        expect($hanguskan->Jalankan(CarbonImmutable::now()->addDays(10)))->toBe(0)
            ->and($hanguskan->Jalankan(CarbonImmutable::now()->addDays(40)))->toBe(1)
            ->and($hanguskan->Jalankan(CarbonImmutable::now()->addDays(41)))->toBe(0);

        $akhir = MuatSaldo($saldo);
        expect($akhir->Status)->toBe(StatusSaldoSesi::Hangus)
            ->and($akhir->SisaSesi)->toBe(0)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-900000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanDiterimaDimuka))->toBe('0.00');
        expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);

        // Perintah malam ikut menghanguskan paket sesi.
        $this->artisan('pelanggan:proses-loyalti', ['--tenant' => [(string) $k['Tenant']->Id]])->assertSuccessful();
    });
});

describe('F-16d paket sesi: master & back-office', function (): void {
    it('tambah & ubah paket sesi: hanya produk Jasa, satu per produk, layanan wajib dipilih. Daftar TabelData', function (): void {
        $k = SiapkanPaketSesi($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $barang = BantuanKatalog::BuatProduk(['Nama' => 'Shampo Anti Ketombe 400 ml']);
        $baru = BantuanKatalog::BuatProduk(['Nama' => 'Paket Pijat Refleksi 5x Sesi', 'Jenis' => JenisProduk::Jasa]);
        $isian = fn (array $timpa = []): array => array_replace([
            'UuidProduk' => $baru->Uuid,
            'JumlahSesi' => 5,
            'MasaBerlakuHari' => null,
            'SemuaProdukJasa' => false,
            'ProdukBerlaku' => [$k['Creambath']->Uuid],
            'Aktif' => true,
        ], $timpa);

        $this->get('/kelola/paket-sesi/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/PaketSesi/Formulir')->where('FiturAktif', true));
        $this->post('/kelola/paket-sesi', $isian(['UuidProduk' => $barang->Uuid]))->assertSessionHasErrors('UuidProduk');
        $this->post('/kelola/paket-sesi', $isian(['UuidProduk' => $k['Paket']->Uuid]))->assertSessionHasErrors('UuidProduk');
        $this->post('/kelola/paket-sesi', $isian(['ProdukBerlaku' => []]))->assertSessionHasErrors('ProdukBerlaku');
        $this->post('/kelola/paket-sesi', $isian(['ProdukBerlaku' => [$baru->Uuid]]))->assertSessionHasErrors('ProdukBerlaku');
        $this->post('/kelola/paket-sesi', $isian(['JumlahSesi' => 0]))->assertSessionHasErrors('JumlahSesi');
        $this->post('/kelola/paket-sesi', $isian())->assertSessionHasNoErrors()->assertRedirect('/kelola/paket-sesi');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $paket = PaketSesi::query()->where('IdProduk', $baru->Id)->sole();
        expect($paket->JumlahSesi)->toBe(5)->and($paket->MasaBerlakuHari)->toBeNull();

        $this->put("/kelola/paket-sesi/{$paket->Uuid}", $isian(['JumlahSesi' => 6, 'SemuaProdukJasa' => true, 'ProdukBerlaku' => [], 'MasaBerlakuHari' => 60]))
            ->assertSessionHasNoErrors()->assertRedirect('/kelola/paket-sesi');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PaketSesi::query()->whereKey($paket->Id)->value('JumlahSesi'))->toBe(6)
            ->and(PaketSesiProduk::query()->where('IdPaketSesi', $paket->Id)->count())->toBe(0);

        $this->get('/kelola/paket-sesi', ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'Data');
        $this->get('/kelola/paket-sesi?cari=Pijat', ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('Data.0.Nama', 'Paket Pijat Refleksi 5x Sesi')->assertJsonPath('Data.0.SemuaProdukJasa', true);
        $this->get("/kelola/paket-sesi/{$paket->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Paket.JumlahSesi', 6));

        // Kasir (tanpa produk.kelola) tidak bisa menambah.
        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post('/kelola/paket-sesi', $isian())->assertForbidden();
    });

    it('daftar saldo sesi & detail pelanggan. Tenant lain tidak bisa membuka saldo sesi', function (): void {
        $k = SiapkanPaketSesi($this);
        [, $saldo] = JualPaketSesi($this, $k);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/pelanggan/saldo-sesi', ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('Data.0.Uuid', $saldo->Uuid)
            ->assertJsonPath('Data.0.Pelanggan.Nama', 'Ani Rahmawati')
            ->assertJsonPath('Data.0.NilaiTersisa', '1000000.00');
        $this->get('/kelola/pelanggan/saldo-sesi?saring[Status]=Habis', ['Accept' => 'application/json'])->assertOk()->assertJsonCount(0, 'Data');
        $this->get("/kelola/pelanggan/{$k['Ani']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('PaketSesi.Berlaku', true)->has('PaketSesi.Daftar', 1)->where('PaketSesi.Daftar.0.SisaSesi', 10));

        $lain = BantuanOrganisasi::BuatTenant('Barbershop Lain Boyolali');
        BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id);
        $this->get("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}")->assertNotFound();
        $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Hangus', 'Alasan' => 'Coba lintas tenant'])->assertNotFound();
    });
});

it('kasir bawaan tidak punya izin pelanggan.sesi.kelola. Admin punya', function (): void {
    $k = SiapkanPaketSesi($this);
    $admin = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
    [, $saldo] = JualPaketSesi($this, $k);

    BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
    $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Hangus', 'Alasan' => 'Pelanggan tidak datang lagi'])->assertForbidden();
    BantuanOrganisasi::Masuk($this, $admin, $k['Tenant']->Id);
    $this->post("/kelola/pelanggan/saldo-sesi/{$saldo->Uuid}/tutup", ['Jenis' => 'Hangus', 'Alasan' => 'Pelanggan tidak datang lagi'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(MuatSaldo($saldo)->Status)->toBe(StatusSaldoSesi::Hangus)
        ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-1000000.00');
    expect(PeriksaInvarianSesi($k['Tenant']->Id))->toBe([]);
});
