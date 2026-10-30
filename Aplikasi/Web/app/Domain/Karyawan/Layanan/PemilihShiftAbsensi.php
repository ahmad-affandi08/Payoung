<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Karyawan\Model\JadwalKerja;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Menentukan shift mana yang dimaksud sebuah absen masuk bila karyawan punya lebih dari satu shift pada tanggal itu
 * (F-18, D-251, shift terpisah seperti 08.00-12.00 dan 13.00-17.00). Satu shift saja = shift itu (perilaku lama).
 * Beberapa shift: yang jam mulainya paling dekat dengan jam masuk di antara yang belum berakhir; bila semuanya sudah
 * berakhir, yang berakhir paling akhir. Murni, jam masuk dalam waktu dinding outlet.
 */
final class PemilihShiftAbsensi
{
    /**
     * @param  Collection<int, JadwalKerja>  $jadwalHari  shift satu karyawan pada satu tanggal
     */
    public static function Pilih(Collection $jadwalHari, CarbonImmutable $masukLokal): ?JadwalKerja
    {
        if ($jadwalHari->count() <= 1) {
            return $jadwalHari->first();
        }

        $tanggal = $masukLokal->toDateString();
        $terpilih = null;
        $selisihTerkecil = PHP_INT_MAX;
        $berakhirTerakhir = null;
        $akhirTerakhir = PHP_INT_MIN;

        foreach ($jadwalHari as $j) {
            $batas = PenilaiKehadiran::AmbilBatasShift($tanggal, $j->JamMulai, $j->JamSelesai);

            if ($batas['Selesai']->getTimestamp() > $akhirTerakhir) {
                $akhirTerakhir = $batas['Selesai']->getTimestamp();
                $berakhirTerakhir = $j;
            }

            if ($batas['Selesai']->greaterThan($masukLokal)) {
                $selisih = abs($batas['Mulai']->getTimestamp() - $masukLokal->getTimestamp());

                if ($selisih < $selisihTerkecil) {
                    $selisihTerkecil = $selisih;
                    $terpilih = $j;
                }
            }
        }

        return $terpilih ?? $berakhirTerakhir;
    }
}
