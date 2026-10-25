<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/** Hasil kirim ke satu penerima. `Diproses` menjaga satu pesan tidak terkirim dua kali bila proses terputus (D-210). */
enum StatusPenerimaSiaran: string
{
    case Menunggu = 'Menunggu';
    case Diproses = 'Diproses';
    case Terkirim = 'Terkirim';
    case Gagal = 'Gagal';
    case Dilewati = 'Dilewati';
}
