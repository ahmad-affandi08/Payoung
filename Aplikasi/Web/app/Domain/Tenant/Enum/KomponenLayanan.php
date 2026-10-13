<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Komponen layanan Payoung yang ditampilkan di halaman status publik dan dipilih saat mencatat insiden. */
enum KomponenLayanan: string
{
    case Kasir = 'Kasir';
    case BackOffice = 'BackOffice';
    case TokoOnline = 'TokoOnline';
    case Pembayaran = 'Pembayaran';
    case Notifikasi = 'Notifikasi';
    case AplikasiOwner = 'AplikasiOwner';
    case Situs = 'Situs';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Kasir => 'Aplikasi Kasir & sinkron data',
            self::BackOffice => 'Dashboard (back-office)',
            self::TokoOnline => 'Toko online & self-order',
            self::Pembayaran => 'Pembayaran (QRIS & langganan)',
            self::Notifikasi => 'Notifikasi WhatsApp & email',
            self::AplikasiOwner => 'Aplikasi Owner',
            self::Situs => 'Situs & pendaftaran',
        };
    }
}
