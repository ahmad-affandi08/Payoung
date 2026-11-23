<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;

/**
 * Kueri publik D-310: apakah satu stasiun dapur masih dirujuk kategori atau produk (termasuk yang diarsipkan).
 * Dipakai domain Organisasi sebelum menjadikan stasiun sebagai checker (salinan).
 */
final class StasiunDapurDipakai
{
    public function CekDipakai(int $idStasiun): bool
    {
        return Kategori::query()->where('IdStasiunDapur', $idStasiun)->exists()
            || Produk::query()->withTrashed()->where('IdStasiunDapur', $idStasiun)->exists();
    }
}
