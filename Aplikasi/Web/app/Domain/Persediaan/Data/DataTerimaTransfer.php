<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;

/**
 * Penerimaan satu baris transfer (`urutan`) pada satu kali penerimaan (F-05b, D-105), dalam satuan dasar. Tiap
 * barang boleh dibagi tiga: `jumlah` diterima masuk ke lokasi tujuan, `jumlahRusak` diterima tetapi rusak (dicatat
 * sebagai susut, bukan stok), `jumlahDitolak` dikembalikan ke lokasi asal. Ketiganya >= 0, minimal satu > 0, dan
 * jumlah ketiganya <= sisa dalam perjalanan. Penerimaan lama yang hanya mengisi `jumlah` tetap berlaku.
 */
final readonly class DataTerimaTransfer
{
    public function __construct(
        public int $urutan,
        public Kuantitas $jumlah,
        public ?Kuantitas $jumlahRusak = null,
        public ?Kuantitas $jumlahDitolak = null,
    ) {}

    public function AmbilRusak(): Kuantitas
    {
        return $this->jumlahRusak ?? Kuantitas::Nol();
    }

    public function AmbilDitolak(): Kuantitas
    {
        return $this->jumlahDitolak ?? Kuantitas::Nol();
    }
}
