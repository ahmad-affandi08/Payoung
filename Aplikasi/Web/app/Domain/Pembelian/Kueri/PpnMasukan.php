<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\ReturPembelian;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * PPN masukan dari pembelian (TAX-05): PPN pada faktur pembelian yang tidak dibatalkan, per bulan tanggal faktur × tarif ×
 * dapat/tidaknya dikreditkan (`FakturPembelian.PpnDikreditkan`), dikurangi PPN retur pembelian terposting pada bulan
 * returnya (retur mengikuti tarif & status kredit faktur asalnya; retur tanpa faktur dianggap dikreditkan tanpa tarif).
 * Dibatasi outlet yang boleh diakses (`null` = semua; dokumen tanpa outlet hanya untuk pelaku semua outlet).
 */
final class PpnMasukan
{
    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Bulan: string, Tarif: string, Dikreditkan: bool, JumlahFaktur: int, Nilai: string, Pajak: string, NilaiRetur: string, PajakRetur: string, PajakBersih: string}>
     */
    public function Ambil(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutletBoleh): array
    {
        if ($idOutletBoleh === []) {
            return [];
        }

        $rentang = [$dari->toDateString(), $sampai->toDateString()];
        $faktur = FakturPembelian::query()
            ->where('Status', '!=', StatusFakturPembelian::Dibatalkan->value)
            ->where('Pajak', '>', 0)
            ->whereBetween('Tanggal', $rentang)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->groupByRaw("DATE_FORMAT(`Tanggal`, '%Y-%m'), `TarifPpn`, `PpnDikreditkan`")
            ->selectRaw("DATE_FORMAT(`Tanggal`, '%Y-%m') AS `Bulan`, `TarifPpn` AS `Tarif`, `PpnDikreditkan` AS `Kredit`, COUNT(*) AS `Jumlah`, COALESCE(SUM(`Subtotal` + `Ongkir`), 0) AS `Nilai`, COALESCE(SUM(`Pajak`), 0) AS `Pajak`")
            ->toBase()
            ->get();
        $retur = ReturPembelian::query()
            ->leftJoin('FakturPembelian as f', fn (JoinClause $j) => $j->on('f.Id', '=', 'ReturPembelian.IdFakturPembelian')->on('f.IdTenant', '=', 'ReturPembelian.IdTenant'))
            ->where('ReturPembelian.Status', StatusDokumenTerposting::Diposting->value)
            ->where('ReturPembelian.Pajak', '>', 0)
            ->whereBetween('ReturPembelian.Tanggal', $rentang)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('ReturPembelian.IdOutlet', $idOutletBoleh ?? []))
            ->groupByRaw("DATE_FORMAT(`ReturPembelian`.`Tanggal`, '%Y-%m'), `f`.`TarifPpn`, `f`.`PpnDikreditkan`")
            ->selectRaw("DATE_FORMAT(`ReturPembelian`.`Tanggal`, '%Y-%m') AS `Bulan`, `f`.`TarifPpn` AS `Tarif`, `f`.`PpnDikreditkan` AS `Kredit`, COALESCE(SUM(`ReturPembelian`.`NilaiBarang`), 0) AS `Nilai`, COALESCE(SUM(`ReturPembelian`.`Pajak`), 0) AS `Pajak`")
            ->toBase()
            ->get();
        $hasil = [];
        $kosong = ['JumlahFaktur' => 0, 'Nilai' => BigDecimal::zero(), 'Pajak' => BigDecimal::zero(), 'NilaiRetur' => BigDecimal::zero(), 'PajakRetur' => BigDecimal::zero()];

        foreach ($faktur as $b) {
            $kunci = self::Kunci($b);
            $ada = $hasil[$kunci] ?? ['Bulan' => self::Teks($b->Bulan ?? ''), 'Tarif' => self::Tarif($b->Tarif ?? null), 'Dikreditkan' => self::Kredit($b->Kredit ?? null), ...$kosong];
            $ada['JumlahFaktur'] += (int) $b->Jumlah;
            $ada['Nilai'] = $ada['Nilai']->plus((string) $b->Nilai);
            $ada['Pajak'] = $ada['Pajak']->plus((string) $b->Pajak);
            $hasil[$kunci] = $ada;
        }

        foreach ($retur as $b) {
            $kunci = self::Kunci($b);
            $ada = $hasil[$kunci] ?? ['Bulan' => self::Teks($b->Bulan ?? ''), 'Tarif' => self::Tarif($b->Tarif ?? null), 'Dikreditkan' => self::Kredit($b->Kredit ?? null), ...$kosong];
            $ada['NilaiRetur'] = $ada['NilaiRetur']->plus((string) $b->Nilai);
            $ada['PajakRetur'] = $ada['PajakRetur']->plus((string) $b->Pajak);
            $hasil[$kunci] = $ada;
        }

        ksort($hasil);

        return array_values(array_map(fn (array $r): array => [
            'Bulan' => $r['Bulan'],
            'Tarif' => $r['Tarif'],
            'Dikreditkan' => $r['Dikreditkan'],
            'JumlahFaktur' => $r['JumlahFaktur'],
            'Nilai' => self::Uang($r['Nilai']),
            'Pajak' => self::Uang($r['Pajak']),
            'NilaiRetur' => self::Uang($r['NilaiRetur']),
            'PajakRetur' => self::Uang($r['PajakRetur']),
            'PajakBersih' => self::Uang($r['Pajak']->minus($r['PajakRetur'])),
        ], $hasil));
    }

    private static function Kunci(\stdClass $b): string
    {
        return self::Teks($b->Bulan ?? '').'|'.self::Tarif($b->Tarif ?? null).'|'.(self::Kredit($b->Kredit ?? null) ? '1' : '0');
    }

    private static function Teks(mixed $nilai): string
    {
        return is_string($nilai) || is_int($nilai) ? (string) $nilai : '';
    }

    private static function Tarif(mixed $tarif): string
    {
        return is_string($tarif) || is_int($tarif) ? (string) BigDecimal::of($tarif)->toScale(6, RoundingMode::HalfUp) : '0.000000';
    }

    private static function Kredit(mixed $kredit): bool
    {
        // Retur tanpa faktur (kolom faktur kosong) dianggap dikreditkan.
        return $kredit === null || (bool) $kredit;
    }

    private static function Uang(BigDecimal $nilai): string
    {
        return (string) $nilai->toScale(2, RoundingMode::HalfUp);
    }
}
