<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Layanan;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use Illuminate\Support\Facades\URL;

/**
 * Tautan berhenti menerima kampanye email prospek (D-216): `/berhenti-prospek/{kontak}` bertanda tangan relatif, jadi
 * tidak bisa ditebak atau diubah dan tidak memuat alamat email. Dipasang di badan email dan di header `List-Unsubscribe`
 * (satu klik, RFC 8058) supaya Gmail dan Yahoo menampilkan tombol "Berhenti berlangganan" sendiri.
 */
final class TautanBerhentiProspek
{
    public const NAMA_RUTE = 'publik.berhenti-prospek';

    public const POLA = '[0-9A-HJKMNP-TV-Z]{26}';

    public static function Buat(KontakProspek $kontak): string
    {
        return AlamatDomain::BuatUrlAbsolutTenant(URL::signedRoute(self::NAMA_RUTE, ['kontak' => strtoupper($kontak->Uuid)], null, false));
    }
}
