<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;

/**
 * Data buku stok untuk laporan stok F-14a (domain Laporan tidak membaca tabel Persediaan langsung):
 * - posisi & nilai persediaan per (produk, lokasi stok) pada akhir suatu tanggal bisnis dari ledger `MutasiStok`
 *   (Σ Jumlah, Σ TotalHpp untuk `TanggalBisnis` ≤ tanggal), sama dengan cara `SaldoStok` dibangun ulang;
 * - saldo terkini (`SaldoStok`) untuk pasangan produk × lokasi (stok kritis).
 */
final class StokUntukLaporan
{
    /**
     * @param  list<int>  $idGudang
     * @return list<array{IdProduk: int, IdGudang: int, Jumlah: string, Nilai: string}> pasangan yang jumlah atau nilainya bukan nol
     */
    public function AmbilNilaiPadaTanggal(CarbonImmutable $tanggal, array $idGudang): array
    {
        if ($idGudang === []) {
            return [];
        }

        $baris = MutasiStok::query()
            ->whereIn('IdGudang', $idGudang)
            ->where('TanggalBisnis', '<=', $tanggal->toDateString())
            ->groupBy('IdProduk', 'IdGudang')
            ->selectRaw('`IdProduk`, `IdGudang`, COALESCE(SUM(`Jumlah`), 0) AS `Jumlah`, COALESCE(SUM(`TotalHpp`), 0) AS `Nilai`')
            ->havingRaw('SUM(`Jumlah`) <> 0 OR SUM(`TotalHpp`) <> 0')
            ->orderBy('IdGudang')
            ->orderBy('IdProduk')
            ->toBase()
            ->get();

        return array_values(array_map(fn (object $b): array => [
            'IdProduk' => (int) $b->IdProduk,
            'IdGudang' => (int) $b->IdGudang,
            'Jumlah' => Kuantitas::Dari((string) $b->Jumlah)->KeString(),
            'Nilai' => Uang::Dari((string) $b->Nilai)->KeString(),
        ], $baris->all()));
    }

    /**
     * Saldo terkini per pasangan; pasangan tanpa baris `SaldoStok` tidak ada di hasil (saldo nol).
     *
     * @param  list<int>  $idGudang
     * @param  list<int>  $idProduk
     * @return array<string, array{Jumlah: string, Nilai: string}> kunci = "IdProduk|IdGudang"
     */
    public function AmbilSaldo(array $idGudang, array $idProduk): array
    {
        if ($idGudang === [] || $idProduk === []) {
            return [];
        }

        $hasil = [];

        foreach (array_chunk(array_values(array_unique($idProduk)), 1000) as $potongan) {
            foreach (SaldoStok::query()->whereIn('IdGudang', $idGudang)->whereIn('IdProduk', $potongan)->get(['IdProduk', 'IdGudang', 'JumlahTersedia', 'NilaiPersediaan']) as $s) {
                $hasil["{$s->IdProduk}|{$s->IdGudang}"] = ['Jumlah' => (string) $s->JumlahTersedia, 'Nilai' => (string) $s->NilaiPersediaan];
            }
        }

        return $hasil;
    }

    /**
     * F-05g: batch yang masih bersisa dan kedaluwarsanya jatuh paling lambat `$sampai` (termasuk yang sudah lewat), urut
     * kedaluwarsa terdekat. `Jumlah` = seluruh batch yang cocok, `Baris` dibatasi `$batas` bila diisi.
     *
     * @param  list<int>  $idGudang
     * @return array{Jumlah: int, Baris: list<array{IdProduk: int, IdGudang: int, NomorBatch: string, TanggalKedaluwarsa: string, JumlahSisa: string}>}
     */
    public function AmbilBatchMendekatiKedaluwarsa(array $idGudang, CarbonImmutable $sampai, ?int $batas = null): array
    {
        if ($idGudang === []) {
            return ['Jumlah' => 0, 'Baris' => []];
        }

        $kueri = BatchStok::query()
            ->whereIn('IdGudang', $idGudang)
            ->where('JumlahSisa', '>', 0)
            ->whereNotNull('TanggalKedaluwarsa')
            ->where('TanggalKedaluwarsa', '<=', $sampai->toDateString());
        $jumlah = (clone $kueri)->count();

        $baris = $kueri->orderBy('TanggalKedaluwarsa')->orderBy('NomorBatch')->orderBy('Id')
            ->when($batas !== null, fn ($k) => $k->limit((int) $batas))
            ->get(['IdProduk', 'IdGudang', 'NomorBatch', 'TanggalKedaluwarsa', 'JumlahSisa']);

        return ['Jumlah' => $jumlah, 'Baris' => array_values($baris->map(fn (BatchStok $b): array => [
            'IdProduk' => $b->IdProduk,
            'IdGudang' => $b->IdGudang,
            'NomorBatch' => $b->NomorBatch,
            'TanggalKedaluwarsa' => (string) $b->TanggalKedaluwarsa?->toDateString(),
            'JumlahSisa' => Kuantitas::Dari($b->JumlahSisa)->KeString(),
        ])->all())];
    }

    /**
     * X6 (v3.43): pemakaian bersih per (produk, lokasi stok) dalam rentang tanggal bisnis (inklusif) = barang keluar
     * karena dijual (termasuk bahan resep), dipakai produksi, atau susut, dikurangi retur penjualan & pembalik void.
     * Transfer, opname, penyesuaian, dan penerimaan tidak dihitung (bukan permintaan). Pasangan pemakaian ≤ 0 tidak ikut.
     *
     * @param  list<int>  $idGudang
     * @return list<array{IdProduk: int, IdGudang: int, Pakai: string}>
     */
    public function AmbilPemakaian(array $idGudang, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        if ($idGudang === []) {
            return [];
        }

        $baris = MutasiStok::query()
            ->whereIn('IdGudang', $idGudang)
            ->whereBetween('TanggalBisnis', [$dari->toDateString(), $sampai->toDateString()])
            ->whereIn('JenisMutasi', [JenisMutasi::Penjualan->value, JenisMutasi::ReturPenjualan->value, JenisMutasi::ProduksiPakai->value, JenisMutasi::Susut->value])
            ->groupBy('IdProduk', 'IdGudang')
            ->selectRaw('`IdProduk`, `IdGudang`, -COALESCE(SUM(`Jumlah`), 0) AS `Pakai`')
            ->havingRaw('SUM(`Jumlah`) < 0')
            ->orderBy('IdGudang')
            ->orderBy('IdProduk')
            ->toBase()
            ->get();

        return array_values(array_map(fn (object $b): array => [
            'IdProduk' => (int) $b->IdProduk,
            'IdGudang' => (int) $b->IdGudang,
            'Pakai' => Kuantitas::Dari((string) $b->Pakai)->KeString(),
        ], $baris->all()));
    }

    /**
     * Pemakaian & selisih stok untuk food cost (§10.10): per produk × jenis mutasi pada rentang tanggal bisnis, jumlah dan
     * nilai (Σ TotalHpp) bertanda (keluar negatif). Jenis: penjualan, retur penjualan, bahan produksi, susut/terbuang,
     * penyesuaian, dan opname.
     *
     * @param  list<int>  $idGudang
     * @return list<array{IdProduk: int, JenisMutasi: string, Jumlah: string, Nilai: string}>
     */
    public function AmbilPemakaianPerJenis(array $idGudang, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        if ($idGudang === []) {
            return [];
        }

        $baris = MutasiStok::query()
            ->whereIn('IdGudang', $idGudang)
            ->whereBetween('TanggalBisnis', [$dari->toDateString(), $sampai->toDateString()])
            ->whereIn('JenisMutasi', [
                JenisMutasi::Penjualan->value, JenisMutasi::ReturPenjualan->value, JenisMutasi::ProduksiPakai->value, JenisMutasi::Susut->value,
                JenisMutasi::PenyesuaianMasuk->value, JenisMutasi::PenyesuaianKeluar->value, JenisMutasi::OpnameLebih->value, JenisMutasi::OpnameKurang->value,
            ])
            ->groupBy('IdProduk', 'JenisMutasi')
            ->selectRaw('`IdProduk`, `JenisMutasi`, COALESCE(SUM(`Jumlah`), 0) AS `Jumlah`, COALESCE(SUM(`TotalHpp`), 0) AS `Nilai`')
            ->toBase()
            ->get();

        return array_values(array_map(fn (object $b): array => [
            'IdProduk' => (int) $b->IdProduk,
            'JenisMutasi' => (string) $b->JenisMutasi,
            'Jumlah' => Kuantitas::Dari((string) $b->Jumlah)->KeString(),
            'Nilai' => Uang::Dari((string) $b->Nilai)->KeString(),
        ], $baris->all()));
    }
}
