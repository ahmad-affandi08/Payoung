<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;

/**
 * Jumlah dokumen penjualan tenant aktif pada satu bulan kalender, dihitung langsung dari `Penjualan` (satu sumber
 * kebenaran, tanpa penghitung terpisah yang bisa melenceng). Bulan mengikuti `TanggalBisnis` dokumen. Semua status ikut
 * dihitung, termasuk `Void`: membatalkan transaksi tidak mengembalikan kuota. Retur bukan penjualan baru dan tidak
 * dihitung. Memakai indeks `(IdTenant, TanggalBisnis)`.
 */
final class PemakaianTransaksiBulanan
{
    /** Zona bulan kuota: sama dengan kuota WhatsApp dan tagihan Payoung (WIB). */
    public const ZONA = 'Asia/Jakarta';

    public function AmbilPeriode(?CarbonImmutable $pada = null): string
    {
        return ($pada ?? CarbonImmutable::now())->setTimezone(self::ZONA)->format('Y-m');
    }

    public function Hitung(string $periode): int
    {
        $awal = CarbonImmutable::parse($periode.'-01', self::ZONA)->startOfMonth();
        $akhir = $awal->endOfMonth();

        return Penjualan::query()
            ->whereBetween('TanggalBisnis', [$awal->toDateString(), $akhir->toDateString()])
            ->count();
    }
}
