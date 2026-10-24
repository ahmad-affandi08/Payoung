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
    case TransferStokMasuk = 'TransferStokMasuk';
    case KuotaTransaksi = 'KuotaTransaksi';
    case PermintaanStok = 'PermintaanStok';
    case TransferStokDiterima = 'TransferStokDiterima';
    case TransferStokMacet = 'TransferStokMacet';

    /**
     * D-183: awalan kode sektor yang punya jenis notifikasi ini; null = umum. Pengaturan notifikasi tidak menawarkan
     * "barang mendekati kedaluwarsa" kepada usaha yang tidak menyimpan barang bertanggal kedaluwarsa.
     *
     * @return list<string>|null
     */
    public function AmbilSektor(): ?array
    {
        return match ($this) {
            self::StokKedaluwarsa => ['RTL-PHR', 'FNB', 'RTL-GEN', 'RTL-FRS', 'RTL-PET', 'RTL-FLR'],
            self::PesananOnline => ['FNB', 'RTL', 'WHS', 'SVC-LDR', 'SVC-GEN'],
            default => null,
        };
    }

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
            self::TransferStokMasuk => 'Kiriman stok masuk',
            self::KuotaTransaksi => 'Kuota transaksi bulan ini',
            self::PermintaanStok => 'Permintaan stok antar-outlet',
            self::TransferStokDiterima => 'Kiriman stok sudah diterima',
            self::TransferStokMacet => 'Kiriman stok belum diterima',
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
            self::TransferStokMasuk => '/kelola/persediaan/transfer',
            self::KuotaTransaksi => '/kelola/langganan',
            self::PermintaanStok => '/kelola/persediaan/permintaan',
            self::TransferStokDiterima, self::TransferStokMacet => '/kelola/persediaan/transfer',
        };
    }
}
