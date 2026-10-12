<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Layanan;

/**
 * Batas baris ekspor laporan berbasis tabel (D-124). Ekspor langsung (diunduh saat itu juga) tetap dibatasi 5.000 baris
 * supaya permintaan web tidak menggantung; ekspor besar yang disiapkan di latar belakang (Pusat Unduhan) boleh jauh
 * lebih banyak. Instans "scoped": mode latar belakang hanya berlaku di proses penyiapan, tidak terbawa antar-request.
 */
final class BatasEksporLaporan
{
    public const MAKS_LANGSUNG = 5000;

    private bool $latarBelakang = false;

    public function AktifkanLatarBelakang(): void
    {
        $this->latarBelakang = true;
    }

    public function NonaktifkanLatarBelakang(): void
    {
        $this->latarBelakang = false;
    }

    public function CekLatarBelakang(): bool
    {
        return $this->latarBelakang;
    }

    public function Maks(): int
    {
        return $this->latarBelakang ? max(self::MAKS_LANGSUNG, (int) config('tenant.MaksBarisEksporBesar')) : self::MAKS_LANGSUNG;
    }
}
