<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiKedaluwarsa;
use App\Domain\Organisasi\Aksi\BuatNotifikasiOperasional;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Illuminate\Console\Command;
use Throwable;

final class BuatNotifikasiOperasionalPerintah extends Command
{
    protected $signature = 'tindakan:buat-notifikasi-operasional {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Membuat push kondisi operasional penting untuk Aplikasi Owner.';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksTenant $konteks, TanggalBisnisOutlet $tanggal, BuatNotifikasiOperasional $buat, BuatNotifikasiKedaluwarsa $kedaluwarsa): int
    {
        $diminta = array_values(array_filter(array_map('intval', (array) $this->option('tenant'))));
        $semua = $keanggotaan->AmbilSemuaIdTenant();
        $daftar = $diminta === [] ? $semua : array_values(array_intersect(array_unique($diminta), $semua));
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);
                try {
                    $hariIni = $tanggal->Hitung(null);
                    $jumlah += $buat->Jalankan($idTenant, $hariIni);
                    // F23: peringatan kedaluwarsa batch H-30/H-7/lewat, satu kali per batch per tingkat.
                    $jumlah += $kedaluwarsa->Jalankan($idTenant, $hariIni);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line($jumlah.' notifikasi baru dibuat.');

        return self::SUCCESS;
    }
}
