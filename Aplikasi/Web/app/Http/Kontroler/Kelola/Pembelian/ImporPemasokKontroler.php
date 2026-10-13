<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Katalog\Impor\Layanan\PembacaBerkasTabel;
use App\Domain\Katalog\Impor\Layanan\PenulisTabel;
use App\Domain\Pembelian\Aksi\ImporPemasok;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor pemasok dari Excel/CSV (PLT-10, F31), izin `pembelian.kelola`. Dua langkah: periksa (tanpa menyimpan) lalu terapkan
 * berkas yang sama; hasilnya dikirim lewat sesi kilat ke halaman.
 */
final class ImporPemasokKontroler extends DasarKelolaKontroler
{
    public function Halaman(Request $permintaan): Response
    {
        $hasil = $permintaan->session()->get('HasilImporPemasok');

        return Inertia::render('Kelola/Pembelian/Pemasok/Impor', [
            'Hasil' => is_array($hasil) ? $hasil : null,
            'MaksimalBaris' => ImporPemasok::MAKSIMAL_BARIS,
        ]);
    }

    public function Kirim(Request $permintaan, ImporPemasok $impor): RedirectResponse
    {
        $data = $permintaan->validate([
            'Berkas' => ['required', 'file', 'max:5120'],
            'Terapkan' => ['sometimes', 'boolean'],
        ], [
            'Berkas.required' => 'Pilih berkas Excel (.xlsx) atau CSV.',
            'Berkas.max' => 'Ukuran berkas paling besar 5 MB.',
            'Berkas.*' => 'Berkas tidak bisa dibaca. Unggah ulang.',
        ]);
        /** @var UploadedFile $berkas */
        $berkas = $data['Berkas'];
        $terapkan = (bool) ($data['Terapkan'] ?? false);
        $hasil = $impor->Jalankan((string) $berkas->getRealPath(), $berkas->getClientOriginalName(), $terapkan, $this->Pelaku()->Id);
        $kembali = to_route('kelola.pembelian.pemasok.impor')->with('HasilImporPemasok', [...$hasil, 'NamaBerkas' => mb_substr($berkas->getClientOriginalName(), 0, 120)]);

        return $terapkan ? $kembali->with('Kilat', "{$hasil['Baru']} pemasok baru diimpor.") : $kembali;
    }

    public function Templat(): StreamedResponse
    {
        return PenulisTabel::Alirkan(PembacaBerkasTabel::FORMAT_XLSX, 'TemplatImporPemasok', ['Kode', 'Nama', 'NamaKontak', 'NoHp', 'Email', 'Alamat', 'Npwp', 'Pkp', 'TerminHari', 'NamaBank', 'NomorRekening', 'AtasNamaRekening', 'Catatan'], fn (): array => [
            ['PMS-0001', 'CV Sumber Pangan Makmur', 'Budi Santoso', '0271-555-0123', 'penjualan@sumberpangan.co.id', 'Jl. Raya Solo-Semarang Km 8, Boyolali', '01.234.567.8-901.000', 'Ya', '30', 'BCA', '1234567890', 'CV Sumber Pangan Makmur', 'Kirim Senin & Kamis'],
        ]);
    }
}
