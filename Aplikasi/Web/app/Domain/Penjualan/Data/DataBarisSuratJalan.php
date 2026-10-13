<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;

/**
 * Satu baris yang diserahkan: baris SO mana (Urutan) dan berapa banyak yang benar-benar dikirim. Produk bernomor seri
 * wajib menyebut nomor seri tiap unit (`nomorSeri`, satu per unit dasar); produk ber-batch dialokasikan FEFO oleh
 * server sehingga tidak perlu diisi.
 */
final readonly class DataBarisSuratJalan
{
    public function __construct(
        public int $urutanPesanan,
        public Kuantitas $jumlah,
        /** @var list<string> */
        public array $nomorSeri = [],
    ) {}
}
