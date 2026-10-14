<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use App\Domain\Pengelola\Operasional\Model\MetrikApi;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Error rate dan p95 API per kelompok dalam [menit] terakhir (P-11, PGL-20), dari `MetrikApi`.
 *
 * p95 dibaca dari histogram latensi: batas atas bucket tempat persentil ke-95 jatuh (`Hingga400Ms` = "paling lama 400 ms").
 * Bucket terakhir (> 1.600 ms) tidak punya batas atas, jadi p95 dilaporkan null dengan `P95Lebih` = true. Menit berjalan
 * belum disiram ke tabel, jadi angka terlambat sampai satu menit. Persentase berupa string desimal dua angka (`"3.00"`).
 */
final class RingkasanMetrikApi
{
    /**
     * @return list<array{Kelompok: string, Jumlah: int, GalatServer: int, ErrorRatePersen: string, RataRataMs: int|null, P95Ms: int|null, P95Lebih: bool}>
     */
    public function Ambil(int $menit): array
    {
        $dari = now()->subMinutes($menit)->startOfMinute();
        $kolom = ['JumlahPermintaan', 'JumlahGalatServer', 'TotalDurasiMs', ...array_keys(PencatatMetrikApi::BUCKET)];
        /** @var array<string, array<string, int>> $total */
        $total = [];

        foreach (MetrikApi::query()->where('Menit', '>=', $dari)->get() as $baris) {
            $t = $total[$baris->Kelompok] ?? array_fill_keys($kolom, 0);

            foreach ($kolom as $nama) {
                $t[$nama] += (int) $baris->{$nama};
            }

            $total[$baris->Kelompok] = $t;
        }

        $hasil = [];

        foreach (PencatatMetrikApi::KELOMPOK as $kelompok) {
            $t = $total[$kelompok] ?? array_fill_keys($kolom, 0);
            $jumlah = $t['JumlahPermintaan'];
            $p95 = $jumlah === 0 ? ['Ms' => null, 'Lebih' => false] : self::HitungP95($t, $jumlah);

            $hasil[] = [
                'Kelompok' => $kelompok,
                'Jumlah' => $jumlah,
                'GalatServer' => $t['JumlahGalatServer'],
                'ErrorRatePersen' => self::HitungPersen($t['JumlahGalatServer'], $jumlah),
                'RataRataMs' => $jumlah === 0 ? null : intdiv($t['TotalDurasiMs'] + intdiv($jumlah, 2), $jumlah),
                'P95Ms' => $p95['Ms'],
                'P95Lebih' => $p95['Lebih'],
            ];
        }

        return $hasil;
    }

    /** Persentase dua desimal dibulatkan biasa; tanpa permintaan = `"0.00"`. */
    public static function HitungPersen(int $bagian, int $jumlah): string
    {
        return $jumlah === 0
            ? '0.00'
            : (string) BigDecimal::of($bagian)->multipliedBy(100)->dividedBy($jumlah, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, int>  $t
     * @return array{Ms: int|null, Lebih: bool}
     */
    public static function HitungP95(array $t, int $jumlah): array
    {
        // Permintaan ke-95 persen dari bawah: ceil(0,95 × jumlah) dihitung dengan bilangan bulat.
        $target = intdiv($jumlah * 95 + 99, 100);
        $kumulatif = 0;

        foreach (PencatatMetrikApi::BUCKET as $kolom => $batas) {
            $kumulatif += $t[$kolom];

            if ($kumulatif >= $target) {
                return ['Ms' => $batas, 'Lebih' => $batas === null];
            }
        }

        return ['Ms' => null, 'Lebih' => true];
    }
}
