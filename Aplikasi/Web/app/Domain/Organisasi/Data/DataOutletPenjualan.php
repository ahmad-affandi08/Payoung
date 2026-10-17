<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

/**
 * Outlet & perangkat pengirim penjualan POS untuk domain lain (F-07b): kode untuk validasi nomor BR-07.1, kota untuk
 * tarif pajak daerah, zona waktu & jam tutup buku (tanggal bisnis), identitas untuk `data-awal`, dan lokasi stok Toko pertama outlet (null bila tidak
 * ada) sebagai sumber mutasi stok penjualan.
 */
final readonly class DataOutletPenjualan
{
    public function __construct(
        public int $idOutlet,
        public string $uuidOutlet,
        public string $kodeOutlet,
        public string $namaOutlet,
        public ?string $alamat,
        public ?string $telepon,
        public ?string $kodeKota,
        public string $zonaWaktu,
        public string $jamTutupBuku,
        public int $idPerangkat,
        public string $uuidPerangkat,
        public string $kodePerangkat,
        public ?int $idGudangToko,
        /** Nama merek outlet: nama usaha yang tampil di struk bila pengaturan struk tidak menimpanya. */
        public ?string $namaMerek = null,
        public ?int $idMerek = null,
    ) {}
}
