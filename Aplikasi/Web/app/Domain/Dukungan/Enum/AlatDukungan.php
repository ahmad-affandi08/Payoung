<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/**
 * Alat bantu dukungan di konsol (PGL-16 bagian 2, P-09, BR-P09.3). `Mengubah()` menentukan cakupan izin yang
 * dibutuhkan: alat yang mengubah data hanya jalan di bawah izin "Baca & ubah" (BR-P09.2); alat baca cukup "Baca saja".
 */
enum AlatDukungan: string
{
    case StatusPerangkat = 'StatusPerangkat';
    case CabutPerangkat = 'CabutPerangkat';
    case MintaLogPerangkat = 'MintaLogPerangkat';
    case LihatLogPerangkat = 'LihatLogPerangkat';
    case BangunUlangSaldoStok = 'BangunUlangSaldoStok';
    case BangunUlangRingkasanHarian = 'BangunUlangRingkasanHarian';
    case KirimUlangPesanWhatsapp = 'KirimUlangPesanWhatsapp';
    case KirimUlangVerifikasiEmail = 'KirimUlangVerifikasiEmail';
    case DaftarKirimUlang = 'DaftarKirimUlang';

    public function Mengubah(): bool
    {
        return ! in_array($this, [self::StatusPerangkat, self::LihatLogPerangkat, self::DaftarKirimUlang], true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::StatusPerangkat => 'Status perangkat & antrean kirim',
            self::CabutPerangkat => 'Cabut perangkat',
            self::MintaLogPerangkat => 'Minta log perangkat',
            self::LihatLogPerangkat => 'Baca log perangkat',
            self::BangunUlangSaldoStok => 'Bangun ulang saldo stok',
            self::BangunUlangRingkasanHarian => 'Bangun ulang ringkasan harian',
            self::KirimUlangPesanWhatsapp => 'Kirim ulang pesan WhatsApp',
            self::KirimUlangVerifikasiEmail => 'Kirim ulang email verifikasi',
            self::DaftarKirimUlang => 'Daftar pesan gagal & email belum terverifikasi',
        };
    }
}
