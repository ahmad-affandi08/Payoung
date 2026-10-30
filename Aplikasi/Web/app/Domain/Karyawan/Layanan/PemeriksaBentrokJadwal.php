<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

/**
 * Mendeteksi shift yang saling tumpang tindih milik satu karyawan (F-18, D-251). Murni. Waktu dibaca sebagai jam dinding
 * (shift yang melewati tengah malam berakhir keesokan harinya, jadi ikut menabrak shift hari berikutnya). Shift yang
 * hanya bersentuhan (selesai 12.00, mulai 12.00) tidak dianggap bentrok.
 */
final class PemeriksaBentrokJadwal
{
    /**
     * @param  list<array{Tanggal: string, JamMulai: string, JamSelesai: string}>  $shift
     * @return array{0: array{Tanggal: string, JamMulai: string, JamSelesai: string}, 1: array{Tanggal: string, JamMulai: string, JamSelesai: string}}|null pasangan pertama yang bentrok
     */
    public static function Cari(array $shift): ?array
    {
        $diurut = [];

        foreach ($shift as $s) {
            $batas = PenilaiKehadiran::AmbilBatasShift($s['Tanggal'], $s['JamMulai'], $s['JamSelesai']);
            $diurut[] = ['Shift' => $s, 'Mulai' => $batas['Mulai']->getTimestamp(), 'Selesai' => $batas['Selesai']->getTimestamp()];
        }

        usort($diurut, fn (array $a, array $b): int => $a['Mulai'] <=> $b['Mulai'] ?: $a['Selesai'] <=> $b['Selesai']);

        for ($i = 1; $i < count($diurut); $i++) {
            if ($diurut[$i]['Mulai'] < $diurut[$i - 1]['Selesai']) {
                return [$diurut[$i - 1]['Shift'], $diurut[$i]['Shift']];
            }
        }

        return null;
    }
}
