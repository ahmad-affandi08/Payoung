<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Karyawan\Aksi\SelaraskanTanggalAbsensi;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use Illuminate\Console\Command;

/** Menyesuaikan tanggal absensi lama ke tanggal kalender waktu outlet di semua tenant (D-230, idempoten). */
final class SelaraskanTanggalAbsensiPerintah extends Command
{
    protected $signature = 'karyawan:selaraskan-tanggal-absensi';

    protected $description = 'Menyesuaikan tanggal absensi lama (yang mengikuti jam tutup buku) ke tanggal kalender waktu outlet.';

    public function handle(KeanggotaanPengguna $keanggotaan, SelaraskanTanggalAbsensi $selaraskan): int
    {
        $daftar = $keanggotaan->AmbilSemuaIdTenant();
        $diperbaiki = 0;

        foreach ($daftar as $idTenant) {
            $diperbaiki += $selaraskan->Jalankan($idTenant);
        }

        $this->info(count($daftar)." tenant diperiksa, {$diperbaiki} absensi disesuaikan tanggalnya.");

        return self::SUCCESS;
    }
}
