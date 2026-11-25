<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwalDetail;

/**
 * Pencarian produk berstok untuk form stok awal (tipe FE `HasilCariProdukStok['Data']`, DesainF05a C.6.6): produk
 * dari `InfoProdukStok::CariUntukStok` (berstok, bukan Konsinyasi, belum diarsipkan) ditambah, bila lokasi stok
 * disebut, `SaldoDiGudang` & `HppRataRata` dari SaldoStok dan `StokAwalSudahAda` (sudah ada stok awal Diposting di
 * lokasi itu). Tanpa lokasi ketiganya null/false. `hanyaKonsinyasi` = hanya barang titipan (dokumen konsinyasi F-05i). `hanyaProduksi` = hanya produk hasil order produksi (D-331).
 */
final class CariProdukStok
{
    public function __construct(private readonly InfoProdukStok $infoProduk) {}

    /**
     * @return list<array{Uuid: string, Nama: string, Sku: string|null, Jenis: string, Pelacakan: string, SimbolSatuan: string, BolehDesimal: bool, SaldoDiGudang: string|null, HppRataRata: string|null, StokAwalSudahAda: bool}>
     */
    public function Cari(string $kata, ?int $idGudang, int $batas = 20, bool $hanyaKonsinyasi = false, bool $hanyaProduksi = false): array
    {
        $produk = $this->infoProduk->CariUntukStok($kata, max(1, min(50, $batas)), $hanyaKonsinyasi, $hanyaProduksi);
        $id = array_map(fn (DataInfoProdukStok $p): int => $p->id, $produk);
        $saldo = [];
        $sudahAda = [];

        if ($idGudang !== null && $id !== []) {
            $saldo = SaldoStok::query()->where('IdGudang', $idGudang)->whereIn('IdProduk', $id)->get()->keyBy('IdProduk')->all();
            $sudahAda = array_flip(StokAwalDetail::query()
                ->join('StokAwal', 'StokAwal.Id', '=', 'StokAwalDetail.IdStokAwal')
                ->where('StokAwal.IdGudang', $idGudang)
                ->where('StokAwal.Status', StatusStokAwal::Diposting->value)
                ->whereIn('StokAwalDetail.IdProduk', $id)
                ->distinct()
                ->pluck('StokAwalDetail.IdProduk')
                ->map(fn (mixed $nilai): int => (int) $nilai)
                ->all());
        }

        return array_map(function (DataInfoProdukStok $p) use ($saldo, $sudahAda, $idGudang): array {
            $baris = $saldo[$p->id] ?? null;

            return [
                'Uuid' => $p->uuid,
                'Nama' => $p->nama,
                'Sku' => $p->sku,
                'Jenis' => $p->jenis->value,
                'Pelacakan' => $p->pelacakan->value,
                'SimbolSatuan' => $p->simbolSatuan,
                'BolehDesimal' => $p->bolehDesimal,
                'SaldoDiGudang' => $idGudang === null ? null : ($baris instanceof SaldoStok ? $baris->JumlahTersedia : '0.0000'),
                'HppRataRata' => $baris instanceof SaldoStok ? $baris->HppRataRata : null,
                'StokAwalSudahAda' => isset($sudahAda[$p->id]),
            ];
        }, $produk);
    }
}
