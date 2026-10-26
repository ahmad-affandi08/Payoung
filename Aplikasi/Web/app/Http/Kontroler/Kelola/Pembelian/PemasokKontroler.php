<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\HapusPemasok;
use App\Domain\Pembelian\Aksi\SimpanPemasok;
use App\Domain\Pembelian\Aksi\UbahStatusPemasok;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Http\Permintaan\Kelola\Pembelian\SimpanPemasokPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Master pemasok (F-04 fase 1, `/kelola/pembelian/pemasok`, izin `pembelian.kelola`). */
final class PemasokKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarPemasok $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPemasok::KOLOM_URUT, DaftarPemasok::URUT_BAWAAN, DaftarPemasok::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Pemasok/Daftar', 'Pemasok', fn (): array => $daftar->AmbilTabel($tabel), fn (): array => [
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    /** Halaman penuh "Tambah pemasok" (pola sama dengan Tambah produk); formulir tidak butuh opsi dari server. */
    public function Buat(): Response
    {
        return Inertia::render('Kelola/Pembelian/Pemasok/Buat');
    }

    public function Simpan(SimpanPemasokPermintaan $permintaan, SimpanPemasok $simpan): RedirectResponse
    {
        $pemasok = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id));

        return to_route('kelola.pembelian.pemasok.daftar')->with('Kilat', "Pemasok {$pemasok->Nama} ditambahkan.");
    }

    public function Perbarui(SimpanPemasokPermintaan $permintaan, string $pemasok, SimpanPemasok $simpan): RedirectResponse
    {
        $hasil = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id), $this->CariPemasok($pemasok));

        return back()->with('Kilat', "Pemasok {$hasil->Nama} disimpan.");
    }

    public function UbahStatus(Request $permintaan, string $pemasok, UbahStatusPemasok $ubah): RedirectResponse
    {
        $aktif = $permintaan->boolean('Aktif');
        $hasil = $ubah->Jalankan($this->CariPemasok($pemasok), $aktif, $this->Pelaku()->Id);

        return back()->with('Kilat', $aktif ? "Pemasok {$hasil->Nama} diaktifkan." : "Pemasok {$hasil->Nama} dinonaktifkan. Tidak bisa dipilih di dokumen baru.");
    }

    public function Hapus(string $pemasok, HapusPemasok $hapus): RedirectResponse
    {
        $data = $this->CariPemasok($pemasok);
        $hapus->Jalankan($data, $this->Pelaku()->Id);

        return back()->with('Kilat', "Pemasok {$data->Nama} dihapus.");
    }
}
