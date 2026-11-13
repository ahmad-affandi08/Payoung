<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

/** Fungsi sebuah nomor pengirim WhatsApp (D-299): pesan apa yang boleh keluar lewat nomor itu. */
enum FungsiNomorWhatsapp: string
{
    case Umum = 'Umum';
    case Pelanggan = 'Pelanggan';
    case Karyawan = 'Karyawan';
    case Broadcast = 'Broadcast';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Umum => 'Semua pesan',
            self::Pelanggan => 'Pesan ke pelanggan',
            self::Karyawan => 'Laporan ke karyawan',
            self::Broadcast => 'Broadcast',
        };
    }

    /** Nomor ini boleh dipakai untuk kebutuhan [kebutuhan]? Nomor Umum melayani semuanya. */
    public function Melayani(self $kebutuhan): bool
    {
        return $this === self::Umum || $this === $kebutuhan;
    }
}
