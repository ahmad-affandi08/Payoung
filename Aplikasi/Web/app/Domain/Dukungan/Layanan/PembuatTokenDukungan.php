<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use Illuminate\Support\Str;

/** Token tukar sesi akses dukungan: acak 48 karakter, hanya hash-nya yang disimpan (sama seperti token portal lain). */
final class PembuatTokenDukungan
{
    /** Umur token tukar: cukup untuk berpindah tab dari konsol ke back-office, tidak lebih. */
    public const DETIK_UMUR_TOKEN = 60;

    public static function Buat(): string
    {
        return Str::random(48);
    }

    public static function Hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
