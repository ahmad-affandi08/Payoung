<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;

/**
 * Satu baris retur grosir: baris surat jalan mana (Urutan), berapa yang dikembalikan, dan dalam kondisi apa.
 * Harga & HPP tidak dikirim klien — keduanya disalin dari snapshot baris surat jalan. Produk bernomor seri wajib menyebut
 * nomor seri yang dikembalikan (`nomorSeri`, dari yang diserahkan di surat jalan itu); batch dikembalikan ke batch asal
 * secara terbalik dari alokasi penyerahan.
 */
final readonly class DataBarisReturGrosir
{
    public function __construct(
        public int $urutanSuratJalan,
        public Kuantitas $jumlah,
        public KondisiBarangRetur $kondisi = KondisiBarangRetur::LayakJual,
        /** @var list<string> */
        public array $nomorSeri = [],
    ) {}
}
