<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Aturan protokol DOKU (non-SNAP) yang dipakai bersama oleh dua jalur yang akunnya berbeda: Checkout untuk tagihan langganan milik Payoung
 * (`GerbangBillingPlatform`, akun platform), pendaftaran merchant, dan penguji kredensial. QRIS toko memakai DOKU SNAP
 * (`AdaptorDokuSnap`, D-314), bukan protokol ini.
 *
 * Tanda tangan: `HMACSHA256=` + base64(HMAC-SHA256(kunci rahasia,
 * "Client-Id:..\nRequest-Id:..\nRequest-Timestamp:..\nRequest-Target:..[\nDigest:base64(sha256(badan))]")).
 * Permintaan keluar dan notifikasi masuk memakai komponen yang sama; `Digest` hanya ada bila ada badan.
 */
final class ProtokolDoku
{
    public const URL_SANDBOX = 'https://api-sandbox.doku.com';

    public const URL_PRODUKSI = 'https://api.doku.com';

    public static function AmbilAlamatDasar(bool $sandbox): string
    {
        return $sandbox ? self::URL_SANDBOX : self::URL_PRODUKSI;
    }

    /**
     * Header permintaan keluar. `$isi` = badan JSON persis seperti yang dikirim (null untuk GET).
     *
     * @return array{'Client-Id': string, 'Request-Id': string, 'Request-Timestamp': string, Signature: string}
     */
    public static function BuatHeader(string $idKlien, string $kunciRahasia, string $target, ?string $isi): array
    {
        $idPermintaan = (string) Str::uuid();
        $waktu = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'Client-Id' => $idKlien,
            'Request-Id' => $idPermintaan,
            'Request-Timestamp' => $waktu,
            'Signature' => self::Tandatangani($idKlien, $idPermintaan, $waktu, $target, $isi, $kunciRahasia),
        ];
    }

    public static function Tandatangani(string $idKlien, string $idPermintaan, string $waktu, string $target, ?string $isi, string $kunciRahasia): string
    {
        $komponen = "Client-Id:{$idKlien}\nRequest-Id:{$idPermintaan}\nRequest-Timestamp:{$waktu}\nRequest-Target:{$target}";

        if ($isi !== null) {
            $komponen .= "\nDigest:".base64_encode(hash('sha256', $isi, true));
        }

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, $kunciRahasia, true));
    }

    /**
     * Notifikasi masuk sah bila `Client-Id`-nya milik akun ini dan tanda tangannya cocok untuk badan mentah serta
     * path yang sedang diminta (`Request-Target` = path webhook kita, sebagaimana DOKU menandatanganinya).
     */
    public static function CekNotifikasiSah(Request $permintaan, string $idKlien, string $kunciRahasia): bool
    {
        $harapan = self::Tandatangani(
            (string) $permintaan->header('Client-Id'),
            (string) $permintaan->header('Request-Id'),
            (string) $permintaan->header('Request-Timestamp'),
            '/'.ltrim($permintaan->path(), '/'),
            $permintaan->getContent(),
            $kunciRahasia,
        );

        return $permintaan->header('Client-Id') === $idKlien && hash_equals($harapan, (string) $permintaan->header('Signature'));
    }

    public static function PetakanStatus(string $status): StatusPembayaranGerbang
    {
        return match ($status) {
            'SUCCESS' => StatusPembayaranGerbang::Lunas,
            'EXPIRED' => StatusPembayaranGerbang::Kedaluwarsa,
            'FAILED' => StatusPembayaranGerbang::Gagal,
            default => StatusPembayaranGerbang::Menunggu,
        };
    }
}
