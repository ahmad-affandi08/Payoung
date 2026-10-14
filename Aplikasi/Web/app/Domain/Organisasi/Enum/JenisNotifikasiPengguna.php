<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/** Jenis notifikasi Aplikasi Owner (OWN-03). Nilainya bagian kontrak API/FCM dan tidak boleh diganti diam-diam. */
enum JenisNotifikasiPengguna: string
{
    case Persetujuan = 'Persetujuan';
    case SelisihKas = 'SelisihKas';
    case StokKritis = 'StokKritis';
    case PerangkatOffline = 'PerangkatOffline';
    case PiutangJatuhTempo = 'PiutangJatuhTempo';
    case StokKedaluwarsa = 'StokKedaluwarsa';
    case TransaksiBesar = 'TransaksiBesar';
    case PesananOnline = 'PesananOnline';
    case PesanPlatform = 'PesanPlatform';

    /** Label untuk pusat notifikasi web dan pengaturannya. */
    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Persetujuan => 'Permintaan persetujuan',
            self::SelisihKas => 'Shift dan kas perlu dicek',
            self::StokKritis => 'Stok menipis',
            self::PerangkatOffline => 'Perangkat kasir tidak aktif',
            self::PiutangJatuhTempo => 'Piutang lewat jatuh tempo',
            self::StokKedaluwarsa => 'Barang mendekati kedaluwarsa',
            self::TransaksiBesar => 'Void, retur, atau diskon besar',
            self::PesananOnline => 'Pesanan online masuk',
            self::PesanPlatform => 'Pesan dari Payoung (tagihan dan tiket dukungan)',
        };
    }

    /** Persetujuan menunggu keputusan orang yang berwenang, jadi tidak boleh dibisukan. */
    public function BisaDibisukan(): bool
    {
        return $this !== self::Persetujuan;
    }

    /** Halaman web tujuan bila notifikasi lama tidak membawa tautan sendiri; null = tidak ada halaman web. */
    public function AmbilTautanBawaan(): ?string
    {
        return match ($this) {
            self::Persetujuan => null,
            self::SelisihKas => '/kelola/kasir/shift?saring[PerluTinjauan]=Ya',
            self::StokKritis => '/kelola/laporan/stok?tab=kritis',
            self::PerangkatOffline => '/kelola/perangkat',
            self::PiutangJatuhTempo => '/kelola/piutang',
            self::StokKedaluwarsa => '/kelola/laporan/stok?tab=kedaluwarsa',
            self::TransaksiBesar => '/kelola/laporan/penjualan?tab=anti-fraud',
            self::PesananOnline => '/kelola/toko-online',
            self::PesanPlatform => null,
        };
    }
}
