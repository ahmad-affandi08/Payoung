<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use Illuminate\Support\Collection;

/**
 * Isi satu rak untuk `TabelData` (F-05c INV-07; tipe FE `BarisProdukRak`): produk yang ditempatkan di rak, dengan Nama/SKU
 * dari katalog lewat subkueri publik `InfoProdukStok::KueriIdNama()` (cari: nama, SKU, atau barcode persis). Urut: Nama
 * (bawaan naik). Hanya satu halaman yang dimuat.
 */
final class DetailRakGudang
{
    public const KOLOM_URUT = ['Nama'];

    public function __construct(private readonly InfoProdukStok $infoProduk) {}

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(RakGudang $rak, DataPermintaanTabel $permintaan): array
    {
        $kueri = ProdukRak::query()
            ->joinSub($this->infoProduk->KueriIdNama($permintaan->cari), 'P', 'P.Id', '=', 'ProdukRak.IdProduk')
            ->where('ProdukRak.IdRak', $rak->Id)
            ->select('ProdukRak.*');

        if ($permintaan->urut === []) {
            $kueri->orderBy('P.Nama');
        }

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['Nama' => 'P.Nama'], function (Collection $baris): array {
            $produk = $this->infoProduk->AmbilBanyak(array_values($baris->pluck('IdProduk')->map(fn ($i): int => (int) $i)->all()), true);

            return array_values($baris->map(fn (ProdukRak $p): array => [
                'Uuid' => $produk[$p->IdProduk]->uuid ?? '',
                'Nama' => $produk[$p->IdProduk]->nama ?? '',
                'Sku' => $produk[$p->IdProduk]->sku ?? null,
                'SimbolSatuan' => $produk[$p->IdProduk]->simbolSatuan ?? '',
            ])->all());
        });
    }
}
