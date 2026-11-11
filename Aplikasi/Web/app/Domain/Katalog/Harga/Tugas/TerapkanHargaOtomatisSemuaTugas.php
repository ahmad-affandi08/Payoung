<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Harga\Aksi\TerapkanHargaOtomatis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Terapkan sekarang" dari back-office (D-266): menjalankan aturan harga otomatis untuk semua produk berstok dari harga
 * beli terakhir masing-masing. Berjalan di antrean karena katalog bisa ribuan produk. Hasilnya dikabarkan lewat
 * notifikasi. `$kunciPutaran` unik per permintaan, jadi tugas yang diulang tidak menggandakan kabar.
 */
final class TerapkanHargaOtomatisSemuaTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $idTenant,
        public readonly string $kunciPutaran,
        public readonly int $idPengguna,
    ) {}

    public function handle(KonteksTenant $konteks, TerapkanHargaOtomatis $terapkan): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $terapkan->JalankanUntukSemua($this->kunciPutaran, $this->idPengguna);
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
