<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;

/**
 * Nilai lama/baru produk untuk `LogAudit` (`produk.buat`, `produk.ubah`, dst.): kolom produk, satuan, dan barcode.
 */
final class RingkasanAuditProduk
{
    /**
     * @return array<string, mixed>
     */
    public static function Ambil(Produk $produk): array
    {
        return [
            'Sku' => $produk->Sku,
            'Nama' => $produk->Nama,
            'NamaStruk' => $produk->NamaStruk,
            'Jenis' => $produk->Jenis->value,
            'IdKategori' => $produk->IdKategori,
            'Merek' => $produk->Merek,
            'IdSatuanDasar' => $produk->IdSatuanDasar,
            'Pelacakan' => $produk->Pelacakan->value,
            'IdKelompokPajak' => $produk->IdKelompokPajak,
            'HargaTermasukPajak' => $produk->HargaTermasukPajak,
            'BolehMinus' => $produk->BolehMinus,
            'TampilDiPos' => $produk->TampilDiPos,
            'TampilOnline' => $produk->TampilOnline,
            'Favorit' => $produk->Favorit,
            'HargaTerbuka' => $produk->HargaTerbuka,
            'GolonganObat' => $produk->GolonganObat?->value,
            'ObatWajibApotek' => $produk->ObatWajibApotek,
            'Prekursor' => $produk->Prekursor,
            'AtributVarian' => $produk->AtributVarian,
            'Satuan' => ProdukSatuan::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get()
                ->map(fn (ProdukSatuan $s): array => ['IdSatuan' => $s->IdSatuan, 'KonversiKeDasar' => $s->KonversiKeDasar, 'Barcode' => $s->Barcode()->orderBy('Id')->pluck('Barcode')->all()])
                ->all(),
        ];
    }
}
