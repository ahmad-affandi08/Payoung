<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Organisasi\Enum\KodeTurProduk;
use App\Domain\Organisasi\Model\TurProduk;

/** PLT-12: menghapus catatan sebuah tur supaya ditawarkan lagi kepada pengguna itu ("Ulangi tur"). */
final class UlangiTurProduk
{
    public function Jalankan(int $idPengguna, KodeTurProduk $kode): void
    {
        TurProduk::query()->where('IdPengguna', $idPengguna)->where('Kode', $kode->value)->delete();
    }
}
