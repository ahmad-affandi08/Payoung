<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use App\Domain\Pengelola\Operasional\Model\MetrikRuteApi;

/**
 * Rute API paling lambat dalam [jam] terakhir (PGL-20 lanjutan), dari `MetrikRuteApi`. Diurutkan p95 menurun (batas atas
 * bucket; bucket terakhir tanpa batas dianggap terlambat sendiri), lalu rata-rata menurun. Rute dengan permintaan di bawah
 * `operasional.MinimumPermintaanRute` tidak ikut: p95 dari beberapa permintaan hanya noise.
 */
final class RuteApiTerlambat
{
    /** Nilai pengurut untuk bucket terakhir (> 1.600 ms) yang tidak punya batas atas. */
    private const URUT_LEBIH = 1_000_000;

    /**
     * @return list<array{Kelompok: string, NamaRute: string, Jumlah: int, GalatServer: int, RataRataMs: int, P95Ms: int|null, P95Lebih: bool}>
     */
    public function Ambil(int $jam = 24, int $batas = 10): array
    {
        $kolom = ['JumlahPermintaan', 'JumlahGalatServer', 'TotalDurasiMs', ...array_keys(PencatatMetrikApi::BUCKET)];
        /** @var array<string, array<string, int>> $total */
        $total = [];

        foreach (MetrikRuteApi::query()->where('Jam', '>=', now()->subHours($jam)->startOfHour())->get() as $baris) {
            $kunci = $baris->Kelompok.'|'.$baris->NamaRute;
            $t = $total[$kunci] ?? array_fill_keys($kolom, 0);

            foreach ($kolom as $nama) {
                $t[$nama] += (int) $baris->{$nama};
            }

            $total[$kunci] = $t;
        }

        $minimum = (int) config('operasional.MinimumPermintaanRute');
        $hasil = [];

        foreach ($total as $kunci => $t) {
            $jumlah = $t['JumlahPermintaan'];

            if ($jumlah < $minimum) {
                continue;
            }

            [$kelompok, $nama] = explode('|', $kunci, 2);
            $p95 = RingkasanMetrikApi::HitungP95($t, $jumlah);
            $hasil[] = [
                'Kelompok' => $kelompok,
                'NamaRute' => $nama,
                'Jumlah' => $jumlah,
                'GalatServer' => $t['JumlahGalatServer'],
                'RataRataMs' => intdiv($t['TotalDurasiMs'] + intdiv($jumlah, 2), $jumlah),
                'P95Ms' => $p95['Ms'],
                'P95Lebih' => $p95['Lebih'],
            ];
        }

        usort($hasil, function (array $a, array $b): int {
            $urutA = $a['P95Lebih'] ? self::URUT_LEBIH : ($a['P95Ms'] ?? 0);
            $urutB = $b['P95Lebih'] ? self::URUT_LEBIH : ($b['P95Ms'] ?? 0);

            return [$urutB, $b['RataRataMs'], $b['Jumlah']] <=> [$urutA, $a['RataRataMs'], $a['Jumlah']];
        });

        return array_slice($hasil, 0, max(1, $batas));
    }
}
