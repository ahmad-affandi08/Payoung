<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use RuntimeException;

/**
 * Pasangan kunci RSA 2048 untuk token B2B DOKU SNAP (D-159). Kunci pribadi dibuat di server dan tidak pernah keluar dari
 * penyimpanan kredensial terenkripsi; hanya kunci publiknya yang ditampilkan untuk didaftarkan di dasbor DOKU
 * (API Keys › Merchant Public Key). Dibuat server supaya operator tidak perlu menjalankan openssl sendiri.
 */
final class PembuatKunciSnap
{
    /**
     * @return array{Pribadi: string, Publik: string}
     */
    public function Buat(): array
    {
        $kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($kunci === false || ! openssl_pkey_export($kunci, $pribadi)) {
            throw new RuntimeException('Pasangan kunci RSA tidak bisa dibuat: ekstensi OpenSSL server bermasalah.');
        }

        $rincian = openssl_pkey_get_details($kunci);

        if ($rincian === false || ! is_string($rincian['key'] ?? null)) {
            throw new RuntimeException('Kunci publik RSA tidak terbaca.');
        }

        return ['Pribadi' => $pribadi, 'Publik' => trim($rincian['key'])];
    }

    /** Sidik jari SHA-256 kunci publik, untuk dicatat di audit tanpa menyimpan kuncinya. */
    public static function BuatSidikJari(string $kunciPublik): string
    {
        return hash('sha256', $kunciPublik);
    }
}
