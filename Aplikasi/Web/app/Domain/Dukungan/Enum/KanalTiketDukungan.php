<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/**
 * Kanal masuk tiket (P-09): tombol Bantuan di back-office, Aplikasi Kasir, dan Aplikasi Owner (konteks otomatis);
 * email dan WhatsApp ditambahkan bersama flow-nya. `Obrolan` (PLT-12, D-163) = obrolan langsung di lembar Bantuan:
 * tiket biasa yang percakapannya ditampilkan waktu nyata (polling) saat petugas siap, dan tetap menjadi tiket asinkron
 * (SLA, email, eskalasi) bila tidak ada petugas.
 */
enum KanalTiketDukungan: string
{
    case BackOffice = 'BackOffice';
    case AplikasiKasir = 'AplikasiKasir';
    case AplikasiPemilik = 'AplikasiPemilik';
    case Email = 'Email';
    case WhatsApp = 'WhatsApp';
    case Obrolan = 'Obrolan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::BackOffice => 'Back-office',
            self::AplikasiKasir => 'Aplikasi kasir',
            self::AplikasiPemilik => 'Aplikasi Owner',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Obrolan => 'Obrolan langsung',
        };
    }
}
