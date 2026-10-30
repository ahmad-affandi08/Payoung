<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Layanan\PengirimJadwalMingguan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * F-18 (D-251): kirim jadwal mingguan satu outlet ke karyawan lewat WhatsApp. Payload hanya id dan tanggal, tidak ada
 * nomor telepon. Kunci menahan dua pengumuman bersamaan untuk outlet dan minggu yang sama.
 */
final class KirimJadwalMingguanTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idOutlet,
        public readonly string $senin,
    ) {}

    public function handle(KonteksTenant $konteks, PengirimJadwalMingguan $pengirim): void
    {
        $kunci = Cache::lock("kirim:jadwal-mingguan:{$this->idOutlet}:{$this->senin}", 300);

        if (! $kunci->get()) {
            return;
        }

        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $pengirim->Kirim($this->idTenant, $this->idOutlet, CarbonImmutable::parse($this->senin, 'UTC'));
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
            $kunci->release();
        }
    }
}
