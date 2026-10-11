<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Kasir;

use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Kasir\Aksi\SimpanKategoriKas;
use App\Domain\Kasir\Aksi\UbahStatusKategoriKas;
use App\Domain\Kasir\Kueri\DaftarKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Kasir\SimpanKategoriKasPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman kategori kas masuk/keluar (F-06), izin `akuntansi.kelola` karena setiap kategori dipetakan ke akun.
 * Kategori tenant lain = 404.
 */
final class KategoriKasKontroler extends DasarKelolaKontroler
{
    public function Daftar(DaftarKategoriKas $daftar): Response
    {
        return Inertia::render('Kelola/Kasir/KategoriKas', $daftar->Ambil());
    }

    public function Simpan(SimpanKategoriKasPermintaan $permintaan, SimpanKategoriKas $simpan): RedirectResponse
    {
        $kategori = $simpan->Jalankan($permintaan->AmbilData());

        return to_route('kelola.kasir.kategori-kas')->with('Kilat', "Kategori \"{$kategori->Nama}\" disimpan.");
    }

    /** D-106: tambah kategori biaya umum (Listrik, Transport, dst.) beserta akun bebannya; aditif, tidak menimpa. */
    public function SiapkanBiayaUmum(SiapkanKategoriBiayaBawaan $siapkan): RedirectResponse
    {
        $jumlah = $siapkan->Jalankan();

        return to_route('kelola.kasir.kategori-kas')->with('Kilat', $jumlah === 0
            ? 'Kategori biaya umum sudah lengkap, tidak ada yang perlu ditambahkan.'
            : "{$jumlah} kategori biaya umum ditambahkan. Ubah atau nonaktifkan yang tidak perlu.");
    }

    public function Perbarui(string $kategoriKas, SimpanKategoriKasPermintaan $permintaan, SimpanKategoriKas $simpan): RedirectResponse
    {
        $kategori = $simpan->Jalankan($permintaan->AmbilData(), $this->Cari($kategoriKas));

        return to_route('kelola.kasir.kategori-kas')->with('Kilat', "Kategori \"{$kategori->Nama}\" disimpan.");
    }

    public function UbahStatus(string $kategoriKas, Request $permintaan, UbahStatusKategoriKas $ubah): RedirectResponse
    {
        $permintaan->validate(['Aktif' => ['required', 'boolean']]);
        $kategori = $this->Cari($kategoriKas);
        $ubah->Jalankan($kategori, $permintaan->boolean('Aktif'));

        return to_route('kelola.kasir.kategori-kas')->with('Kilat', $kategori->Aktif ? "Kategori \"{$kategori->Nama}\" diaktifkan." : "Kategori \"{$kategori->Nama}\" dinonaktifkan.");
    }

    private function Cari(string $uuid): KategoriKas
    {
        return KategoriKas::query()->where('Uuid', $uuid)->firstOrFail();
    }
}
