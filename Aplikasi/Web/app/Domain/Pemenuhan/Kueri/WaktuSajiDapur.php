<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Kueri;

use App\Domain\Pemenuhan\Model\TiketDapur;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Waktu saji dapur untuk laporan (§10.10 "waktu saji KDS"): dari tiket dapur yang sudah `Siap` pada periode (tanggal
 * lokal outlet saat tiket dikirim), per stasiun. Tunggu = dikirim → mulai dimasak (hanya bila KDS menandai Dimasak),
 * Masak = mulai (atau dikirim bila tidak ditandai) → siap, Total = dikirim → siap, Sajikan = siap → disajikan.
 * Rata-rata, median, persentil ke-90, dan jumlah tiket lewat target (menit). Satuan detik. Tiket yang belum siap tidak
 * dihitung. Dibaca paling banyak `BATAS_TIKET` tiket terbaru (periode ≤ 92 hari).
 */
final class WaktuSajiDapur
{
    public const BATAS_TIKET = 50000;

    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet tenant
     * @param  array<int, int>  $offsetDetik  Id outlet → selisih detik dari UTC
     * @return list<array{IdStasiun: int, JumlahTiket: int, TungguRataRata: int|null, MasakRataRata: int|null, TotalRataRata: int, TotalMedian: int, TotalP90: int, TotalTerlama: int, SajikanRataRata: int|null, LewatTarget: int}>
     */
    public function PerStasiun(string $dari, string $sampai, ?array $idOutlet, array $offsetDetik, int $targetMenit, int $offsetBawaan = 25200): array
    {
        if ($idOutlet === []) {
            return [];
        }

        $baris = TiketDapur::query()
            ->whereNotNull('SiapPada')
            ->whereBetween('DikirimPada', [(new DateTimeImmutable($dari, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s'), (new DateTimeImmutable($sampai, new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d H:i:s')])
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->orderByDesc('DikirimPada')
            ->limit(self::BATAS_TIKET)
            ->toBase()
            ->get(['IdOutlet', 'IdStasiunDapur', 'DikirimPada', 'MulaiPada', 'SiapPada', 'DisajikanPada']);
        $kelompok = [];

        foreach ($baris as $b) {
            $kirim = new DateTimeImmutable((string) $b->DikirimPada, new DateTimeZone('UTC'));
            $tanggal = $kirim->modify(sprintf('%+d seconds', $offsetDetik[(int) $b->IdOutlet] ?? $offsetBawaan))->format('Y-m-d');

            if ($tanggal < $dari || $tanggal > $sampai) {
                continue;
            }

            $siap = new DateTimeImmutable((string) $b->SiapPada, new DateTimeZone('UTC'));
            $mulai = $b->MulaiPada === null ? null : new DateTimeImmutable((string) $b->MulaiPada, new DateTimeZone('UTC'));
            $sajikan = $b->DisajikanPada === null ? null : new DateTimeImmutable((string) $b->DisajikanPada, new DateTimeZone('UTC'));
            $kelompok[(int) $b->IdStasiunDapur][] = [
                'Tunggu' => $mulai === null ? null : max(0, $mulai->getTimestamp() - $kirim->getTimestamp()),
                'Masak' => max(0, $siap->getTimestamp() - ($mulai ?? $kirim)->getTimestamp()),
                'Total' => max(0, $siap->getTimestamp() - $kirim->getTimestamp()),
                'Sajikan' => $sajikan === null ? null : max(0, $sajikan->getTimestamp() - $siap->getTimestamp()),
            ];
        }

        $hasil = [];

        foreach ($kelompok as $idStasiun => $tiket) {
            $total = array_column($tiket, 'Total');
            sort($total);
            $hasil[] = [
                'IdStasiun' => $idStasiun,
                'JumlahTiket' => count($tiket),
                'TungguRataRata' => self::RataRata(array_column($tiket, 'Tunggu')),
                'MasakRataRata' => self::RataRata(array_column($tiket, 'Masak')),
                'TotalRataRata' => self::RataRata($total) ?? 0,
                'TotalMedian' => self::Persentil($total, 50),
                'TotalP90' => self::Persentil($total, 90),
                'TotalTerlama' => (int) end($total),
                'SajikanRataRata' => self::RataRata(array_column($tiket, 'Sajikan')),
                'LewatTarget' => count(array_filter($total, fn (int $d): bool => $d > $targetMenit * 60)),
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<int, int|null>  $nilai
     */
    private static function RataRata(array $nilai): ?int
    {
        $ada = array_values(array_filter($nilai, fn (?int $n): bool => $n !== null));

        return $ada === [] ? null : intdiv(array_sum($ada) + intdiv(count($ada), 2), count($ada));
    }

    /**
     * Persentil nearest-rank dari daftar terurut naik (tanpa interpolasi, hasilnya selalu salah satu nilai nyata).
     *
     * @param  list<int>  $terurut
     */
    private static function Persentil(array $terurut, int $persen): int
    {
        if ($terurut === []) {
            return 0;
        }

        $urutan = (int) ceil($persen / 100 * count($terurut));

        return $terurut[max(0, $urutan - 1)];
    }
}
