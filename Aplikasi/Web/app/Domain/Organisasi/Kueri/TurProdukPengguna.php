<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Model\TurProduk;

/** PLT-12: kode tur yang sudah diselesaikan atau dilewati satu pengguna di tenant aktif. */
final class TurProdukPengguna
{
    /** @return list<string> */
    public function KodeSudahDilihat(int $idPengguna): array
    {
        return array_values(array_map('strval', TurProduk::query()->where('IdPengguna', $idPengguna)->orderBy('Kode')->pluck('Kode')->all()));
    }
}
