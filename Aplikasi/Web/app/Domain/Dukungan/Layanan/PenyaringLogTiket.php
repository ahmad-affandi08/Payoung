<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

/**
 * Menyaring log singkat yang dilampirkan aplikasi ke tiket (P-09 "log singkat"): maksimal 20 baris × 200 karakter,
 * karakter kendali dibuang, dan pola yang bisa memuat data pribadi atau rahasia (email, deretan angka panjang seperti
 * nomor HP/kartu/NIK, token dan kata sandi) diganti penanda. Aplikasi sudah menyaring log lokalnya (K-21); ini lapis
 * kedua di server karena isi kiriman klien tidak boleh dipercaya begitu saja (BR-P09.1).
 */
final class PenyaringLogTiket
{
    public const MAKS_BARIS = 20;

    public const MAKS_PANJANG = 200;

    /**
     * @param  array<mixed>  $baris
     * @return list<string>
     */
    public static function Saring(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $isi) {
            if (! is_string($isi)) {
                continue;
            }

            $rapi = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $isi));
            // Kata sandi/token/rahasia bergaya `kunci=nilai` atau `kunci: nilai`, dan token bearer.
            $rapi = (string) preg_replace('/\b(password|kata[ _-]?sandi|pin|token|secret|rahasia|api[ _-]?key|authorization)\b(\s*[=:]\s*)(?:bearer\s+)?\S+/i', '$1$2[rahasia]', $rapi);
            $rapi = (string) preg_replace('/\b(bearer)\s+[\w.\-~+\/=]+/i', '$1 [rahasia]', $rapi);
            $rapi = (string) preg_replace('/[\w.+\-]+@[\w\-]+(\.[\w\-]+)+/u', '[email]', $rapi);
            // Deretan 8+ digit (boleh dipisah spasi/titik/strip): nomor HP, kartu, NIK, rekening.
            $rapi = (string) preg_replace('/(?<![\w.])\+?\d(?:[\s.\-]?\d){7,}(?!\w)/', '[angka]', $rapi);

            if ($rapi === '') {
                continue;
            }

            $hasil[] = mb_substr($rapi, 0, self::MAKS_PANJANG);

            if (count($hasil) >= self::MAKS_BARIS) {
                break;
            }
        }

        return $hasil;
    }
}
