<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Nilai\Uang;

/**
 * Baris jurnal invoice (PRD §11.3 J-21.1 & J-21.2, D-166). Pembatalan memakai jurnal pembalik otomatis
 * (`BalikkanJurnal`), jadi tidak ada penyusun khusus pembatalan.
 *
 * **J-21.1 invoice penjualan** (jasa / barang tanpa stok): pendapatan diakui saat diterbitkan, karena tidak ada penyerahan
 * stok yang menjadi titik pengakuan lain.
 * - Dr `PiutangUsaha` sebesar **Tagihan** (total dikurangi uang muka yang dipotong);
 * - Dr `UangMukaPelanggan` sebesar DPP uang muka yang dipotong (kewajiban DP dilunasi dengan jasa/barang yang diserahkan);
 * - Dr `DiskonPenjualan` sebesar diskon baris, Cr `PendapatanJasa`/`Penjualan` sebesar bruto − pajak inklusif (pola J-07.1);
 * - Cr `PpnKeluaran` / `HutangPbjt` per kode pajak, **dikurangi** PPN yang sudah dikreditkan saat invoice DP
 *   diterbitkan (PPN terutang saat pembayaran uang muka, UU PPN Pasal 11 ayat 1 huruf b), jadi tidak terhitung dua kali.
 *
 * Seimbang tanpa bergantung pembulatan: Tagihan + DPP uang muka + Diskon = Total − PPN uang muka + Diskon, dan
 * Σ pendapatan + (Pajak − PPN uang muka) = Σ bruto + PajakEksklusif − PPN uang muka, sedangkan Total + Diskon =
 * Σ bruto + PajakEksklusif.
 *
 * **J-21.2 invoice uang muka (DP)**: Dr `PiutangUsaha` sebesar total DP, Cr `UangMukaPelanggan` sebesar DPP, Cr pajak
 * per kode. Belum ada pendapatan; DP baru menjadi pendapatan saat invoice akhir memotongnya (J-21.1).
 */
final class PenyusunJurnalInvoice
{
    public function __construct(private readonly PenyusunJurnalPenjualan $penyusunPenjualan) {}

    /**
     * @param  array<string, Uang>  $pendapatan  nilai `PeranAkun` (PendapatanJasa/Penjualan) → Σ bruto − pajak inklusif
     * @param  array<string, Uang>  $pajak  kode jenis pajak → pajak invoice **setelah** dikurangi PPN uang muka (≥ 0)
     * @param  list<array{IdOutlet: int, Dpp: Uang}>  $uangMuka  DPP uang muka yang dipotong, per outlet invoice DP-nya
     * @return list<DataBarisJurnal>
     */
    public function BarisInvoice(int $idOutlet, Uang $tagihan, Uang $diskon, array $pendapatan, array $pajak, array $uangMuka): array
    {
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::PiutangUsaha, $tagihan, $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::DiskonPenjualan, $diskon, $idOutlet),
        ];

        foreach ($uangMuka as $potongan) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::UangMukaPelanggan, $potongan['Dpp'], $potongan['IdOutlet']);
        }

        foreach ($pendapatan as $peran => $nilai) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::from($peran), Uang::Nol()->Kurangi($nilai), $idOutlet);
        }

        return [...array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null)), ...$this->BarisPajak($pajak, $idOutlet)];
    }

    /**
     * @param  array<string, Uang>  $pajak  kode jenis pajak → pajak invoice DP
     * @return list<DataBarisJurnal>
     */
    public function BarisUangMuka(int $idOutlet, Uang $total, Uang $dpp, array $pajak): array
    {
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::PiutangUsaha, $total, $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::UangMukaPelanggan, Uang::Nol()->Kurangi($dpp), $idOutlet),
        ];

        return [...array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null)), ...$this->BarisPajak($pajak, $idOutlet)];
    }

    /**
     * @param  array<string, Uang>  $pajak
     * @return list<DataBarisJurnal>
     */
    private function BarisPajak(array $pajak, int $idOutlet): array
    {
        $baris = [];

        foreach ($pajak as $kode => $jumlah) {
            $peran = $this->penyusunPenjualan->TentukanAkunPajak([(string) $kode])[(string) $kode];
            $satu = DataBarisJurnal::DariSelisih($peran, Uang::Nol()->Kurangi($jumlah), $idOutlet);

            if ($satu !== null) {
                $baris[] = $satu;
            }
        }

        return $baris;
    }
}
