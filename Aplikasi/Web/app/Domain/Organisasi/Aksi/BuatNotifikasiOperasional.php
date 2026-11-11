<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Kueri\PerangkatBermasalah;
use App\Domain\Organisasi\Model\TenantPengguna;
use Carbon\CarbonImmutable;

/**
 * Membuat ringkasan harian untuk kondisi operasional penting yang masih terbuka. Penerimanya semua anggota aktif tenant
 * yang berizin melihat kondisi itu (F31): pusat notifikasi web membaca baris yang sama; push FCM hanya sampai ke anggota
 * yang punya pemasangan Aplikasi Owner aktif.
 */
final class BuatNotifikasiOperasional
{
    public function __construct(
        private readonly KonteksTindakanPengguna $konteks,
        private readonly KotakTindakan $kotak,
        private readonly PerangkatBermasalah $perangkat,
        private readonly BuatNotifikasiPengguna $buat,
    ) {}

    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        $anggota = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->pluck('IdPengguna');
        $dibuat = 0;

        foreach ($anggota as $id) {
            $konteks = $this->konteks->Buat($idTenant, (int) $id, $hariIni);
            foreach ($this->kotak->Ambil($konteks) as $butir) {
                $jenis = match ($butir->kunci) {
                    'shift.tinjauan' => JenisNotifikasiPengguna::SelisihKas,
                    'stok.kritis' => JenisNotifikasiPengguna::StokKritis,
                    'piutang.lewat-jatuh-tempo' => JenisNotifikasiPengguna::PiutangJatuhTempo,
                    // P-04: kuota transaksi per bulan hampir atau sudah habis (pengingat harian selama masih berlaku).
                    'kuota.transaksi' => JenisNotifikasiPengguna::KuotaTransaksi,
                    default => null,
                };

                if ($jenis !== null) {
                    $dibuat += (int) ($this->buat->Jalankan(
                        (int) $id,
                        $jenis,
                        'Operasional:'.$jenis->value.':'.$hariIni->toDateString(),
                        $butir->judul,
                        $butir->jumlah.' butir. '.$butir->keterangan,
                        ['Tautan' => 'notifikasi', 'Href' => $butir->tautan],
                    )?->wasRecentlyCreated === true);
                }
            }

            if ($konteks->CekIzin(IzinTenant::PerangkatLihat->value)) {
                // Hanya perangkat di outlet yang sedang buka (D-269), jadi perangkat yang dimatikan saat toko tutup tidak dikabarkan.
                $jumlah = count($this->perangkat->AmbilTidakTerhubung($konteks->idOutletBoleh));
                if ($jumlah > 0) {
                    $dibuat += (int) ($this->buat->Jalankan(
                        (int) $id,
                        JenisNotifikasiPengguna::PerangkatOffline,
                        'Operasional:PerangkatOffline:'.$hariIni->toDateString(),
                        'Perangkat kasir tidak terhubung',
                        $jumlah.' perangkat tidak terhubung ke server lebih dari '.PerangkatBermasalah::MENIT_TIDAK_TERHUBUNG.' menit saat outlet buka. Kasir masih bisa berjualan offline, transaksinya masuk setelah perangkat online.',
                        ['Tautan' => 'perangkat', 'Href' => '/kelola/perangkat'],
                    )?->wasRecentlyCreated === true);
                }

                // Antrean kirim yang macet: transaksi sudah ada di kasir tetapi belum sampai ke server. Berlaku walau outlet tutup.
                $macet = count($this->perangkat->AmbilAntreanMacet($konteks->idOutletBoleh));
                if ($macet > 0) {
                    $dibuat += (int) ($this->buat->Jalankan(
                        (int) $id,
                        JenisNotifikasiPengguna::AntreanKasirMacet,
                        'Operasional:AntreanKasirMacet:'.$hariIni->toDateString(),
                        'Data kasir belum terkirim',
                        $macet.' perangkat menyimpan transaksi yang belum sampai ke server lebih dari '.PerangkatBermasalah::BatasMacetJam().' jam. Buka aplikasi Kasir di perangkat itu dan pastikan internetnya hidup.',
                        ['Tautan' => 'perangkat', 'Href' => '/kelola/perangkat'],
                    )?->wasRecentlyCreated === true);
                }
            }
        }

        return $dibuat;
    }
}
