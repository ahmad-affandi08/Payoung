<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Token B2B yang kita terbitkan untuk DOKU saat DOKU memanggil endpoint notifikasi kita (pengaturan "Token URL" di
 * dasbor DOKU › API Keys › Pengaturan SNAP). DOKU meminta token lebih dulu, lalu memakainya di header `Authorization`
 * pada notifikasi.
 *
 * Token ini **tidak melindungi apa pun yang penting**: notifikasi hanya memicu pertanyaan status ke DOKU, tidak pernah
 * melunasi tagihan sendiri (D-159), jadi notifikasi palsu tidak bisa menambah uang siapa pun. Karena itu permintaan
 * token tidak diverifikasi dengan kunci publik DOKU, dan notifikasi tanpa token tetap diproses (supaya perbedaan kecil
 * pada cara DOKU memanggil tidak membuat pembayaran tak terdeteksi).
 */
final class PenerbitTokenSnap
{
    private const DETIK_BERLAKU = 900;

    /**
     * @return array{responseCode: string, responseMessage: string, accessToken: string, tokenType: string, expiresIn: string}
     */
    public function Terbitkan(): array
    {
        $token = Str::random(64);
        Cache::put('snap.token_masuk.'.hash('sha256', $token), 1, self::DETIK_BERLAKU);

        return [
            'responseCode' => '2007300',
            'responseMessage' => 'Successful',
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresIn' => (string) self::DETIK_BERLAKU,
        ];
    }
}
