<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use Carbon\CarbonImmutable;

/**
 * Menghitung kekurangan staf (F-18, D-251). Murni. Untuk tiap kebutuhan (hari dalam seminggu + rentang jam + minimal staf)
 * pada tiap tanggal, dicari jumlah staf terjadwal paling sedikit di sepanjang rentang jam itu. Shift malam hari
 * sebelumnya yang melewati tengah malam ikut dihitung. Karyawan cuti/izin tidak dihitung (disaring pemanggil). Satu
 * karyawan dihitung sekali pada satu saat meski punya dua baris yang bersinggungan.
 */
final class PenghitungKekuranganStaf
{
    /**
     * @param  list<array{HariKe: int, JamMulai: string, JamSelesai: string, Minimal: int}>  $kebutuhan
     * @param  array<string, list<array{IdKaryawan: int, JamMulai: string, JamSelesai: string}>>  $shiftPerTanggal  tanggal `YYYY-MM-DD` → shift terjadwal
     * @param  list<string>  $hari
     * @return list<array{Tanggal: string, JamMulai: string, JamSelesai: string, Minimal: int, Terjadwal: int, Kurang: int}>
     */
    public static function Hitung(array $kebutuhan, array $shiftPerTanggal, array $hari): array
    {
        $hasil = [];

        foreach ($hari as $tanggal) {
            $hariKe = CarbonImmutable::parse($tanggal, 'UTC')->dayOfWeekIso;
            $sebelum = CarbonImmutable::parse($tanggal, 'UTC')->subDay()->toDateString();
            $interval = [];

            foreach ($shiftPerTanggal[$tanggal] ?? [] as $s) {
                $mulai = self::Menit($s['JamMulai']);
                $selesai = self::Menit($s['JamSelesai']);
                $interval[] = [$s['IdKaryawan'], $mulai, $selesai <= $mulai ? $selesai + 1440 : $selesai];
            }

            foreach ($shiftPerTanggal[$sebelum] ?? [] as $s) {
                $mulai = self::Menit($s['JamMulai']);
                $selesai = self::Menit($s['JamSelesai']);

                if ($selesai <= $mulai) {
                    $interval[] = [$s['IdKaryawan'], $mulai - 1440, $selesai];
                }
            }

            foreach ($kebutuhan as $k) {
                if ($k['HariKe'] !== $hariKe || $k['Minimal'] < 1) {
                    continue;
                }

                $awal = self::Menit($k['JamMulai']);
                $akhir = self::Menit($k['JamSelesai']);

                if ($akhir <= $awal) {
                    continue;
                }

                $titik = [$awal];

                foreach ($interval as [, $m, $s]) {
                    foreach ([$m, $s] as $t) {
                        if ($t > $awal && $t < $akhir) {
                            $titik[] = $t;
                        }
                    }
                }

                $titik = array_values(array_unique($titik));
                $paling = PHP_INT_MAX;

                foreach ($titik as $t) {
                    $orang = [];

                    foreach ($interval as [$id, $m, $s]) {
                        if ($m <= $t && $t < $s) {
                            $orang[$id] = true;
                        }
                    }

                    $paling = min($paling, count($orang));
                }

                if ($paling < $k['Minimal']) {
                    $hasil[] = ['Tanggal' => $tanggal, 'JamMulai' => $k['JamMulai'], 'JamSelesai' => $k['JamSelesai'], 'Minimal' => $k['Minimal'], 'Terjadwal' => $paling, 'Kurang' => $k['Minimal'] - $paling];
                }
            }
        }

        return $hasil;
    }

    private static function Menit(string $jam): int
    {
        return ((int) substr($jam, 0, 2)) * 60 + (int) substr($jam, 3, 2);
    }
}
