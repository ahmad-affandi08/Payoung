<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

/**
 * Kode gift card (CRM-06, D-153): 12 karakter dari 31 simbol tanpa yang mudah tertukar (0/O, 1/I/L), ditulis
 * `XXXX-XXXX-XXXX`. Kode adalah rahasia pemegang kartu, jadi dibuat dari `random_int` (±59 bit) dan tidak berurutan.
 * `Normalisasi` menerima huruf kecil, spasi, dan tanda hubung hasil ketikan kasir; `Samarkan` menampilkan hanya empat
 * karakter terakhir untuk daftar, audit, dan struk.
 */
final class KodeGiftCard
{
    private const HURUF = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function Buat(): string
    {
        $kode = '';

        for ($i = 0; $i < 12; $i++) {
            $kode .= self::HURUF[random_int(0, strlen(self::HURUF) - 1)];
        }

        return self::Format($kode);
    }

    /** Kode baku `XXXX-XXXX-XXXX`, atau string kosong bila bukan kode yang mungkin (panjang atau simbol salah). */
    public static function Normalisasi(string $masukan): string
    {
        $bersih = strtoupper((string) preg_replace('/[\s\-]+/', '', $masukan));

        if (strlen($bersih) !== 12 || strspn($bersih, self::HURUF) !== 12) {
            return '';
        }

        return self::Format($bersih);
    }

    public static function Samarkan(string $kode): string
    {
        return 'GC-••••-••••-'.substr($kode, -4);
    }

    private static function Format(string $kode12): string
    {
        return implode('-', str_split($kode12, 4));
    }
}
