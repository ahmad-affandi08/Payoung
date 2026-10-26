<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\AnalitikSitus\Data;

use Carbon\CarbonImmutable;

/**
 * Rentang tanggal laporan situs (D-215): tanggal GA4 memakai zona waktu properti, jadi yang dikirim hanya tanggal
 * (Y-m-d). Bawaan 28 hari terakhir sampai kemarin, seperti Google Analytics. Maksimum 365 hari; tanggal depan dipotong
 * ke hari ini. Rentang pembanding = rentang yang sama panjang tepat sebelumnya.
 */
final readonly class RentangAnalitik
{
    public const MAKS_HARI = 365;

    public const HARI_BAWAAN = 28;

    private const ZONA = 'Asia/Jakarta';

    private function __construct(public CarbonImmutable $dari, public CarbonImmutable $sampai) {}

    public static function Bangun(mixed $dari, mixed $sampai, ?CarbonImmutable $sekarang = null): self
    {
        $hariIni = ($sekarang ?? CarbonImmutable::now(self::ZONA))->setTimezone(self::ZONA)->startOfDay();
        $batasAkhir = $sampai === null || $sampai === '' ? $hariIni->subDay() : (self::BacaTanggal($sampai) ?? $hariIni->subDay());

        if ($batasAkhir->greaterThan($hariIni)) {
            $batasAkhir = $hariIni;
        }

        $awal = self::BacaTanggal($dari) ?? $batasAkhir->subDays(self::HARI_BAWAAN - 1);

        if ($awal->greaterThan($batasAkhir)) {
            [$awal, $batasAkhir] = [$batasAkhir, $awal];
        }

        $batasAwal = $batasAkhir->subDays(self::MAKS_HARI - 1);

        if ($awal->lessThan($batasAwal)) {
            $awal = $batasAwal;
        }

        return new self($awal, $batasAkhir);
    }

    public function HitungHari(): int
    {
        return (int) $this->dari->diffInDays($this->sampai) + 1;
    }

    public function Sebelumnya(): self
    {
        $hari = $this->HitungHari();

        return new self($this->dari->subDays($hari), $this->dari->subDay());
    }

    /** @return array{startDate: string, endDate: string} */
    public function UntukGoogle(): array
    {
        return ['startDate' => $this->dari->toDateString(), 'endDate' => $this->sampai->toDateString()];
    }

    /** @return array{Dari: string, Sampai: string, Hari: int} */
    public function UntukTampilan(): array
    {
        return ['Dari' => $this->dari->toDateString(), 'Sampai' => $this->sampai->toDateString(), 'Hari' => $this->HitungHari()];
    }

    private static function BacaTanggal(mixed $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) !== 1) {
            return null;
        }

        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $nilai, self::ZONA);

        return $tanggal instanceof CarbonImmutable && $tanggal->toDateString() === $nilai ? $tanggal : null;
    }
}
