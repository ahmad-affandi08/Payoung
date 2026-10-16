<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;

/**
 * Satu baris isian invoice (F-21). Harga datang dari pengguna back-office (kesepakatan dengan pelanggan, bisa berbeda dari
 * harga katalog), bukan dari price engine kasir. `uuidProduk` opsional: produk Jasa/Tanpa stok dari katalog, atau baris
 * bebas yang diketik. `uuidKelompokPajak` null = tanpa pajak. `hargaTermasukPajak` null = ikut isian tingkat invoice.
 */
final readonly class DataBarisInvoice
{
    public function __construct(
        public ?string $uuidProduk,
        public string $nama,
        public ?string $keterangan,
        public JenisBarisInvoice $jenisBaris,
        public ?string $simbolSatuan,
        public Kuantitas $jumlah,
        public Uang $harga,
        public Uang $diskon,
        public ?string $uuidKelompokPajak,
        public ?bool $hargaTermasukPajak = null,
    ) {}
}
