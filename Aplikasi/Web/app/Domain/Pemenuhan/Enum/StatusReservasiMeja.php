<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Enum;

/**
 * Status reservasi meja (FnB, §9.1, D-145): `Menunggu` → `Dikonfirmasi` → `Hadir` (tamu duduk) → `Selesai` (dibayar di
 * kasir). `Dibatalkan` dan `TidakDatang` berakhir; `TidakDatang` hanya setelah jam mulai lewat. Meja terpakai selama
 * Menunggu/Dikonfirmasi/Hadir.
 */
enum StatusReservasiMeja: string
{
    case Menunggu = 'Menunggu';
    case Dikonfirmasi = 'Dikonfirmasi';
    case Hadir = 'Hadir';
    case Selesai = 'Selesai';
    case Dibatalkan = 'Dibatalkan';
    case TidakDatang = 'TidakDatang';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return match ($this) {
            self::Menunggu => in_array($tujuan, [self::Dikonfirmasi, self::Hadir, self::Dibatalkan], true),
            self::Dikonfirmasi => in_array($tujuan, [self::Hadir, self::Dibatalkan, self::TidakDatang], true),
            self::Hadir => in_array($tujuan, [self::Selesai, self::Dibatalkan], true),
            self::Selesai, self::Dibatalkan, self::TidakDatang => false,
        };
    }

    public function CekMemakaiMeja(): bool
    {
        return in_array($this, [self::Menunggu, self::Dikonfirmasi, self::Hadir], true);
    }

    /** Boleh mengubah isi (waktu, meja, tamu): sebelum tamu duduk. */
    public function CekBolehDiubah(): bool
    {
        return in_array($this, [self::Menunggu, self::Dikonfirmasi], true);
    }

    /** @return list<string> */
    public static function AmbilNilaiMemakaiMeja(): array
    {
        return [self::Menunggu->value, self::Dikonfirmasi->value, self::Hadir->value];
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu konfirmasi',
            self::Dikonfirmasi => 'Dikonfirmasi',
            self::Hadir => 'Sudah datang',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
            self::TidakDatang => 'Tidak datang',
        };
    }
}
