<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Tugas;

use App\Domain\Pengelola\Operasional\Kueri\TugasGagal;
use App\Domain\Pengelola\Operasional\Layanan\PencatatRiwayatJadwal;
use App\Domain\Pengelola\Operasional\Model\RiwayatJadwal;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menjalankan satu perintah terjadwal atas permintaan petugas (D-209), di antrean agar tidak menahan permintaan web.
 * Perintah sudah diperiksa terhadap jadwal oleh `JalankanJadwalSekarang`; payload hanya Id baris riwayat. Tanpa
 * percobaan ulang: perintah yang gagal tercatat gagal, petugas memutuskan apakah menjalankannya lagi.
 */
final class JalankanPerintahJadwalTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $idRiwayat) {}

    public function handle(PencatatRiwayatJadwal $pencatat): void
    {
        $baris = RiwayatJadwal::query()->find($this->idRiwayat);

        if ($baris === null || $baris->SelesaiPada !== null) {
            return;
        }

        // Bila jadwal otomatis memakai withoutOverlapping, jalan manual memakai kunci yang sama: jadwal yang jatuh tempo saat
        // ini berjalan dilewati scheduler, dan jalan manual ditolak bila jadwal otomatis sedang memegang kunci.
        $acara = self::CariAcaraJadwal($baris->Perintah);
        $memegangKunci = false;

        if ($acara !== null) {
            $memegangKunci = $acara->mutex->create($acara);

            if (! $memegangKunci) {
                $pencatat->Selesaikan($baris, false, 'Jadwal otomatis sedang menjalankan perintah ini. Coba lagi setelah selesai.');

                return;
            }
        }

        try {
            $kode = Artisan::call($baris->Perintah);
            $keluaran = TugasGagal::SaringRahasia(trim(Artisan::output()));

            $pencatat->Selesaikan(
                $baris,
                $kode === 0,
                $kode === 0 ? ($keluaran === '' ? null : Str::limit($keluaran, 250)) : "Berhenti dengan kode keluar {$kode}. ".Str::limit($keluaran, 220),
            );
        } catch (Throwable $galat) {
            $pencatat->Selesaikan($baris, false, TugasGagal::SaringRahasia($galat->getMessage()));
        } finally {
            if ($memegangKunci && $acara !== null) {
                $acara->mutex->forget($acara);
            }
        }
    }

    /** Acara jadwal ber-`withoutOverlapping` untuk perintah ini; null bila tidak ada atau jadwal belum dimuat di proses ini. */
    private static function CariAcaraJadwal(string $perintah): ?Event
    {
        foreach (app(Schedule::class)->events() as $acara) {
            if ($acara->withoutOverlapping && PencatatRiwayatJadwal::NamaPerintah($acara) === $perintah) {
                return $acara;
            }
        }

        return null;
    }
}
