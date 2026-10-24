<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Model\Outlet;

/**
 * Id outlet kanvas tenant aktif (kendaraan salesman). Muat dan bongkar kendaraan adalah transfer harian yang rutin,
 * jadi domain lain memakai daftar ini untuk tidak membanjiri notifikasi dengan kabar transfer ke/dari kendaraan.
 */
final class DaftarIdOutletKanvas
{
    /**
     * @return list<int>
     */
    public function Ambil(): array
    {
        return Outlet::query()->where('Kanvas', true)->pluck('Id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }
}
