<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Angka laporan pembelian (§10.10 "Pembelian": per pemasok, per produk, PO outstanding). Sumbernya faktur pembelian yang
 * tidak dibatalkan (nilai pembelian = faktur, retur sudah mengurangi `JumlahRetur`) dan pesanan pembelian terbuka.
 * Semua dibatasi outlet yang boleh diakses (`idOutletBoleh` null = semua; dokumen tanpa outlet hanya untuk pelaku semua
 * outlet). Uang & jumlah tetap string desimal; pembagian hanya di PHP dengan BigDecimal.
 */
final class AgregatPembelian
{
    /**
     * Pembelian per pemasok pada periode (tanggal faktur): jumlah faktur, DPP (subtotal), ongkir, pajak, total, retur,
     * pembelian bersih (total − retur), dibayar, dan sisa hutang. `IdPemasok` null = faktur tanpa pemasok.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{IdPemasok: int|null, JumlahFaktur: int, Subtotal: string, Ongkir: string, Pajak: string, Total: string, Retur: string, Bersih: string, Dibayar: string, SisaHutang: string}>
     */
    public function PerPemasok(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutletBoleh): array
    {
        $baris = $this->KueriFaktur($dari, $sampai, $idOutletBoleh)
            ->groupBy('IdPemasok')
            ->selectRaw('`IdPemasok`, COUNT(*) AS `Jumlah`, COALESCE(SUM(`Subtotal`), 0) AS `Subtotal`, COALESCE(SUM(`Ongkir`), 0) AS `Ongkir`, COALESCE(SUM(`Pajak`), 0) AS `Pajak`, COALESCE(SUM(`Total`), 0) AS `Total`, COALESCE(SUM(`JumlahRetur`), 0) AS `Retur`, COALESCE(SUM(`JumlahDibayar`), 0) AS `Dibayar`')
            ->toBase()
            ->get();
        $hasil = [];

        foreach ($baris as $b) {
            $total = BigDecimal::of((string) $b->Total);
            $retur = BigDecimal::of((string) $b->Retur);
            $dibayar = BigDecimal::of((string) $b->Dibayar);
            $hasil[] = [
                'IdPemasok' => $b->IdPemasok === null ? null : (int) $b->IdPemasok,
                'JumlahFaktur' => (int) $b->Jumlah,
                'Subtotal' => self::Uang((string) $b->Subtotal),
                'Ongkir' => self::Uang((string) $b->Ongkir),
                'Pajak' => self::Uang((string) $b->Pajak),
                'Total' => self::Uang($total),
                'Retur' => self::Uang($retur),
                'Bersih' => self::Uang($total->minus($retur)),
                'Dibayar' => self::Uang($dibayar),
                'SisaHutang' => self::Uang($total->minus($dibayar)->minus($retur)),
            ];
        }

        return $hasil;
    }

    /**
     * Pembelian per produk pada periode: jumlah (satuan dasar), nilai beli (subtotal baris sesudah diskon, sebelum pajak
     * dan ongkir), nilai retur, nilai bersih, harga rata-rata per satuan dasar, harga terendah & tertinggi per satuan
     * dasar, jumlah faktur dan pemasok. Urut nilai bersih terbesar, dibatasi `$batas` baris.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{IdProduk: int, NamaProduk: string, Qty: string, Nilai: string, NilaiRetur: string, Bersih: string, HargaRata: string, HargaMin: string, HargaMax: string, JumlahFaktur: int, JumlahPemasok: int}>
     */
    public function PerProduk(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutletBoleh, int $batas = 200): array
    {
        $baris = FakturPembelianDetail::query()
            ->join('FakturPembelian as f', fn ($j) => $j->on('f.Id', '=', 'FakturPembelianDetail.IdFakturPembelian')->on('f.IdTenant', '=', 'FakturPembelianDetail.IdTenant'))
            ->where('f.Status', '!=', StatusFakturPembelian::Dibatalkan->value)
            ->whereBetween('f.Tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('f.IdOutlet', $idOutletBoleh ?? []))
            ->groupBy('FakturPembelianDetail.IdProduk')
            ->selectRaw('`FakturPembelianDetail`.`IdProduk` AS `IdProduk`, MAX(`FakturPembelianDetail`.`NamaProduk`) AS `NamaProduk`, COALESCE(SUM(`FakturPembelianDetail`.`JumlahDasar`), 0) AS `Qty`, COALESCE(SUM(`FakturPembelianDetail`.`Subtotal`), 0) AS `Nilai`, COALESCE(SUM(`FakturPembelianDetail`.`NilaiDiretur`), 0) AS `NilaiRetur`, COUNT(DISTINCT `f`.`Id`) AS `JumlahFaktur`, COUNT(DISTINCT `f`.`IdPemasok`) AS `JumlahPemasok`, MIN(CASE WHEN `FakturPembelianDetail`.`JumlahDasar` > 0 THEN `FakturPembelianDetail`.`Subtotal` / `FakturPembelianDetail`.`JumlahDasar` END) AS `HargaMin`, MAX(CASE WHEN `FakturPembelianDetail`.`JumlahDasar` > 0 THEN `FakturPembelianDetail`.`Subtotal` / `FakturPembelianDetail`.`JumlahDasar` END) AS `HargaMax`')
            ->toBase()
            ->get();
        $hasil = [];

        foreach ($baris as $b) {
            $qty = BigDecimal::of((string) $b->Qty);
            $nilai = BigDecimal::of((string) $b->Nilai);
            $retur = BigDecimal::of((string) $b->NilaiRetur);
            $hasil[] = [
                'IdProduk' => (int) $b->IdProduk,
                'NamaProduk' => (string) $b->NamaProduk,
                'Qty' => (string) $qty->toScale(4, RoundingMode::HalfUp),
                'Nilai' => self::Uang($nilai),
                'NilaiRetur' => self::Uang($retur),
                'Bersih' => self::Uang($nilai->minus($retur)),
                'HargaRata' => $qty->isZero() ? '0.00' : self::Uang($nilai->dividedBy($qty, 6, RoundingMode::HalfUp)),
                'HargaMin' => self::Uang((string) ($b->HargaMin ?? '0')),
                'HargaMax' => self::Uang((string) ($b->HargaMax ?? '0')),
                'JumlahFaktur' => (int) $b->JumlahFaktur,
                'JumlahPemasok' => (int) $b->JumlahPemasok,
            ];
        }

        usort($hasil, fn (array $a, array $b): int => BigDecimal::of($b['Bersih'])->compareTo($a['Bersih']) ?: strcmp($a['NamaProduk'], $b['NamaProduk']));

        return array_slice($hasil, 0, $batas);
    }

    /**
     * Pesanan pembelian yang masih menunggu barang (Disetujui atau Diterima sebagian): baris yang belum lengkap diterima
     * dan nilai sisa (sisa jumlah × harga bersih per satuan baris). Urut perkiraan tiba terdekat (tanpa perkiraan di
     * akhir), lalu tanggal. Bukan laporan berperiode: ini keadaan saat ini.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, IdPemasok: int, Tanggal: string, PerkiraanTiba: string|null, Status: string, LabelStatus: string, UmurHari: int, HariTerlambat: int, JumlahBaris: int, JumlahBarisSisa: int, Total: string, NilaiSisa: string}>
     */
    public function PesananOutstanding(CarbonImmutable $hariIni, ?array $idOutletBoleh, int $batas = 500): array
    {
        $pesanan = PesananPembelian::query()
            ->whereIn('Status', [StatusPesananPembelian::Disetujui->value, StatusPesananPembelian::DiterimaSebagian->value])
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByRaw('`PerkiraanTiba` IS NULL')
            ->orderBy('PerkiraanTiba')
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->limit($batas)
            ->get();
        // Bandingkan tanggal kalender murni (tanpa zona waktu), supaya selisih hari tidak tergelincir oleh jam.
        $hari = CarbonImmutable::parse($hariIni->toDateString());
        $detail = PesananPembelianDetail::query()->whereIn('IdPesananPembelian', $pesanan->pluck('Id')->all())->get()->groupBy('IdPesananPembelian');
        $hasil = [];

        foreach ($pesanan as $po) {
            $baris = 0;
            $barisSisa = 0;
            $nilaiSisa = BigDecimal::zero();

            foreach ($detail->get($po->Id, collect()) as $d) {
                $baris++;
                $jumlah = BigDecimal::of($d->Jumlah);
                $sisa = $jumlah->minus($d->JumlahDiterima);

                if ($sisa->isPositive()) {
                    $barisSisa++;
                    $nilaiSisa = $nilaiSisa->plus($jumlah->isZero() ? BigDecimal::zero() : BigDecimal::of($d->Subtotal)->multipliedBy($sisa)->dividedBy($jumlah, 6, RoundingMode::HalfUp));
                }
            }

            $perkiraan = $po->PerkiraanTiba === null ? null : CarbonImmutable::parse($po->PerkiraanTiba->toDateString());
            $hasil[] = [
                'Uuid' => $po->Uuid,
                'Nomor' => $po->Nomor,
                'IdPemasok' => $po->IdPemasok,
                'Tanggal' => $po->Tanggal->toDateString(),
                'PerkiraanTiba' => $perkiraan?->toDateString(),
                'Status' => $po->Status->value,
                'LabelStatus' => $po->Status->AmbilLabel(),
                'UmurHari' => max(0, (int) CarbonImmutable::parse($po->Tanggal->toDateString())->diffInDays($hari, false)),
                'HariTerlambat' => $perkiraan !== null && $perkiraan->lessThan($hari) ? (int) $perkiraan->diffInDays($hari) : 0,
                'JumlahBaris' => $baris,
                'JumlahBarisSisa' => $barisSisa,
                'Total' => self::Uang($po->Total),
                'NilaiSisa' => self::Uang($nilaiSisa),
            ];
        }

        return $hasil;
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<FakturPembelian>
     */
    private function KueriFaktur(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutletBoleh): Builder
    {
        return FakturPembelian::query()
            ->where('Status', '!=', StatusFakturPembelian::Dibatalkan->value)
            ->whereBetween('Tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []));
    }

    private static function Uang(BigDecimal|string $nilai): string
    {
        return (string) BigDecimal::of($nilai)->toScale(2, RoundingMode::HalfUp);
    }
}
