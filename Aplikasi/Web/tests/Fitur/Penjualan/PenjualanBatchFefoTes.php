<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Produk ber-batch dengan stok awal terposting. `batch` = [nomor, jumlah, hari menuju kedaluwarsa (null = tanpa kedaluwarsa)],
 * semua batch dengan HPP Rp10.000 per pcs supaya HPP berjalan tidak bergantung pada urutan batch.
 *
 * @param  list<array{0: string, 1: string, 2: int|null}>  $batch
 */
function BuatProdukBatchJual(array $k, array $batch, string $nama = 'Susu UHT Full Cream 1 Liter'): Produk
{
    // Beberapa fixture sengaja memuat batch tanpa tanggal kedaluwarsa (selalu diambil paling akhir oleh FEFO).
    config(['persediaan.StokAwal.WajibKedaluwarsaBatch' => false]);
    $produk = BantuanKatalog::BuatProduk(['Nama' => $nama, 'Pelacakan' => PelacakanProduk::Batch], '19500.00');
    $baris = array_map(fn (array $b) => BantuanStokAwal::Baris(
        $produk,
        $b[1],
        '10000',
        $b[0],
        $b[2] === null ? null : CarbonImmutable::now()->addDays($b[2])->toDateString(),
    ), $batch);
    BantuanStokAwal::BuatDanPosting($k['Gudang'], $baris, $k['Pemilik']->Id);

    return $produk;
}

/** @return array<string, string> NomorBatch → JumlahSisa */
function SisaBatchJual(Produk $produk): array
{
    return BatchStok::query()->where('IdProduk', $produk->Id)->orderBy('NomorBatch')->pluck('JumlahSisa', 'NomorBatch')->all();
}

function SaldoProdukBatchJual(Produk $produk, int $idGudang): ?string
{
    return SaldoStok::query()->where('IdProduk', $produk->Id)->where('IdGudang', $idGudang)->value('JumlahTersedia');
}

describe('F-05g penjualan produk ber-batch (FEFO di server)', function (): void {
    it('satu baris dipecah FEFO lintas batch (kedaluwarsa terdekat dulu, tanpa kedaluwarsa terakhir); HPP baris = Σ pecahan. Saldo & batch turun. Invariant terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['C-TANPA', '5', null], ['B-LAMA', '5', 40], ['A-DEKAT', '3', 10]]);

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '5', 'Harga' => '19500.00']]]);

        $mutasi = MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdReferensi', $p->Id)->orderBy('Id')->get();
        $namaBatch = BatchStok::query()->pluck('NomorBatch', 'Id')->all();
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect($mutasi->map(fn (MutasiStok $m): array => [$namaBatch[$m->IdBatchStok], $m->Jumlah])->all())
            ->toBe([['A-DEKAT', '-3.0000'], ['B-LAMA', '-2.0000']])
            ->and(SisaBatchJual($susu))->toBe(['A-DEKAT' => '0.0000', 'B-LAMA' => '3.0000', 'C-TANPA' => '5.0000'])
            ->and(SaldoProdukBatchJual($susu, $k['Gudang']->Id))->toBe('8.0000')
            ->and($d->TotalHpp)->toBe('50000.00')
            ->and($p->PerluTinjauan)->toBeFalse()
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('dua baris produk yang sama tidak mengambil unit batch yang sama dua kali', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['A-DEKAT', '3', 10], ['B-LAMA', '5', 40]]);

        BantuanPenjualan::Jual($this, $k, ['Baris' => [
            ['Produk' => $susu, 'Jumlah' => '2', 'Harga' => '19500.00'],
            ['Produk' => $susu, 'Jumlah' => '2', 'Harga' => '19500.00'],
        ]]);

        expect(SisaBatchJual($susu))->toBe(['A-DEKAT' => '0.0000', 'B-LAMA' => '4.0000'])
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('stok batch kurang: diterima, hanya bagian yang ada yang mengurangi batch, ditandai PerluTinjauan BatchTidakCukup (batch tidak pernah minus)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['A-DEKAT', '3', 10]]);

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '5', 'Harga' => '19500.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('BatchTidakCukup')
            ->and(SisaBatchJual($susu))->toBe(['A-DEKAT' => '0.0000'])
            ->and(SaldoProdukBatchJual($susu, $k['Gudang']->Id))->toBe('0.0000')
            ->and($d->TotalHpp)->toBe('30000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('produk ber-batch tanpa stok batch sama sekali: diterima, tanpa mutasi, ditinjau BatchTidakCukup', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BantuanKatalog::BuatProduk(['Nama' => 'Yoghurt Plain Cup 100 gram', 'Pelacakan' => PelacakanProduk::Batch], '8500.00');

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '2', 'Harga' => '8500.00']]]);

        expect($p->AlasanTinjauan)->toContain('BatchTidakCukup')
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdReferensi', $p->Id)->count())->toBe(0)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('batch yang sudah lewat kedaluwarsa tetap diambil lebih dulu (FEFO) tetapi penjualan ditandai BatchKedaluwarsa', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['X-LEWAT', '2', -5], ['B-LAMA', '5', 30]]);

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '3', 'Harga' => '19500.00']]]);

        expect($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('BatchKedaluwarsa')->toContain('X-LEWAT')
            ->and(SisaBatchJual($susu))->toBe(['B-LAMA' => '4.0000', 'X-LEWAT' => '0.0000']);
    });

    it('produk bernomor seri tanpa nomor seri yang dicatat ditolak NomorSeriTidakSesuai (penjualannya dicakup PenjualanSerialTes)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $seri = BantuanKatalog::BuatProduk(['Nama' => 'Rice Cooker Digital 1,8 Liter', 'Pelacakan' => PelacakanProduk::Seri], '675000.00');
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $seri, 'Jumlah' => '1', 'Harga' => '675000.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Ditolak', 'NomorSeriTidakSesuai']])
            ->and(Penjualan::query()->where('Uuid', $item['Uuid'])->exists())->toBeFalse();
    });
});

describe('F-05g void & retur mengembalikan stok ke batch asal', function (): void {
    it('void: setiap pecahan kembali ke batch yang sama (tanpa membuat batch baru). Saldo & sisa batch pulih', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['A-DEKAT', '3', 10], ['B-LAMA', '5', 40]]);
        $awal = SisaBatchJual($susu);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '5', 'Harga' => '19500.00']]]);
        expect(SisaBatchJual($susu))->toBe(['A-DEKAT' => '0.0000', 'B-LAMA' => '3.0000']);

        $item = BantuanPenjualan::ItemVoid($k, $p);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SisaBatchJual($susu))->toBe($awal)
            ->and(BatchStok::query()->where('IdProduk', $susu->Id)->count())->toBe(2)
            ->and(SaldoProdukBatchJual($susu, $k['Gudang']->Id))->toBe('8.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('retur parsial berulang: unit kembali ke batch tempat unit itu diambil (berurutan, bilangan bulat), retur terakhir menutup sisa. Nilai HPP Σ = asal', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $susu = BuatProdukBatchJual($k, [['A-DEKAT', '3', 10], ['B-LAMA', '5', 40]]);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '5', 'Harga' => '19500.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        foreach (['2', '2', '1'] as $jumlah) {
            $item = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => $jumlah, 'Kondisi' => 'LayakJual']]);
            expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
            $p->refresh();
        }

        $kembali = MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::ReturPenjualan->value)->orderBy('Id')->get();
        $namaBatch = BatchStok::query()->pluck('NomorBatch', 'Id')->all();

        // Penjualan mengambil 3 dari A lalu 2 dari B; retur 2 → 2 dari A; retur 2 → 1 dari A + 1 dari B; retur 1 → 1 dari B.
        expect($kembali->map(fn (MutasiStok $m): array => [$namaBatch[$m->IdBatchStok], $m->Jumlah])->all())
            ->toBe([['A-DEKAT', '2.0000'], ['A-DEKAT', '1.0000'], ['B-LAMA', '1.0000'], ['B-LAMA', '1.0000']])
            ->and(SisaBatchJual($susu))->toBe(['A-DEKAT' => '3.0000', 'B-LAMA' => '5.0000'])
            // Dijumlah dengan Uang (brick/math, aturan emas #7), tidak bergantung ekstensi bcmath.
            ->and($kembali->reduce(fn (Uang $t, MutasiStok $m): Uang => $t->Tambah(Uang::Dari((string) $m->TotalHpp)), Uang::Nol())->KeString())->toBe('50000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});
