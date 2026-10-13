<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/**
 * Kanal masuk tiket (P-09): tombol Bantuan di back-office, Aplikasi Kasir, dan Aplikasi Owner (konteks otomatis);
 * email dan WhatsApp ditambahkan bersama flow-nya.
 */
enum KanalTiketDukungan: string
{
    case BackOffice = 'BackOffice';
    case AplikasiKasir = 'AplikasiKasir';
    case AplikasiPemilik = 'AplikasiPemilik';
    case Email = 'Email';
    case WhatsApp = 'WhatsApp';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::BackOffice => 'Back-office',
            self::AplikasiKasir => 'Aplikasi kasir',
            self::AplikasiPemilik => 'Aplikasi Owner',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
