<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/** Hasil sebuah tur produk bagi satu pengguna (PLT-12). */
enum StatusTurProduk: string
{
    case Selesai = 'Selesai';
    case Dilewati = 'Dilewati';
}
