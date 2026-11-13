<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use Carbon\CarbonImmutable;

/**
 * Pesanan pembelian yang masih menunggu barang (Disetujui atau Diterima sebagian), dilengkapi nama pemasok dan lokasi
 * stok tujuan, untuk dua pemakai: halaman "pilih pesanan" saat menerima barang dan saran "ada pesanan terbuka dari
 * pemasok ini" di formulir penerimaan tanpa pesanan (F-04). Sumber angkanya `AgregatPembelian::PesananOutstanding`
 * supaya sisa barang, sisa nilai, dan keterlambatan sama dengan laporan pembelian. Dibatasi outlet yang boleh diakses.
 */
final class PesananTerbukaUntukDiterima
{
    /** Pesanan tanpa perkiraan tiba dianggap terlambat setelah sekian hari sejak tanggal pesanan. */
    public const HARI_TANPA_PERKIRAAN = 7;

    public function __construct(
        private readonly AgregatPembelian $agregat,
        private readonly PetaNamaPembelian $peta,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Nomor: string, UuidPemasok: string, NamaPemasok: string, NamaGudang: string, Tanggal: string, PerkiraanTiba: string|null, HariTerlambat: int, Terlambat: bool, Status: string, LabelStatus: string, JumlahBarisSisa: int, NilaiSisa: string}>
     */
    public function Daftar(CarbonImmutable $hariIni, ?array $idOutletBoleh, int $batas = 200): array
    {
        $baris = $this->agregat->PesananOutstanding($hariIni, $idOutletBoleh, $batas);
        $pemasok = $this->peta->Pemasok(array_column($baris, 'IdPemasok'));
        $gudang = $this->peta->Gudang(array_column($baris, 'IdGudang'));
        $hasil = [];

        foreach ($baris as $b) {
            // Pesanan yang semua barisnya sudah lengkap tetapi belum ditutup tidak lagi menunggu barang.
            if ($b['JumlahBarisSisa'] === 0) {
                continue;
            }

            $hasil[] = [
                'Uuid' => $b['Uuid'],
                'Nomor' => $b['Nomor'],
                'UuidPemasok' => $pemasok[$b['IdPemasok']]['Uuid'] ?? '',
                'NamaPemasok' => $pemasok[$b['IdPemasok']]['Nama'] ?? 'Pemasok dihapus',
                'NamaGudang' => ($gudang[$b['IdGudang']] ?? null)?->nama ?? '',
                'Tanggal' => $b['Tanggal'],
                'PerkiraanTiba' => $b['PerkiraanTiba'],
                'HariTerlambat' => $b['HariTerlambat'],
                'Terlambat' => $b['PerkiraanTiba'] === null ? $b['UmurHari'] > self::HARI_TANPA_PERKIRAAN : $b['HariTerlambat'] > 0,
                'Status' => $b['Status'],
                'LabelStatus' => $b['LabelStatus'],
                'JumlahBarisSisa' => $b['JumlahBarisSisa'],
                'NilaiSisa' => $b['NilaiSisa'],
            ];
        }

        return $hasil;
    }
}
