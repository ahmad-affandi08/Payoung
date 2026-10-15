<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Integrasi;

use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Pengelola\Integrasi\Aksi\BuatKunciSnapIntegrasi;
use App\Domain\Pengelola\Integrasi\Aksi\SimpanKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Aksi\UbahIzinPenyediaGerbang;
use App\Domain\Pengelola\Integrasi\Aksi\UbahStatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Aksi\UjiKoneksiIntegrasi;
use App\Domain\Pengelola\Integrasi\Kueri\DaftarIntegrasi;
use App\Domain\Pengelola\Integrasi\Kueri\RingkasanGerbangTenant;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Integrasi\SimpanKonfigurasiIntegrasiPermintaan;
use App\Http\Permintaan\Pengelola\Integrasi\UbahIzinPenyediaGerbangPermintaan;
use App\Http\Permintaan\Pengelola\Integrasi\UbahStatusIntegrasiPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Konfigurasi integrasi platform (P-05) dan katalog gerbang pembayaran untuk tenant (v2.06).
 */
final class IntegrasiKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(DaftarIntegrasi $kueri, RingkasanGerbangTenant $gerbang): Response
    {
        return Inertia::render('Pengelola/Integrasi/Daftar', ['Integrasi' => $kueri->Ambil(), 'GerbangTenant' => $gerbang->Ambil()]);
    }

    /** v2.06: izinkan/larang penyedia gerbang pembayaran untuk tenant. */
    public function UbahIzinGerbang(string $penyedia, UbahIzinPenyediaGerbangPermintaan $permintaan, UbahIzinPenyediaGerbang $ubah): RedirectResponse
    {
        $gerbang = PenyediaGerbang::from($penyedia);
        $diizinkan = $permintaan->boolean('Diizinkan');
        $ubah->Jalankan($this->AmbilPelaku(), $gerbang, $diizinkan, $permintaan->AmbilAlasan());

        return back()->with('Kilat', $diizinkan
            ? "{$gerbang->AmbilLabel()} bisa dipilih tenant."
            : "{$gerbang->AmbilLabel()} dilarang. Tenant yang memakainya tidak bisa membuat tagihan QRIS baru.");
    }

    public function Simpan(SimpanKonfigurasiIntegrasiPermintaan $permintaan, SimpanKonfigurasiIntegrasi $simpan): RedirectResponse
    {
        $konfigurasi = $simpan->Jalankan($this->AmbilPelaku(), $permintaan->AmbilData());

        return back()->with('Kilat', "{$konfigurasi->Jenis->AmbilLabel()} ({$konfigurasi->Lingkungan->value}) disimpan. Uji koneksi sebelum mengaktifkan.");
    }

    public function Uji(KonfigurasiIntegrasi $konfigurasiIntegrasi, UjiKoneksiIntegrasi $uji): RedirectResponse
    {
        $hasil = $uji->Jalankan($konfigurasiIntegrasi, $this->AmbilPelaku())['Hasil'];

        return $hasil->berhasil
            ? back()->with('Kilat', "Koneksi berhasil. {$hasil->pesan}")
            : back()->withErrors(['Umum' => "Koneksi gagal. {$hasil->pesan}"]);
    }

    /** D-159: buat/ganti pasangan kunci RSA SNAP; kunci publiknya tampil di kartu untuk didaftarkan di dasbor DOKU. */
    public function BuatKunciSnap(KonfigurasiIntegrasi $konfigurasiIntegrasi, BuatKunciSnapIntegrasi $buat): RedirectResponse
    {
        $buat->Jalankan($this->AmbilPelaku(), $konfigurasiIntegrasi);

        return back()->with('Kilat', 'Kunci SNAP dibuat. Salin kunci publiknya ke dasbor DOKU › API Keys › Merchant Public Key, lalu uji koneksi.');
    }

    public function Aktifkan(KonfigurasiIntegrasi $konfigurasiIntegrasi, UbahStatusIntegrasiPermintaan $permintaan, UbahStatusIntegrasi $ubah): RedirectResponse
    {
        $konfigurasi = $ubah->Jalankan($this->AmbilPelaku(), $konfigurasiIntegrasi, true, $permintaan->AmbilAlasan());

        return back()->with('Kilat', "{$konfigurasi->Jenis->AmbilLabel()} ({$konfigurasi->Lingkungan->value}) aktif.");
    }

    public function Nonaktifkan(KonfigurasiIntegrasi $konfigurasiIntegrasi, UbahStatusIntegrasiPermintaan $permintaan, UbahStatusIntegrasi $ubah): RedirectResponse
    {
        $konfigurasi = $ubah->Jalankan($this->AmbilPelaku(), $konfigurasiIntegrasi, false, $permintaan->AmbilAlasan());

        return back()->with('Kilat', "{$konfigurasi->Jenis->AmbilLabel()} ({$konfigurasi->Lingkungan->value}) dinonaktifkan.");
    }
}
