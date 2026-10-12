<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;

/**
 * X7 Open API v1 (`GET /api/v1/produk`): produk tenant aktif (termasuk diarsipkan, tanpa yang dihapus) urut `Id`,
 * halaman berbasis kursor (`setelahId`). Per satuan: konversi, barcode, dan harga dasar (daftar harga bawaan, jumlah
 * minimum terkecil). HPP dan data internal tidak dikirim. Uang & jumlah sebagai string desimal.
 */
final class ProdukUntukApiPublik
{
    /**
     * @return array{Data: list<array<string, mixed>>, IdTerakhir: int|null}
     */
    public function Daftar(int $setelahId, int $batas, ?string $uuid = null): array
    {
        $produk = Produk::query()
            ->when($uuid !== null, fn ($k) => $k->where('Uuid', $uuid), fn ($k) => $k->where('Id', '>', $setelahId))
            ->orderBy('Id')
            ->limit($batas)
            ->get();
        $idProduk = $produk->modelKeys();
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $idProduk)->orderBy('Id')->get()->groupBy('IdProduk');
        $barcode = ProdukBarcode::query()->whereIn('IdProduk', $idProduk)->orderBy('Id')->get()->groupBy('IdProdukSatuan');
        $harga = ProdukHarga::query()->whereIn('IdProduk', $idProduk)->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->get()->groupBy('IdProdukSatuan');
        $namaSatuan = Satuan::query()->pluck('Nama', 'Id');
        $kategori = Kategori::query()->whereIn('Id', $produk->pluck('IdKategori')->filter()->unique()->values()->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');

        return [
            'Data' => array_values($produk->map(fn (Produk $p): array => [
                'Uuid' => $p->Uuid,
                'Sku' => $p->Sku,
                'Nama' => $p->Nama,
                'Jenis' => $p->Jenis->value,
                'Kategori' => ($k = $kategori->get($p->IdKategori)) === null ? null : ['Uuid' => $k->Uuid, 'Nama' => $k->Nama],
                'Merek' => $p->Merek,
                'Aktif' => $p->Aktif,
                'TampilDiPos' => $p->TampilDiPos,
                'TampilOnline' => $p->TampilOnline,
                'Favorit' => $p->Favorit,
                'HargaTerbuka' => $p->HargaTerbuka,
                'Satuan' => array_values(collect($satuan->get($p->Id, []))->map(fn (ProdukSatuan $s): array => [
                    'Uuid' => $s->Uuid,
                    'Nama' => (string) $namaSatuan->get($s->IdSatuan),
                    'KonversiKeDasar' => (string) $s->KonversiKeDasar,
                    'DefaultJual' => $s->DefaultJual,
                    'Barcode' => array_values(collect($barcode->get($s->Id, []))->pluck('Barcode')->all()),
                    'HargaDasar' => ($h = collect($harga->get($s->Id, []))->first()) instanceof ProdukHarga ? (string) $h->Harga : null,
                ])->all()),
                'DiubahPada' => $p->DiubahPada?->toIso8601ZuluString(),
            ])->all()),
            'IdTerakhir' => $produk->count() < $batas ? null : $produk->last()?->Id,
        ];
    }
}
