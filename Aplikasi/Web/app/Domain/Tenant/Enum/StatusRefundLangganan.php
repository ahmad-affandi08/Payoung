<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status pengajuan refund tagihan langganan (P-08, BR-P08.2): Diajukan → Disetujui / Ditolak. Keduanya final.
 * Refund di atas batas (`config('tagihan.BatasRefundTanpaPersetujuan')`) diajukan Keuangan lalu diputuskan Super Admin;
 * di bawah batas, atau diajukan Super Admin sendiri (D-34), langsung Disetujui.
 */
enum StatusRefundLangganan: string
{
    case Diajukan = 'Diajukan';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }
}
