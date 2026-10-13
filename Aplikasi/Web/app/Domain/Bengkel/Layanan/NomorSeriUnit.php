<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Layanan;

/**
 * IMEI / nomor seri perangkat yang diservis (D-142): huruf besar, spasi dirapikan, 3-40 karakter dari huruf, angka,
 * spasi, titik, garis miring, dan tanda hubung. Kosong = tidak dicatat (null); isi tak sah = `false` supaya pemanggil
 * bisa membedakan "tidak diisi" dari "salah ketik".
 */
final class NomorSeriUnit
{
    public static function Normalisasi(?string $masukan): string|false|null
    {
        $teks = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $masukan)));

        if ($teks === '') {
            return null;
        }

        return preg_match('/^[A-Z0-9 .\/-]{3,40}$/', $teks) === 1 ? $teks : false;
    }
}
