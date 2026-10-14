<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Kanal pesan platform kepada pengguna tenant yang punya templat (PGL-07). `Notifikasi` = notifikasi aplikasi: satu
 * `NotifikasiPengguna` yang muncul di pusat notifikasi web/Aplikasi Owner dan, bila push tidak dimatikan penerimanya,
 * dikirim sebagai push dengan judul dan isi yang sama. Push dan in-app sengaja satu templat: keduanya satu baris
 * notifikasi, jadi teks yang berbeda berarti pengguna membaca dua versi dari kejadian yang sama.
 */
enum KanalKomunikasi: string
{
    case Email = 'Email';
    case WhatsApp = 'WhatsApp';
    case Notifikasi = 'Notifikasi';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Notifikasi => 'Notifikasi aplikasi',
        };
    }
}
