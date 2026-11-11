<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Penangan;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Harga\Aksi\TerapkanHargaOtomatis;
use App\Domain\Pembelian\Peristiwa\PenerimaanBarangDicatat;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * D-266: begitu penerimaan barang (atau belanja stok) tercatat, harga jual produk-produknya diperiksa terhadap aturan
 * harga otomatis. Efek non-kritis: antrean, setelah commit (aturan #10), membawa `IdTenant`. Hitungannya selalu dari
 * harga beli terakhir yang tersimpan, jadi penangan yang diulang atau terlambat tidak menggandakan perubahan.
 */
final class TerapkanHargaOtomatisSetelahPenerimaan implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly TerapkanHargaOtomatis $terapkan,
    ) {}

    public function handle(PenerimaanBarangDicatat $peristiwa): void
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($peristiwa->idTenant);

        try {
            $this->terapkan->Jalankan($peristiwa->idProduk, 'Penerimaan:'.$peristiwa->idPenerimaanBarang);
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }
}
