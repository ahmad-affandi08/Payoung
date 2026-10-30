<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Aksi\TerapkanPolaJadwal;
use App\Domain\Karyawan\Model\PolaJadwalKerja;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * F-18 (D-251): tiap hari mengisi jadwal dua minggu ke depan dari pola jadwal berulang karyawan. Hanya karyawan yang
 * minggu itu belum punya jadwal sama sekali yang diisi, jadi hari yang sengaja dikosongkan pengelola tidak terisi lagi.
 * Hanya tenant yang punya pola yang dikunjungi, dan kegagalan satu tenant tidak menghentikan yang lain.
 */
final class TerapkanPolaJadwalPerintah extends Command
{
    protected $signature = 'karyawan:terapkan-pola-jadwal {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Mengisi jadwal dua minggu ke depan dari pola jadwal berulang karyawan (F-18, D-251).';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksPengelola $pengelola, KonteksTenant $konteks, TerapkanPolaJadwal $terapkan, TanggalBisnisOutlet $tanggalBisnis): int
    {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $daftar = $diminta === []
            ? $pengelola->IdTenantDenganPekerjaan(PolaJadwalKerja::class, fn ($kueri) => $kueri)
            : array_values(array_intersect(array_unique($diminta), $keanggotaan->AmbilSemuaIdTenant()));
        $sebelumnya = $konteks->Ambil();
        $terisi = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    foreach (PolaJadwalKerja::query()->distinct()->pluck('IdOutlet') as $idOutlet) {
                        $senin = CarbonImmutable::parse($tanggalBisnis->HitungKalender((int) $idOutlet)->toDateString(), 'UTC')->startOfWeek(CarbonImmutable::MONDAY);

                        foreach ([$senin, $senin->addWeek(), $senin->addWeeks(2)] as $minggu) {
                            $terisi += $terapkan->Jalankan((int) $idOutlet, $minggu, null, true);
                        }
                    }
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$terisi} hari terisi dari pola, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
