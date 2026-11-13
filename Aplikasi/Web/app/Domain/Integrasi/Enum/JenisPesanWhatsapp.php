<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

/**
 * Jenis pesan WhatsApp otomatis yang bisa dinyalakan tenant dari Pusat WhatsApp (D-299). Teks pesan tetap bawaan
 * (sama dengan Majoo), tenant hanya memilih jenis mana yang menyala. Pesan lama (struk, piutang, reservasi, status
 * pesanan online, laundry) punya sakelar sendiri di modulnya dan tidak termasuk di sini.
 */
enum JenisPesanWhatsapp: string
{
    case PoinBertambah = 'PoinBertambah';
    case PoinDitukar = 'PoinDitukar';
    case DepositDiisi = 'DepositDiisi';
    case DepositDipakai = 'DepositDipakai';
    case LaporanTutupKasir = 'LaporanTutupKasir';
    case LaporanPenjualanPerJam = 'LaporanPenjualanPerJam';
    case LaporanAbsensi = 'LaporanAbsensi';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::PoinBertambah => 'Poin bertambah',
            self::PoinDitukar => 'Poin ditukar',
            self::DepositDiisi => 'Deposit diisi',
            self::DepositDipakai => 'Deposit dipakai',
            self::LaporanTutupKasir => 'Laporan tutup kasir',
            self::LaporanPenjualanPerJam => 'Penjualan per jam',
            self::LaporanAbsensi => 'Absensi karyawan',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::PoinBertambah => 'Pelanggan diberi tahu poin yang baru didapat dan totalnya.',
            self::PoinDitukar => 'Pelanggan diberi tahu poin yang ditukar dan sisanya.',
            self::DepositDiisi => 'Pelanggan diberi tahu deposit yang masuk dan saldonya.',
            self::DepositDipakai => 'Pelanggan diberi tahu deposit yang terpakai dan sisanya.',
            self::LaporanTutupKasir => 'Ringkasan penjualan dan selisih kas saat shift ditutup.',
            self::LaporanPenjualanPerJam => 'Total penjualan hari ini, dikirim tiap jam selama outlet buka.',
            self::LaporanAbsensi => 'Kabar saat karyawan absen masuk atau pulang.',
        };
    }

    /** `Pelanggan` atau `Karyawan` (pengelompokan di Pusat WhatsApp dan nomor pengirim yang dipakai). */
    public function AmbilKelompok(): string
    {
        return match ($this) {
            self::PoinBertambah, self::PoinDitukar, self::DepositDiisi, self::DepositDipakai => 'Pelanggan',
            default => 'Karyawan',
        };
    }

    public function AmbilFungsi(): FungsiNomorWhatsapp
    {
        return $this->AmbilKelompok() === 'Pelanggan' ? FungsiNomorWhatsapp::Pelanggan : FungsiNomorWhatsapp::Karyawan;
    }

    /** Pesan baru tidak dikirim sampai tenant menyalakannya, supaya pelanggan tidak kebanjiran pesan tak terduga. */
    public function BawaanAktif(): bool
    {
        return false;
    }
}
