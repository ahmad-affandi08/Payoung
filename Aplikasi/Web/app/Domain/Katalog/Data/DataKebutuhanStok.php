<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Data;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\LokasiProduksi;
use App\Domain\Katalog\Enum\PelacakanProduk;
use Brick\Math\BigDecimal;

/**
 * Satu produk berstok yang berkurang saat produk lain dijual (F-07b): produk itu sendiri (Stok/Produksi), bahan resep
 * versi terbaru (Resep), komponen paket (rekursif), atau bahan pilihan. Kebutuhan per 1 satuan dasar produk yang
 * dijual = `pembilang ÷ penyebut` dalam satuan dasar produk berstok (pecahan eksak; pemanggil membulatkan setelah
 * dikali jumlah). `jalur` = asal kebutuhan untuk pesan & tinjauan (misal "Resep", "Paket: Es Teh Manis"). `dihapus` =
 * produk berstok sudah dihapus (soft delete; hanya mungkin bila belum pernah dipakai, BR-03.2). `lokasiProduksi` (BR-05.4) =
 * lokasi stok tempat bahan resep dikurangi (produk Resep yang menurunkannya); null = lokasi Toko.
 */
final readonly class DataKebutuhanStok
{
    public function __construct(
        public int $idProduk,
        public string $uuid,
        public string $nama,
        public JenisProduk $jenis,
        public PelacakanProduk $pelacakan,
        public BigDecimal $pembilang,
        public BigDecimal $penyebut,
        public string $jalur,
        public bool $dihapus = false,
        public ?LokasiProduksi $lokasiProduksi = null,
    ) {}
}
