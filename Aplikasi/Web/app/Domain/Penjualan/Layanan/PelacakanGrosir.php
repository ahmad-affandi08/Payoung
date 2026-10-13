<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\ReturGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use Brick\Math\BigDecimal;

/**
 * Pelacakan batch & nomor seri pada dokumen grosir (F-12 × F-05g/F-05h). Surat jalan menyimpan snapshot yang
 * diserahkan di `SuratJalanDetail.RincianPelacakan`; retur menyimpan yang dikembalikan di
 * `ReturGrosirDetail.RincianPelacakan`. Sisa yang masih bisa diretur adalah selisihnya, dihitung dari retur yang masih
 * berlaku (retur dibatalkan tidak dihitung), jadi pembatalan retur otomatis membuka kembali batch/nomor itu.
 *
 * Bentuk entri: batch `{NomorBatch, TanggalKedaluwarsa, JumlahDasar, HppSatuan}`, nomor seri `{Nomor, HppSatuan}`.
 * HPP per entri disimpan supaya retur mengembalikan stok pada nilai saat barang keluar, bukan nilai rata-rata baris.
 */
final class PelacakanGrosir
{
    /**
     * Sisa batch yang masih bisa diretur dari satu baris surat jalan, urut seperti alokasi penyerahan (FEFO).
     *
     * @return list<array{NomorBatch: string, TanggalKedaluwarsa: string|null, JumlahDasar: string, HppSatuan: string}>
     */
    public function SisaBatch(SuratJalanDetail $detail): array
    {
        $sudah = [];

        foreach ($this->ReturBerlaku($detail) as $retur) {
            foreach ($retur->RincianPelacakan ?? [] as $e) {
                $kunci = self::KunciBatch($e);
                $sudah[$kunci] = ($sudah[$kunci] ?? BigDecimal::zero())->plus((string) ($e['JumlahDasar'] ?? '0'));
            }
        }

        $hasil = [];

        foreach ($detail->RincianPelacakan ?? [] as $e) {
            if (! isset($e['NomorBatch'])) {
                continue;
            }

            $kunci = self::KunciBatch($e);
            $sisa = BigDecimal::of((string) $e['JumlahDasar'])->minus($sudah[$kunci] ?? BigDecimal::zero());
            $sudah[$kunci] = ($sudah[$kunci] ?? BigDecimal::zero())->minus(BigDecimal::of((string) $e['JumlahDasar']));

            if ($sisa->isPositive()) {
                $hasil[] = ['NomorBatch' => (string) $e['NomorBatch'], 'TanggalKedaluwarsa' => $e['TanggalKedaluwarsa'] ?? null, 'JumlahDasar' => (string) $sisa, 'HppSatuan' => (string) $e['HppSatuan']];
            }
        }

        return $hasil;
    }

    /**
     * Sisa nomor seri yang masih bisa diretur dari satu baris surat jalan.
     *
     * @return list<array{Nomor: string, HppSatuan: string}>
     */
    public function SisaSeri(SuratJalanDetail $detail): array
    {
        $sudah = [];

        foreach ($this->ReturBerlaku($detail) as $retur) {
            foreach ($retur->RincianPelacakan ?? [] as $e) {
                if (isset($e['Nomor'])) {
                    $sudah[mb_strtoupper((string) $e['Nomor'])] = true;
                }
            }
        }

        $hasil = [];

        foreach ($detail->RincianPelacakan ?? [] as $e) {
            if (isset($e['Nomor']) && ! isset($sudah[mb_strtoupper((string) $e['Nomor'])])) {
                $hasil[] = ['Nomor' => (string) $e['Nomor'], 'HppSatuan' => (string) $e['HppSatuan']];
            }
        }

        return $hasil;
    }

    /**
     * Pembagian jumlah retur ke batch asal secara **terbalik** dari alokasi penyerahan (yang keluar terakhir kembali
     * dulu). Ditolak bila jumlahnya melebihi sisa batch (`JumlahReturMelebihiSisa` sudah dijaga lebih awal, ini
     * pengaman tambahan).
     *
     * @return list<array{NomorBatch: string, TanggalKedaluwarsa: string|null, JumlahDasar: string, HppSatuan: string}>
     */
    public function AlokasikanReturBatch(SuratJalanDetail $detail, Kuantitas $jumlahDasar): array
    {
        $butuh = BigDecimal::of($jumlahDasar->KeString());
        $hasil = [];

        foreach (array_reverse($this->SisaBatch($detail)) as $e) {
            if (! $butuh->isPositive()) {
                break;
            }

            $ambil = $butuh->isLessThan($e['JumlahDasar']) ? $butuh : BigDecimal::of($e['JumlahDasar']);
            $hasil[] = [...$e, 'JumlahDasar' => (string) $ambil];
            $butuh = $butuh->minus($ambil);
        }

        if ($butuh->isPositive()) {
            throw new PelanggaranAturanBisnis('JumlahReturMelebihiSisa', "Jumlah retur {$detail->NamaProduk} melebihi batch yang diserahkan dan belum diretur.", 'Baris');
        }

        return array_values(array_reverse($hasil));
    }

    /**
     * Nomor seri yang dikembalikan harus bagian dari yang diserahkan dan belum diretur.
     *
     * @param  list<string>  $nomor
     * @return list<array{Nomor: string, HppSatuan: string}>
     */
    public function PilihReturSeri(SuratJalanDetail $detail, array $nomor): array
    {
        $sisa = [];

        foreach ($this->SisaSeri($detail) as $e) {
            $sisa[mb_strtoupper($e['Nomor'])] = $e;
        }

        $hasil = [];
        $dipakai = [];

        foreach ($nomor as $no) {
            $kunci = mb_strtoupper(trim($no));

            if (isset($dipakai[$kunci])) {
                throw new PelanggaranAturanBisnis('NomorSeriGanda', "Nomor seri {$no} dipilih dua kali.", 'Baris');
            }

            if (! isset($sisa[$kunci])) {
                throw new PelanggaranAturanBisnis(
                    'NomorSeriReturTidakValid',
                    "Nomor seri {$no} bukan bagian dari {$detail->NamaProduk} yang diserahkan di surat jalan ini, atau sudah diretur.",
                    'Baris',
                );
            }

            $dipakai[$kunci] = true;
            $hasil[] = $sisa[$kunci];
        }

        return $hasil;
    }

    /**
     * @return list<ReturGrosirDetail>
     */
    private function ReturBerlaku(SuratJalanDetail $detail): array
    {
        return array_values(ReturGrosirDetail::query()
            ->where('IdSuratJalanDetail', $detail->Id)
            ->whereIn('IdReturGrosir', ReturGrosir::query()->where('Status', StatusDokumenTerposting::Diposting->value)->select('Id'))
            ->orderBy('Id')
            ->get()
            ->all());
    }

    /**
     * @param  array<string, string|null>  $entri
     */
    private static function KunciBatch(array $entri): string
    {
        return mb_strtoupper((string) ($entri['NomorBatch'] ?? '')).'|'.($entri['TanggalKedaluwarsa'] ?? '');
    }
}
