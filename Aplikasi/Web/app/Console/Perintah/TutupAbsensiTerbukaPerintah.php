<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Layanan\PenutupAbsensiOtomatis;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * F-18 (D-250): tiap 15 menit menutup absensi yang lupa absen keluar sesuai jam selesai jadwal, untuk tenant yang
 * mengaktifkannya (bawaan aktif). Hanya tenant yang punya absensi terbuka yang dikunjungi. Kegagalan satu tenant tidak
 * menghentikan tenant lain.
 */
final class TutupAbsensiTerbukaPerintah extends Command
{
    protected $signature = 'karyawan:tutup-absensi-terbuka {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Menutup absensi yang lupa absen keluar sesuai jam selesai jadwal agar jam kerja tidak menyambung ke hari berikutnya (F-18, D-250).';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksPengelola $pengelola, KonteksTenant $konteks, PenutupAbsensiOtomatis $penutup): int
    {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $sekarang = CarbonImmutable::now('UTC');
        $daftar = $diminta === []
            ? $pengelola->IdTenantDenganPekerjaan(Absensi::class, fn ($kueri) => $kueri->whereNull('KeluarPada')->where('MasukPada', '<', $sekarang))
            : array_values(array_intersect(array_unique($diminta), $keanggotaan->AmbilSemuaIdTenant()));
        $sebelumnya = $konteks->Ambil();
        $ditutup = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $ditutup += $penutup->TutupYangLewat(CarbonImmutable::now('UTC'));
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$ditutup} absensi ditutup otomatis, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
