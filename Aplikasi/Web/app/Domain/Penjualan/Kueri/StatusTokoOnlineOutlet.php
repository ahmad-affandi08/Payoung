<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;

/**
 * F-17: apakah toko online melayani satu outlet — pengaturan tenant aktif **dan** sakelar outletnya hidup. Dipakai
 * data awal POS supaya aplikasi kasir tidak menampilkan menu Pesanan toko online di outlet yang tidak melayaninya.
 */
final class StatusTokoOnlineOutlet
{
    public function __construct(private readonly OutletPenjualan $outlet) {}

    public function CekAktif(?int $idOutlet): bool
    {
        if ($idOutlet === null || PengaturanTokoOnline::query()->value('Aktif') !== true) {
            return false;
        }

        return $this->outlet->CekTokoOnlineAktif($idOutlet);
    }
}
