<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Enum;

/**
 * Nasib sisa uang muka reservasi meja yang tidak dipakai membayar tagihan: `Dikembalikan` (Dr Uang Muka Pelanggan, Cr
 * kas/bank) atau `Hangus` (Dr Uang Muka Pelanggan, Cr Pendapatan Lain), D-145.
 */
enum CaraSelesaiUangMukaMeja: string
{
    case Dikembalikan = 'Dikembalikan';
    case Hangus = 'Hangus';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Dikembalikan => 'Dikembalikan ke tamu',
            self::Hangus => 'Hangus (jadi pendapatan)',
        };
    }
}
