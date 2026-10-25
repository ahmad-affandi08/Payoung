<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Layanan;

use App\Domain\Pengelola\Operasional\Model\RiwayatJadwal;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mencatat jalannya perintah terjadwal ke `RiwayatJadwal` (D-209). Pendengar peristiwa scheduler Laravel; kegagalan
 * mencatat tidak boleh mengganggu tugas yang sedang dijadwalkan, jadi semua galat di sini ditelan.
 *
 * Perintah yang berjalan tiap menit (`queue:work`, `schedule:*`) tidak dicatat agar tabel tidak membengkak. Tiap perintah
 * hanya menyimpan `BATAS_PER_PERINTAH` jalan terakhir.
 */
final class PencatatRiwayatJadwal
{
    public const BATAS_PER_PERINTAH = 30;

    /** Perintah yang tidak dicatat riwayatnya. */
    private const DIABAIKAN = ['queue:work', 'schedule:'];

    public function SaatMulai(ScheduledTaskStarting $peristiwa): void
    {
        $this->Aman(function () use ($peristiwa): void {
            $perintah = self::NamaPerintah($peristiwa->task);

            if ($perintah === null) {
                return;
            }

            RiwayatJadwal::query()->create([
                'Perintah' => $perintah,
                'Sumber' => RiwayatJadwal::SUMBER_JADWAL,
                'MulaiPada' => Carbon::now(),
            ]);
        });
    }

    public function SaatSelesai(ScheduledTaskFinished|ScheduledBackgroundTaskFinished $peristiwa): void
    {
        $kode = $peristiwa->task->exitCode;

        $this->Aman(fn () => $this->Tutup($peristiwa->task, $kode === null || (int) $kode === 0, $kode === null || (int) $kode === 0 ? null : "Berhenti dengan kode keluar {$kode}."));
    }

    public function SaatGagal(ScheduledTaskFailed $peristiwa): void
    {
        $this->Aman(fn () => $this->Tutup($peristiwa->task, false, Str::limit($peristiwa->exception->getMessage(), 280)));
    }

    /**
     * Nama perintah artisan dari teks perintah acara (`'php' 'artisan' nama:perintah --opsi`); null bila bukan artisan
     * atau termasuk yang diabaikan.
     */
    public static function NamaPerintah(Event $acara): ?string
    {
        $teks = (string) $acara->command;

        if (preg_match('/artisan[\'"]?\s+(\S.*)$/', $teks, $cocok) !== 1) {
            return null;
        }

        $perintah = trim($cocok[1]);

        foreach (self::DIABAIKAN as $awalan) {
            if (str_starts_with($perintah, $awalan)) {
                return null;
            }
        }

        return $perintah;
    }

    private function Tutup(Event $acara, bool $berhasil, ?string $keterangan): void
    {
        $perintah = self::NamaPerintah($acara);

        if ($perintah === null) {
            return;
        }

        $baris = RiwayatJadwal::query()
            ->where('Perintah', $perintah)
            ->where('Sumber', RiwayatJadwal::SUMBER_JADWAL)
            ->whereNull('SelesaiPada')
            ->orderByDesc('Id')
            ->first();

        if ($baris === null) {
            return;
        }

        $this->Selesaikan($baris, $berhasil, $keterangan);
    }

    /** Menutup satu baris riwayat dan memangkas yang lama. Dipakai juga oleh jalan manual. */
    public function Selesaikan(RiwayatJadwal $baris, bool $berhasil, ?string $keterangan): void
    {
        $selesai = Carbon::now();
        $baris->forceFill([
            'SelesaiPada' => $selesai,
            'Berhasil' => $berhasil,
            'DurasiMs' => (int) max(0, $baris->MulaiPada->diffInMilliseconds($selesai, true)),
            'Keterangan' => $keterangan === null ? null : Str::limit($keterangan, 290),
        ])->save();

        $batas = RiwayatJadwal::query()
            ->where('Perintah', $baris->Perintah)
            ->orderByDesc('Id')
            ->skip(self::BATAS_PER_PERINTAH)
            ->value('Id');

        if (is_int($batas)) {
            RiwayatJadwal::query()->where('Perintah', $baris->Perintah)->where('Id', '<=', $batas)->delete();
        }
    }

    private function Aman(callable $kerja): void
    {
        try {
            $kerja();
        } catch (Throwable $galat) {
            report($galat);
        }
    }
}
