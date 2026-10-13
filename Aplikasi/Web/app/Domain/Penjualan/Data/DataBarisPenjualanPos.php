<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;

/**
 * Satu baris item outbox `Penjualan.Buat` (F-07b). `uuidProdukSatuan` null = satuan dasar. `kodePajak` null = semua
 * pajak dokumen berlaku, `[]` = baris tanpa pajak. `hargaTermasukPajak` null = ikut dokumen. F-18: [uuidKaryawan] staf
 * yang melayani baris (komisi dibagi rata; kosong = tanpa komisi).
 */
final readonly class DataBarisPenjualanPos
{
    /**
     * @param  list<array{UuidPilihan: string, Nama: string, Harga: string}>  $pilihan
     * @param  list<string>|null  $kodePajak
     * @param  list<string>  $uuidKaryawan
     * @param  list<string>  $nomorSeri  F-05h: nomor seri/IMEI yang dijual (produk `Pelacakan=Seri`), satu per unit
     */
    public function __construct(
        public string $uuid,
        public string $uuidProduk,
        public ?string $uuidProdukSatuan,
        public Kuantitas $jumlah,
        public Uang $hargaSatuan,
        public Uang $hargaPilihan,
        public array $pilihan,
        public ?bool $hargaTermasukPajak,
        public ?array $kodePajak,
        public ?DataDiskonManual $diskonManual,
        public ?string $catatan,
        public array $uuidKaryawan = [],
        public array $nomorSeri = [],
        // Apotek (§9.5): baris ini ditutup resep di blok `Resep`; null = perangkat tidak menandai (semua baris wajib resep).
        public ?bool $denganResep = null,
        // Apotek bagian 3: obat racikan (komponen dikurangi dari stok; baris = produk Jasa racik).
        public ?DataRacikanPenjualanPos $racikan = null,
        // Price engine lapis 1: harga baris diubah kasir (izin `pos.harga.timpa` atau PIN penyetuju); null = harga price engine.
        public ?DataHargaManual $hargaManual = null,
    ) {}
}
