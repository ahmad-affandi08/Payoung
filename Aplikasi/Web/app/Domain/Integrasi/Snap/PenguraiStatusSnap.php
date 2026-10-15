<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use Brick\Math\BigDecimal;

/**
 * Mengubah jawaban status DOKU SNAP menjadi `StatusPembayaranGerbang`. Logika murni tanpa jaringan supaya aturannya
 * bisa diuji satu per satu. **Aturan keselamatan: hanya bukti lunas yang tegas yang melunasi.** Jawaban yang tidak
 * dikenali dianggap `Menunggu` (tidak ada uang yang dianggap masuk), bukan lunas.
 *
 * - QRIS & e-wallet: `latestTransactionStatus` `00` = sukses; `05` dibatalkan dan `06` gagal = `Gagal`; lainnya menunggu.
 * - Virtual account: lunas bila `paymentFlagStatus` `00`, atau (bila kolom itu tidak ada) `paymentFlagReason` berisi
 *   kata sukses/lunas/dibayar. Jawaban "Pending" dengan `paidAmount` terisi (contoh di dokumen DOKU) **tidak** melunasi.
 */
final class PenguraiStatusSnap
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function UraiDompetAtauQris(array $data): StatusPembayaranGerbang
    {
        $kode = self::Teks($data['latestTransactionStatus'] ?? null);

        return match ($kode) {
            '00' => StatusPembayaranGerbang::Lunas,
            '05', '06' => StatusPembayaranGerbang::Gagal,
            default => StatusPembayaranGerbang::Menunggu,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function UraiVirtualAccount(array $data): StatusPembayaranGerbang
    {
        $va = is_array($data['virtualAccountData'] ?? null) ? $data['virtualAccountData'] : [];
        $bendera = self::Teks($va['paymentFlagStatus'] ?? null);

        if ($bendera !== '') {
            return $bendera === '00' ? StatusPembayaranGerbang::Lunas : StatusPembayaranGerbang::Menunggu;
        }

        $alasan = is_array($va['paymentFlagReason'] ?? null) ? $va['paymentFlagReason'] : [];
        $teks = mb_strtolower(self::Teks($alasan['english'] ?? null).' '.self::Teks($alasan['indonesia'] ?? null));

        return preg_match('/\b(success|successful|paid|lunas|berhasil|dibayar|sukses)\b/u', $teks) === 1
            ? StatusPembayaranGerbang::Lunas
            : StatusPembayaranGerbang::Menunggu;
    }

    /**
     * Jumlah (string desimal) yang DOKU nyatakan dibayar, atau '' bila tidak ada. Dipakai pelunas untuk menolak
     * pelunasan otomatis bila berbeda dari total tagihan.
     *
     * @param  array<string, mixed>  $data
     */
    public static function AmbilJumlah(array $data): string
    {
        foreach ([
            $data['virtualAccountData']['paidAmount']['value'] ?? null,
            $data['amount']['value'] ?? null,
            $data['transAmount']['value'] ?? null,
        ] as $nilai) {
            $teks = self::Teks($nilai);

            if ($teks !== '' && is_numeric($teks) && BigDecimal::of($teks)->isPositive()) {
                return $teks;
            }
        }

        return '';
    }

    private static function Teks(mixed $nilai): string
    {
        return is_string($nilai) || is_int($nilai) || is_float($nilai) ? trim((string) $nilai) : '';
    }
}
