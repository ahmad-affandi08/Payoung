<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Model\BukaLaci;
use App\Domain\Kasir\Model\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * F-14 anti-fraud (OWN-09): pola kas per kasir dalam rentang tanggal bisnis shift: buka laci manual tanpa transaksi
 * (per pembuka) dan selisih kas tutup shift. `SelisihKurang` = jumlah mutlak selisih negatif (uang di laci kurang dari
 * seharusnya). Audit F-15: selisih dikreditkan ke kasir pemilik shift (`DibukaOleh`), bukan ke penutup (supervisor boleh
 * menutup shift orang lain); shift bersama tidak dibebankan ke satu kasir.
 */
final class PolaKasKasir
{
    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet
     * @return array<int, array{BukaLaciManual: int, ShiftDitutup: int, ShiftSelisihKurang: int, SelisihKurang: string, SelisihLebih: string}>
     */
    public function PerKasir(CarbonInterface $dari, CarbonInterface $sampai, ?array $idOutlet, ?int $idKasir = null): array
    {
        $hasil = [];
        $kosong = ['BukaLaciManual' => 0, 'ShiftDitutup' => 0, 'ShiftSelisihKurang' => 0, 'SelisihKurang' => '0.00', 'SelisihLebih' => '0.00'];
        $rentang = [$dari->toDateString(), $sampai->toDateString()];

        foreach (BukaLaci::query()
            ->join('Shift', fn (JoinClause $j) => $j->on('Shift.Id', '=', 'BukaLaci.IdShift')->on('Shift.IdTenant', '=', 'BukaLaci.IdTenant'))
            ->whereBetween('Shift.TanggalBisnis', $rentang)
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('Shift.IdOutlet', $idOutlet ?? []))
            ->when($idKasir !== null, fn (Builder $k) => $k->where('BukaLaci.DibukaOleh', $idKasir))
            ->selectRaw('`BukaLaci`.`DibukaOleh` AS `IdKasir`, COUNT(*) AS `Jumlah`')
            ->groupBy('BukaLaci.DibukaOleh')
            ->toBase()
            ->get() as $b) {
            $hasil[(int) $b->IdKasir] = [...$kosong, 'BukaLaciManual' => (int) $b->Jumlah];
        }

        foreach (Shift::query()
            ->whereNotNull('DitutupOleh')
            ->where('Bersama', false)
            ->whereBetween('TanggalBisnis', $rentang)
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->when($idKasir !== null, fn (Builder $k) => $k->where('DibukaOleh', $idKasir))
            ->selectRaw('`DibukaOleh` AS `IdKasir`, COUNT(*) AS `Jumlah`')
            ->selectRaw('SUM(CASE WHEN `Selisih` < 0 THEN 1 ELSE 0 END) AS `JumlahKurang`')
            ->selectRaw('COALESCE(SUM(CASE WHEN `Selisih` < 0 THEN -`Selisih` ELSE 0 END), 0) AS `Kurang`')
            ->selectRaw('COALESCE(SUM(CASE WHEN `Selisih` > 0 THEN `Selisih` ELSE 0 END), 0) AS `Lebih`')
            ->groupBy('DibukaOleh')
            ->toBase()
            ->get() as $b) {
            $hasil[(int) $b->IdKasir] = [
                ...($hasil[(int) $b->IdKasir] ?? $kosong),
                'ShiftDitutup' => (int) $b->Jumlah,
                'ShiftSelisihKurang' => (int) $b->JumlahKurang,
                'SelisihKurang' => Uang::Dari(is_string($b->Kurang) || is_int($b->Kurang) ? (string) $b->Kurang : '0')->KeString(),
                'SelisihLebih' => Uang::Dari(is_string($b->Lebih) || is_int($b->Lebih) ? (string) $b->Lebih : '0')->KeString(),
            ];
        }

        return $hasil;
    }

    /**
     * Detik kerja per kasir: Σ (tutup − buka) shift yang sudah ditutup pada rentang tanggal bisnis, tanpa shift bersama
     * (tidak milik satu kasir). Dasar laporan performa kasir (transaksi & omzet per jam kerja).
     *
     * @param  list<int>|null  $idOutlet  null = semua outlet
     * @return array<int, int> Id kasir → detik
     */
    public function DetikKerjaPerKasir(CarbonInterface $dari, CarbonInterface $sampai, ?array $idOutlet, ?int $idKasir = null): array
    {
        $hasil = [];

        foreach (Shift::query()
            ->whereNotNull('DitutupPada')
            ->where('Bersama', false)
            ->whereBetween('TanggalBisnis', [$dari->toDateString(), $sampai->toDateString()])
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->when($idKasir !== null, fn (Builder $k) => $k->where('DibukaOleh', $idKasir))
            ->selectRaw('`DibukaOleh` AS `IdKasir`, COALESCE(SUM(GREATEST(TIMESTAMPDIFF(SECOND, `DibukaPada`, `DitutupPada`), 0)), 0) AS `Detik`')
            ->groupBy('DibukaOleh')
            ->toBase()
            ->get() as $b) {
            $hasil[(int) $b->IdKasir] = (int) (is_numeric($b->Detik) ? $b->Detik : 0);
        }

        return $hasil;
    }
}
