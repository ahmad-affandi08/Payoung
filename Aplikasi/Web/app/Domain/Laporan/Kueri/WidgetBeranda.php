<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Kueri\LaporanKomisi;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;

/**
 * Widget "Ringkasan cepat" di Beranda (D-203): enam daftar peringkat untuk periode yang sama dengan dasbor (metode
 * bayar, jenis pesanan, kategori, kasir, komisi staf) dan ringkasan kontrol anti-fraud. Produk terlaris dan stok
 * kritis memakai data dasbor yang sudah ada. Tiap daftar dibatasi `BATAS` baris teratas; `Total` adalah jumlah
 * seluruh baris, bukan hanya yang tampil, supaya batang proporsi jujur.
 */
final class WidgetBeranda
{
    public const BATAS = 5;

    public function __construct(
        private readonly LaporanPenjualan $laporan,
        private readonly LaporanKomisi $komisi,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>
     */
    public function Ambil(DataSaringLaporanPenjualan $saring, ?array $idOutletBoleh, bool $bolehKomisi): array
    {
        return [
            'MetodeBayar' => $this->Peringkat(
                array_map(fn (array $m): array => ['Kunci' => $m['Kunci'], 'Nama' => $m['NamaMetode'], 'Nilai' => $m['Bersih'], 'Keterangan' => $m['JumlahTransaksi'].' transaksi'], $this->laporan->PerMetode($saring)),
            ),
            'JenisPesanan' => $this->Peringkat(
                array_map(fn (array $k): array => ['Kunci' => $k['Kunci'], 'Nama' => $k['LabelKanal'], 'Nilai' => $k['Bersih'], 'Keterangan' => $k['JumlahTransaksi'].' transaksi'], $this->laporan->PerKanal($saring)),
            ),
            'Kategori' => $this->Peringkat(
                array_map(fn (array $k): array => ['Kunci' => $k['Kunci'], 'Nama' => $k['NamaKategori'], 'Nilai' => $k['Bersih'], 'Keterangan' => $k['JumlahProduk'].' produk'], $this->laporan->PerKategori($saring)),
            ),
            'Kasir' => $this->Peringkat(
                array_map(fn (array $k): array => ['Kunci' => $k['Kunci'], 'Nama' => $k['NamaKasir'], 'Nilai' => $k['Bersih'], 'Keterangan' => $k['JumlahTransaksi'].' transaksi'], $this->laporan->PerKasir($saring)),
            ),
            'AntiFraud' => $this->RingkasAntiFraud($this->laporan->AntiFraud($saring)),
            'Komisi' => $bolehKomisi ? $this->RingkasKomisi($saring, $idOutletBoleh) : null,
        ];
    }

    /**
     * Baris yang nilainya nol atau negatif tidak diperingkatkan (batang tidak bermakna), tetapi tetap ikut `Total`.
     *
     * @param  list<array{Kunci: string, Nama: string, Nilai: string, Keterangan: string}>  $baris
     * @return array{Total: string, Jumlah: int, Baris: list<array{Kunci: string, Nama: string, Nilai: string, Keterangan: string}>}
     */
    private function Peringkat(array $baris): array
    {
        $total = Uang::Nol();

        foreach ($baris as $b) {
            $total = $total->Tambah(Uang::Dari($b['Nilai']));
        }

        $berisi = array_values(array_filter($baris, fn (array $b): bool => Uang::Dari($b['Nilai'])->Bandingkan(Uang::Nol()) > 0));

        return ['Total' => $total->KeString(), 'Jumlah' => count($berisi), 'Baris' => array_slice($berisi, 0, self::BATAS)];
    }

    /**
     * Status keseluruhan = tingkat tertinggi di antara kasir; indikator dijumlahkan seluruh kasir.
     *
     * @param  list<array<string, mixed>>  $baris  keluaran `LaporanPenjualan::AntiFraud`, urut skor menurun
     * @return array{Tingkat: string, SkorTertinggi: int, NamaKasirTertinggi: string, JumlahKasirPerluDicek: int, VoidCepatTunai: int, JumlahVoid: int, JumlahBerdiskon: int, BukaLaciManual: int, ShiftSelisihKurang: int}
     */
    private function RingkasAntiFraud(array $baris): array
    {
        $hasil = ['Tingkat' => 'Rendah', 'SkorTertinggi' => 0, 'NamaKasirTertinggi' => '', 'JumlahKasirPerluDicek' => 0, 'VoidCepatTunai' => 0, 'JumlahVoid' => 0, 'JumlahBerdiskon' => 0, 'BukaLaciManual' => 0, 'ShiftSelisihKurang' => 0];

        foreach ($baris as $i => $b) {
            if ($i === 0) {
                $hasil['Tingkat'] = (string) $b['Tingkat'];
                $hasil['SkorTertinggi'] = (int) $b['Skor'];
                $hasil['NamaKasirTertinggi'] = (string) $b['NamaKasir'];
            }

            if ($b['Tingkat'] !== 'Rendah') {
                $hasil['JumlahKasirPerluDicek']++;
            }

            foreach (['VoidCepatTunai', 'JumlahVoid', 'JumlahBerdiskon', 'BukaLaciManual', 'ShiftSelisihKurang'] as $kolom) {
                $hasil[$kolom] += (int) $b[$kolom];
            }
        }

        return $hasil;
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Total: string, Jumlah: int, Baris: list<array{Kunci: string, Nama: string, Nilai: string, Keterangan: string}>}
     */
    private function RingkasKomisi(DataSaringLaporanPenjualan $saring, ?array $idOutletBoleh): array
    {
        $ringkasan = $this->komisi->AmbilRingkasanPeriode($saring->dari->toDateString(), $saring->sampai->toDateString(), $idOutletBoleh, self::BATAS);
        $baris = array_map(fn (array $b): array => ['Kunci' => $b['Uuid'], 'Nama' => $b['Nama'], 'Nilai' => $b['Bersih'], 'Keterangan' => ''], $ringkasan['Baris']);

        return ['Total' => $ringkasan['Total'], 'Jumlah' => count($baris), 'Baris' => $baris];
    }
}
