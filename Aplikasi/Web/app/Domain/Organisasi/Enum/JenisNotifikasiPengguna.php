<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/** Jenis notifikasi Aplikasi Owner (OWN-03). Nilainya bagian kontrak API/FCM dan tidak boleh diganti diam-diam. */
enum JenisNotifikasiPengguna: string
{
    case Persetujuan = 'Persetujuan';
    case SelisihKas = 'SelisihKas';
    case StokKritis = 'StokKritis';
    case PerangkatOffline = 'PerangkatOffline';
    case PiutangJatuhTempo = 'PiutangJatuhTempo';
    case StokKedaluwarsa = 'StokKedaluwarsa';
}
