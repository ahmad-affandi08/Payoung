<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Penjualan;

use App\Domain\Pelanggan\Kueri\IdentitasPajakPelanggan;
use App\Domain\Penjualan\Aksi\BatalkanFakturPajakPenjualan;
use App\Domain\Penjualan\Aksi\TerbitkanFakturPajakPenjualan;
use App\Domain\Penjualan\Aksi\UbahNomorFakturPajakPenjualan;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Penjualan\BatalkanFakturPajakPermintaan;
use App\Http\Permintaan\Kelola\Penjualan\NomorFakturPajakKasirPermintaan;
use App\Http\Permintaan\Kelola\Penjualan\TerbitkanFakturPajakPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Faktur Pajak per transaksi kasir atas permintaan pembeli ber-NPWP/NIK (TAX-07, izin `akuntansi.kelola`): terbitkan
 * dari detail penjualan, isi nomor dari Coretax, dan batalkan. Penjualan di outlet di luar akses pelaku = tidak ditemukan.
 */
final class FakturPajakPenjualanKontroler extends DasarKelolaKontroler
{
    /** Dropdown pembeli: pelanggan aktif beserta status identitas pajaknya (NPWP/NIK tersamar). */
    public function CariPelanggan(Request $permintaan, IdentitasPajakPelanggan $cari): JsonResponse
    {
        return response()->json(['Data' => $cari->Cari((string) $permintaan->query('kata', ''))]);
    }

    public function Terbitkan(TerbitkanFakturPajakPermintaan $permintaan, string $penjualan, TerbitkanFakturPajakPenjualan $terbitkan): RedirectResponse
    {
        $terbitkan->Jalankan(
            $penjualan,
            (string) $permintaan->validated('UuidPelanggan'),
            $permintaan->validated('Catatan'),
            $this->Pelaku()->Id,
            $this->IdOutletBoleh(),
        );

        return to_route('kelola.penjualan.detail', ['penjualan' => $penjualan])->with('Kilat', 'Faktur Pajak dibuat. Ekspor ke Coretax dari Laporan › Pajak, lalu isi nomornya di sini.');
    }

    public function UbahNomor(NomorFakturPajakKasirPermintaan $permintaan, string $penjualan, string $faktur, UbahNomorFakturPajakPenjualan $ubah): RedirectResponse
    {
        $ubah->Jalankan($faktur, $permintaan->validated('NomorFakturPajak'), $this->Pelaku()->Id, $this->IdOutletBoleh());

        return to_route('kelola.penjualan.detail', ['penjualan' => $penjualan])->with('Kilat', 'Nomor Faktur Pajak disimpan.');
    }

    public function Batalkan(BatalkanFakturPajakPermintaan $permintaan, string $penjualan, string $faktur, BatalkanFakturPajakPenjualan $batalkan): RedirectResponse
    {
        $hasil = $batalkan->Jalankan($faktur, (string) $permintaan->validated('Alasan'), $this->Pelaku()->Id, $this->IdOutletBoleh());
        $pesan = $hasil->NomorFakturPajak === null
            ? 'Faktur Pajak dibatalkan.'
            : 'Faktur Pajak dibatalkan di Payoung. Faktur bernomor '.$hasil->NomorFakturPajak.' yang sudah disetujui di Coretax harus dibatalkan atau diganti di Coretax juga.';

        return to_route('kelola.penjualan.detail', ['penjualan' => $penjualan])->with('Kilat', $pesan);
    }
}
