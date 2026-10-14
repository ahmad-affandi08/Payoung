<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Model\MetrikGalatSinkron;

/**
 * Penolakan sinkron terbanyak dalam [jam] terakhir per jenis item dan kode galat (PGL-20 lanjutan), dari
 * `MetrikGalatSinkron`. Menunjukkan apa yang paling sering membuat kasir gagal mengirim (mis. `Penjualan.Buat` /
 * `StokTidakCukup`) tanpa membuka data tenant mana pun.
 */
final class GalatSinkronTerbanyak
{
    /**
     * @return list<array{JenisItem: string, KodeGalat: string, Jumlah: int}>
     */
    public function Ambil(int $jam = 24, int $batas = 10): array
    {
        /** @var array<string, array{JenisItem: string, KodeGalat: string, Jumlah: int}> $total */
        $total = [];

        foreach (MetrikGalatSinkron::query()->where('Jam', '>=', now()->subHours($jam)->startOfHour())->get() as $baris) {
            $kunci = $baris->JenisItem.'|'.$baris->KodeGalat;
            $ada = $total[$kunci] ?? ['JenisItem' => $baris->JenisItem, 'KodeGalat' => $baris->KodeGalat, 'Jumlah' => 0];
            $ada['Jumlah'] += $baris->Jumlah;
            $total[$kunci] = $ada;
        }

        $hasil = array_values($total);
        usort($hasil, fn (array $a, array $b): int => [$b['Jumlah'], $a['JenisItem'], $a['KodeGalat']] <=> [$a['Jumlah'], $b['JenisItem'], $b['KodeGalat']]);

        return array_slice($hasil, 0, max(1, $batas));
    }
}
