<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Referensi\Kueri\HariLiburTerbit;
use Illuminate\Support\Carbon;

/**
 * SLA respons pertama tiket (P-09 "SLA per paket"): jam dari `config('dukungan.SlaResponsPertamaJam')` per kode paket
 * lalu per prioritas. Satuannya jam kerja tim dukungan (`config('dukungan.JamKerja')`, Senin–Jumat 08.00–17.00 WIB,
 * hari libur terbit P-02 dilewati), kecuali tiket Mendesak pada paket berbayar yang dihitung jam kalender. Waktu mulai
 * di luar jam kerja baru dihitung sejak jam kerja berikutnya dibuka.
 */
final class PenghitungSlaTiket
{
    /** Pengaman putaran: 400 hari lebih dari cukup untuk melewati hari libur panjang. */
    private const MAKS_HARI_TELUSUR = 400;

    /** @var array<int, array<string, true>> tahun → set tanggal libur (`Y-m-d`) */
    private array $hariLibur = [];

    public function __construct(private readonly HariLiburTerbit $libur) {}

    public function HitungJam(?string $kodePaket, PrioritasTiketDukungan $prioritas): int
    {
        /** @var array<string, array<string, int>> $perPaket */
        $perPaket = (array) config('dukungan.SlaResponsPertamaJam');
        /** @var array<string, int> $bawaan */
        $bawaan = (array) config('dukungan.SlaResponsPertamaJamBawaan');
        $tabel = $kodePaket !== null && isset($perPaket[$kodePaket]) ? $perPaket[$kodePaket] : $bawaan;

        return max(1, (int) ($tabel[$prioritas->value] ?? $bawaan[$prioritas->value] ?? 18));
    }

    /** Tiket Mendesak pada paket berbayar dihitung jam kalender; selain itu jam kerja. */
    public function CekKalender(?string $kodePaket, PrioritasTiketDukungan $prioritas): bool
    {
        return $prioritas === PrioritasTiketDukungan::Mendesak
            && $kodePaket !== null
            && in_array($kodePaket, (array) config('dukungan.PaketSlaKalenderMendesak'), true);
    }

    /** Batas respons: [jam] jam kalender atau jam kerja sejak [dibuatPada]. */
    public function HitungBatas(Carbon $dibuatPada, int $jam, bool $kalender = true): Carbon
    {
        return $kalender ? $dibuatPada->copy()->addHours($jam) : $this->TambahJamKerja($dibuatPada, $jam);
    }

    /** Menambah [jam] jam kerja (hari kerja, di dalam jam buka, bukan hari libur) ke [mulai]. */
    public function TambahJamKerja(Carbon $mulai, int $jam): Carbon
    {
        $zona = (string) config('dukungan.JamKerja.ZonaWaktu');
        $selesai = (int) config('dukungan.JamKerja.Selesai');
        $sisaMenit = max(0, $jam) * 60;
        $t = $mulai->copy()->setTimezone($zona);

        for ($putaran = 0; $putaran < self::MAKS_HARI_TELUSUR; $putaran++) {
            $t = $this->GeserKeJamKerja($t);
            $tutup = $t->copy()->setTime($selesai, 0);
            $tersedia = (int) $t->diffInMinutes($tutup, true);

            if ($sisaMenit <= $tersedia) {
                return $t->addMinutes($sisaMenit)->setTimezone($mulai->getTimezone());
            }

            $sisaMenit -= $tersedia;
            $t = $t->copy()->addDay()->startOfDay();
        }

        return $mulai->copy()->addHours($jam);
    }

    /** Jam kerja terdekat pada atau setelah [waktu]. */
    private function GeserKeJamKerja(Carbon $waktu): Carbon
    {
        $mulai = (int) config('dukungan.JamKerja.Mulai');
        $selesai = (int) config('dukungan.JamKerja.Selesai');
        $t = $waktu->copy();

        for ($putaran = 0; $putaran < self::MAKS_HARI_TELUSUR; $putaran++) {
            if (! $this->CekHariKerja($t)) {
                $t = $t->addDay()->startOfDay();

                continue;
            }

            if ($t->lt($t->copy()->setTime($mulai, 0))) {
                return $t->setTime($mulai, 0);
            }

            if ($t->gte($t->copy()->setTime($selesai, 0))) {
                $t = $t->addDay()->startOfDay();

                continue;
            }

            return $t;
        }

        return $t;
    }

    private function CekHariKerja(Carbon $tanggal): bool
    {
        return in_array($tanggal->dayOfWeekIso, (array) config('dukungan.JamKerja.HariKerja'), true)
            && ! $this->CekLibur($tanggal);
    }

    private function CekLibur(Carbon $tanggal): bool
    {
        $tahun = $tanggal->year;
        $this->hariLibur[$tahun] ??= array_fill_keys(
            array_map(fn ($libur): string => $libur->Tanggal->format('Y-m-d'), $this->libur->AmbilTahun($tahun)),
            true,
        );

        return isset($this->hariLibur[$tahun][$tanggal->format('Y-m-d')]);
    }
}
