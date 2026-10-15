<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap\Enum;

/**
 * Cara bayar tagihan langganan lewat DOKU SNAP (D-159): halaman bayar milik Payoung sendiri, DOKU hanya memproses.
 * Kartu kredit tidak ada di sini (SNAP tidak mencakupnya); itu tetap DOKU Checkout.
 */
enum MetodeBayarSnap: string
{
    case Qris = 'Qris';
    case VirtualAccount = 'VirtualAccount';
    case Dana = 'Dana';
    case ShopeePay = 'ShopeePay';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Qris => 'QRIS',
            self::VirtualAccount => 'Virtual account',
            self::Dana => 'DANA',
            self::ShopeePay => 'ShopeePay',
        };
    }

    /** Bentuk tampilan di halaman bayar: gambar QR, nomor untuk disalin, atau pengalihan ke aplikasi dompet digital. */
    public function AmbilTampilan(): string
    {
        return match ($this) {
            self::Qris => 'Qr',
            self::VirtualAccount => 'NomorVa',
            self::Dana, self::ShopeePay => 'Alihkan',
        };
    }

    /** Kode `additionalInfo.channel` untuk e-wallet (VA dan QRIS tidak memakainya dari sini). */
    public function AmbilKodeKanalDompet(): ?string
    {
        return match ($this) {
            self::Dana => 'EMONEY_DANA_SNAP',
            self::ShopeePay => 'EMONEY_SHOPEE_PAY_SNAP',
            default => null,
        };
    }
}
