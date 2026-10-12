<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Metode pembayaran langganan (PRD §15.3): transfer manual dengan bukti yang diverifikasi Keuangan, atau gerbang
 * pembayaran platform yang melunasi sendiri dari notifikasi webhook (BR-P08.11).
 */
enum MetodePembayaranLangganan: string
{
    case TransferManual = 'TransferManual';
    case Gateway = 'Gateway';
    /** Tagihan Rp 0 (kupon 100%): lunas otomatis tanpa uang masuk (D-112). */
    case Kupon = 'Kupon';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::TransferManual => 'Transfer manual',
            self::Gateway => 'Pembayaran online',
            self::Kupon => 'Kupon 100%',
        };
    }
}
