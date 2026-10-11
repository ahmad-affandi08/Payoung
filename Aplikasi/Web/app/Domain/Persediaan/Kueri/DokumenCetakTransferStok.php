<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Persediaan\Model\TransferStok;

/**
 * Data cetak surat jalan transfer stok (F-05b, D-94): barang yang dikirim dari satu lokasi ke lokasi lain milik usaha yang
 * sama. **Tanpa nilai**: kertas ini dipegang pembawa barang dan dicocokkan petugas gudang tujuan, yang hanya perlu barang &
 * jumlah, jadi harga pokok dan nilai kirim dari `DetailTransferStok` sengaja tidak diteruskan.
 */
final class DokumenCetakTransferStok
{
    public function __construct(private readonly DetailTransferStok $detail) {}

    /**
     * @return array{Transfer: array<string, mixed>, Baris: list<array<string, mixed>>}
     */
    public function Ambil(TransferStok $t): array
    {
        $isi = $this->detail->Ambil($t);
        $transfer = $isi['Transfer'];

        return [
            'Transfer' => [
                'Nomor' => $transfer['Nomor'],
                'Status' => $transfer['Status'],
                'LabelStatus' => $transfer['LabelStatus'],
                'Tanggal' => $transfer['Tanggal'],
                'NamaGudangAsal' => $transfer['NamaGudangAsal'],
                'NamaOutletAsal' => $transfer['NamaOutletAsal'],
                'NamaGudangTujuan' => $transfer['NamaGudangTujuan'],
                'NamaOutletTujuan' => $transfer['NamaOutletTujuan'],
                'Catatan' => $transfer['Catatan'],
                'AlasanBatal' => $transfer['AlasanBatal'],
                'NamaPengirim' => $transfer['DikirimOleh'],
                'DikirimPada' => $transfer['DikirimPada'],
            ],
            'Baris' => array_map(static fn (array $b): array => [
                'Urutan' => $b['Urutan'],
                'NamaProduk' => $b['NamaProduk'],
                'Sku' => $b['Sku'],
                'SimbolSatuan' => $b['SimbolSatuan'],
                'Jumlah' => $b['JumlahDikirim'],
                'NomorBatch' => $b['NomorBatch'],
                'TanggalKedaluwarsa' => $b['TanggalKedaluwarsa'],
                'NomorSeri' => $b['NomorSeri'],
            ], $isi['Baris']),
        ];
    }
}
