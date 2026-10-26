<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Pelanggan\Enum\JenisMutasiPoin;
use App\Domain\Pelanggan\Enum\SumberMutasiPoin;
use App\Domain\Pelanggan\Model\MutasiPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PengaturanLoyalti;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16b bagian 2 (PRD "Rincian F-16b"): penukaran poin sebagai diskon pesanan sebelum pajak (J-16.4). Poin dipotong di
 * transaksi penjualan yang sama sebelum perolehan, idempoten; saldo kurang / nilai berbeda = diterima + tinjauan; nilai
 * yang terpotong batas subtotal ditolak; void mengembalikan poin; jurnal mendebit Diskon Penjualan; endpoint saldo POS.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + Ani bersaldo [saldo] poin + produk Rp 38.500 + loyalti aktif (Rp 10.000 = 1 poin; 1 poin = Rp 100).
 *
 * @return array<string, mixed>
 */
function SiapkanTukarPoin(TestCase $tes, int $saldo = 100): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Toko Kelontong Tukar Poin');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $ani = Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']);
    PengaturanLoyalti::query()->create(['Aktif' => true]);

    if ($saldo > 0) {
        MutasiPoin::query()->create([
            'IdPelanggan' => $ani->Id,
            'Jenis' => JenisMutasiPoin::Penyesuaian,
            'Poin' => $saldo,
            'Sisa' => $saldo,
            'JenisSumber' => SumberMutasiPoin::Manual,
            'KedaluwarsaPada' => CarbonImmutable::today()->addMonths(6)->toDateString(),
        ]);
    }

    return $k + ['Produk' => $produk, 'Ani' => $ani];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemTukarPoin(array $k, int $poin, string $nilai, array $timpa = []): array
{
    return BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']], 'TukarPoin' => ['Poin' => $poin, 'Nilai' => $nilai]],
        $timpa + ['UuidPelanggan' => $k['Ani']->Uuid],
    );
}

function SaldoPoinTukar(Pelanggan $p): int
{
    return (int) MutasiPoin::query()->where('IdPelanggan', $p->Id)->sum('Poin');
}

function SaldoDiskonJurnal(Penjualan $p): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::DiskonPenjualan, $p->IdOutlet);
    $saldo = Kuantitas::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $p->IdJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Kuantitas::Dari($b->Debit))->Kurangi(Kuantitas::Dari($b->Kredit));
    }

    return (string) $saldo->KeDesimal()->toScale(2);
}

describe('F-16b tukar poin di penjualan', function (): void {
    it('memotong poin sebelum perolehan, menyimpan snapshot, jurnal Diskon Penjualan, dan idempoten', function (): void {
        $k = SiapkanTukarPoin($this);
        $item = ItemTukarPoin($k, 50, '5000');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        // 77.000 − 5.000 = 72.000 → perolehan 7 poin dari total setelah diskon.
        expect($jual->PoinDitukar)->toBe(50)
            ->and((string) $jual->DiskonPoin)->toBe('5000.00')
            ->and((string) $jual->DiskonPesanan)->toBe('5000.00')
            ->and((string) $jual->TotalDiskon)->toBe('5000.00')
            ->and((string) $jual->TotalAkhir)->toBe('72000.00')
            ->and($jual->PerluTinjauan)->toBeFalse()
            ->and(SaldoDiskonJurnal($jual))->toBe('5000.00');

        $tukar = MutasiPoin::query()->where('Jenis', JenisMutasiPoin::Penukaran->value)->sole();
        expect($tukar->Poin)->toBe(-50)
            ->and($tukar->IdSumber)->toBe($jual->Id)
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::Perolehan->value)->sole()->Poin)->toBe(7)
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::Penyesuaian->value)->sole()->Sisa)->toBe(50)
            ->and(SaldoPoinTukar($k['Ani']))->toBe(57);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoPoinTukar($k['Ani']))->toBe(57);
    });

    it('saldo kurang atau nilai tukar berbeda: penjualan tetap diterima, poin dipotong, ditandai tinjauan', function (): void {
        $k = SiapkanTukarPoin($this, 20);
        // 50 poin × Rp 100 = Rp 5.000, tetapi perangkat memakai nilai lama Rp 120/poin.
        $item = ItemTukarPoin($k, 50, '6000');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        expect($jual->PerluTinjauan)->toBeTrue()
            ->and($jual->AlasanTinjauan)->toContain('PenukaranPoin: saldo 20 poin kurang dari 50 poin yang ditukar')
            ->and($jual->AlasanTinjauan)->toContain('berbeda dengan 50 poin × nilai tukar saat ini (Rp 5.000)')
            ->and((string) $jual->DiskonPoin)->toBe('6000.00')
            // 20 − 50 + ⌊71.000 ÷ 10.000⌋ = −23.
            ->and(SaldoPoinTukar($k['Ani']))->toBe(-23);
    });

    it('menolak nilai tukar yang melebihi sisa subtotal dan tukar poin tanpa pelanggan', function (): void {
        $k = SiapkanTukarPoin($this);
        // Ringkasan perangkat dari mesin (dibatasi, total 0), tetapi nilai tukar yang dikirim tidak dibatasi.
        $lebih = ItemTukarPoin($k, 800, '80000');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lebih]))->toBe([['Ditolak', 'HitunganTidakCocok']]);

        $tanpaPelanggan = ItemTukarPoin($k, 50, '5000', ['UuidPelanggan' => null]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaPelanggan]))->toBe([['Ditolak', 'DataTidakValid']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->count())->toBe(0)
            ->and(SaldoPoinTukar($k['Ani']))->toBe(100);
    });

    it('void mengembalikan poin yang ditukar dan membalik perolehan', function (): void {
        $k = SiapkanTukarPoin($this);
        $item = ItemTukarPoin($k, 50, '5000');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $kembali = MutasiPoin::query()->where('Jenis', JenisMutasiPoin::BatalPenukaran->value)->sole();
        expect($kembali->Poin)->toBe(50)
            ->and($kembali->Sisa)->toBe(50)
            ->and($kembali->KedaluwarsaPada?->toDateString())->toBe(CarbonImmutable::today()->addMonthsNoOverflow(12)->toDateString())
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::PembalikanVoid->value)->sole()->Poin)->toBe(-7)
            ->and(SaldoPoinTukar($k['Ani']))->toBe(100);
    });
});

describe('F-16b saldo poin untuk POS', function (): void {
    it('GET /api/pos/v1/pelanggan/{uuid}/poin: saldo terkini & aturan tukar. Pelanggan tenant lain = 404', function (): void {
        $k = SiapkanTukarPoin($this, 120);
        $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Jaya');
        $tetangga = Pelanggan::query()->create(['Nama' => 'Ani Tetangga', 'NoHp' => '6281234567890']);

        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$k['Ani']->Uuid}/poin")
            ->assertOk()
            ->assertExactJson([
                'Pelanggan' => ['Uuid' => $k['Ani']->Uuid, 'SaldoPoin' => 120],
                'TukarPoin' => ['Berlaku' => true, 'NilaiTukarPoin' => '100.00', 'MinimalTukarPoin' => 10],
            ]);
        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$tetangga->Uuid}/poin")
            ->assertNotFound()
            ->assertJsonPath('Galat.Kode', 'PelangganTidakDitemukan');
        unset($lain);
    });
});

describe('F-16b pengaturan tukar poin', function (): void {
    it('nilai tukar tidak boleh melebihi belanja per poin. Minimal tukar 1–100.000', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Pengaturan Tukar');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $dasar = ['Aktif' => true, 'BelanjaPerPoin' => '10000', 'MasaBerlakuBulan' => 12, 'BulanEvaluasiTier' => 12];

        $this->put('/kelola/pelanggan/loyalti', $dasar + ['NilaiTukarPoin' => '20000'])->assertSessionHasErrors('NilaiTukarPoin');
        $this->put('/kelola/pelanggan/loyalti', $dasar + ['MinimalTukarPoin' => 0])->assertSessionHasErrors('MinimalTukarPoin');
        $this->put('/kelola/pelanggan/loyalti', $dasar + ['NilaiTukarPoin' => '250', 'MinimalTukarPoin' => 20])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = PengaturanLoyalti::query()->sole();
        expect((string) $p->NilaiTukarPoin)->toBe('250.00')
            ->and($p->MinimalTukarPoin)->toBe(20);
    });
});
