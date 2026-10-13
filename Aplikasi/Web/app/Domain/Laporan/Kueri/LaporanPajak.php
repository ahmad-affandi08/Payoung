<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pembelian\Kueri\PpnMasukan;
use App\Domain\Penjualan\Kueri\PajakPenjualanBulanan;
use Carbon\CarbonImmutable;

/**
 * Laporan pajak F-14a (`/kelola/laporan/pajak`, izin `laporan.keuangan.lihat`): pajak keluaran dari `PenjualanPajak`
 * dikurangi pajak retur (kueri publik domain Penjualan), dikelompokkan menurut kategori jenis pajak (domain Pajak):
 * - PB1/PBJT (kategori `Pbjt` & `Lainnya`) per outlet × bulan × jenis × tarif (TAX-04: DPP, pajak);
 * - PPN keluaran (kategori `Ppn`) per bulan × tarif untuk seluruh outlet yang boleh diakses (TAX-05 dasar).
 */
final class LaporanPajak
{
    public function __construct(
        private readonly PajakPenjualanBulanan $pajak,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly PetaUuidOutlet $outlet,
        private readonly PpnMasukan $ppnMasukan,
    ) {}

    /**
     * @param  list<int>|null  $idOutlet
     * @return array{Pbjt: list<array<string, mixed>>, Ppn: list<array<string, mixed>>, PpnMasukan: list<array<string, mixed>>, RingkasanPpn: array<string, string>}
     */
    public function Ambil(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutlet): array
    {
        $baris = $this->pajak->Ambil($dari, $sampai, $idOutlet);
        $jenis = $this->kelompokPajak->AmbilJenisPajak(array_values(array_unique(array_column($baris, 'KodeJenisPajak'))));
        $namaOutlet = array_column($this->outlet->AmbilRingkas(array_values(array_unique(array_column($baris, 'IdOutlet')))), 'Nama', 'Id');
        $pbjt = [];
        $ppn = [];

        foreach ($baris as $b) {
            $j = $jenis[$b['KodeJenisPajak']] ?? null;
            $kategori = KategoriJenisPajak::tryFrom($j['Kategori'] ?? '') ?? KategoriJenisPajak::Lainnya;
            $dasar = [
                'Bulan' => $b['Bulan'],
                'KodeJenisPajak' => $b['KodeJenisPajak'],
                'NamaJenisPajak' => $j['Nama'] ?? $b['KodeJenisPajak'],
                'LabelKategori' => $kategori->AmbilLabel(),
                'Tarif' => $b['Tarif'],
            ];

            if ($kategori !== KategoriJenisPajak::Ppn) {
                $pbjt[] = [
                    'Kunci' => "{$b['Bulan']}|{$b['IdOutlet']}|{$b['KodeJenisPajak']}|{$b['Tarif']}",
                    ...$dasar,
                    'NamaOutlet' => $namaOutlet[$b['IdOutlet']] ?? '',
                    ...self::Angka($b),
                ];

                continue;
            }

            $kunci = "{$b['Bulan']}|{$b['KodeJenisPajak']}|{$b['Tarif']}";
            $ada = $ppn[$kunci] ?? ['Kunci' => $kunci, ...$dasar, 'Dpp' => '0.00', 'Pajak' => '0.00', 'DppRetur' => '0.00', 'PajakRetur' => '0.00', 'DppBersih' => '0.00', 'PajakBersih' => '0.00', 'JumlahTransaksi' => 0];

            foreach (['Dpp', 'Pajak', 'DppRetur', 'PajakRetur', 'DppBersih', 'PajakBersih'] as $kolom) {
                $ada[$kolom] = Uang::Dari($ada[$kolom])->Tambah(Uang::Dari($b[$kolom]))->KeString();
            }

            $ada['JumlahTransaksi'] += $b['JumlahTransaksi'];
            $ppn[$kunci] = $ada;
        }

        $masukan = array_map(fn (array $b): array => ['Kunci' => "{$b['Bulan']}|{$b['Tarif']}|".($b['Dikreditkan'] ? '1' : '0'), ...$b], $this->ppnMasukan->Ambil($dari, $sampai, $idOutlet));

        return ['Pbjt' => $pbjt, 'Ppn' => array_values($ppn), 'PpnMasukan' => $masukan, 'RingkasanPpn' => self::RingkasPpn(array_values($ppn), $masukan)];
    }

    /**
     * Ringkasan PPN periode: keluaran bersih (sesudah retur penjualan), masukan yang dapat dikreditkan bersih (sesudah retur
     * pembelian), masukan yang tidak dapat dikreditkan (beban, tidak mengurangi PPN), dan selisih = keluaran − masukan
     * dikreditkan (positif = kurang bayar, negatif = lebih bayar). Angka bantu pembukuan, bukan SPT.
     *
     * @param  list<array<string, mixed>>  $keluaran
     * @param  list<array<string, mixed>>  $masukan
     * @return array<string, string>
     */
    private static function RingkasPpn(array $keluaran, array $masukan): array
    {
        $teks = static fn (mixed $nilai): string => is_string($nilai) ? $nilai : '0';
        $keluar = Uang::Nol();
        $kredit = Uang::Nol();
        $tanpaKredit = Uang::Nol();

        foreach ($keluaran as $b) {
            $keluar = $keluar->Tambah(Uang::Dari($teks($b['PajakBersih'] ?? null)));
        }

        foreach ($masukan as $b) {
            $bersih = Uang::Dari($teks($b['PajakBersih'] ?? null));

            if ($b['Dikreditkan'] === true) {
                $kredit = $kredit->Tambah($bersih);
            } else {
                $tanpaKredit = $tanpaKredit->Tambah($bersih);
            }
        }

        return [
            'PpnKeluaran' => $keluar->KeString(),
            'PpnMasukanKredit' => $kredit->KeString(),
            'PpnMasukanTanpaKredit' => $tanpaKredit->KeString(),
            'Selisih' => $keluar->Kurangi($kredit)->KeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function Angka(array $b): array
    {
        return [
            'Dpp' => $b['Dpp'],
            'Pajak' => $b['Pajak'],
            'DppRetur' => $b['DppRetur'],
            'PajakRetur' => $b['PajakRetur'],
            'DppBersih' => $b['DppBersih'],
            'PajakBersih' => $b['PajakBersih'],
            'JumlahTransaksi' => $b['JumlahTransaksi'],
        ];
    }
}
