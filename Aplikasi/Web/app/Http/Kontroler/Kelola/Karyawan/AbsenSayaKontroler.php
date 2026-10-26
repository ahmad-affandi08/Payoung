<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Karyawan\Aksi\AjukanKoreksiAbsensi;
use App\Domain\Karyawan\Aksi\CatatAbsensiWeb;
use App\Domain\Karyawan\Aksi\DaftarkanWajahKaryawan;
use App\Domain\Karyawan\Kueri\AbsenSaya;
use App\Domain\Karyawan\Kueri\CekKecocokanWajah;
use App\Domain\Karyawan\Kueri\StatusAbsensiWeb;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Absensi\PembacaKirimanAbsensi;
use App\Http\Respons\ResponsPwaAbsensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Halaman Absen berlogin (F-18 bagian 4, D-37, D-180) `/kelola/absen`: karyawan masuk dengan akunnya (email + kata sandi
 * atau Google), mendaftarkan wajah sekali, lalu absen masuk/keluar dengan satu tombol (lokasi GPS + pindai wajah).
 * Mobile-first. Tanpa izin back-office: yang dibutuhkan hanya karyawan aktif yang ikut absensi dan tertaut ke akunnya
 * (selain itu 404, sama seperti halaman yang tidak ada). Kiriman berbentuk JSON dari halaman; galat memakai format
 * galat seragam.
 */
final class AbsenSayaKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(AbsenSaya $saya, StatusAbsensiWeb $status, NamaTampilUsaha $nama, AksesPengguna $akses): Response
    {
        $karyawan = $this->CariKaryawan($saya);
        $izin = $akses->Ambil($this->IdTenant(), $this->Pelaku()->Id);

        return Inertia::render('Kelola/Absen/Saya', [
            'NamaToko' => $nama->UntukTenant($this->IdTenant()),
            'NamaKaryawan' => $karyawan->Nama,
            'AlamatModelWajah' => asset('model-wajah'),
            'AlamatDasar' => '/kelola/absen',
            'Berlogin' => true,
            // Staf yang hanya bisa absen tidak punya menu lain; yang punya akses dashboard diberi jalan ke sana.
            'BisaDashboard' => $izin !== null && ($izin['Pemilik'] || $izin['Izin'] !== []),
            ...$status->Ambil($karyawan),
        ]);
    }

    /** PWA (D-211): manifest halaman Absen berlogin, supaya bisa dipasang ke layar utama HP. */
    public function Manifest(AbsenSaya $saya, NamaTampilUsaha $nama): JsonResponse
    {
        return ResponsPwaAbsensi::Manifest('/kelola/absen', $nama->UntukTenant($this->IdTenant()), $this->CariKaryawan($saya)->Nama);
    }

    public function PekerjaLayanan(): SymfonyResponse
    {
        return ResponsPwaAbsensi::PekerjaLayanan('/kelola/absen');
    }

    public function DaftarWajah(Request $permintaan, AbsenSaya $saya, DaftarkanWajahKaryawan $daftar, PembacaKirimanAbsensi $pembaca): JsonResponse
    {
        $karyawan = $this->CariKaryawan($saya);
        $valid = $pembaca->BacaPendaftaranWajah($permintaan);
        $wajah = $daftar->Jalankan($karyawan, $valid['SidikWajah'], $valid['Foto'], $valid['Persetujuan']);

        return response()->json(['Status' => $wajah->Status->value, 'Pesan' => 'Wajah terdaftar. Anda sudah bisa absen.'], 201);
    }

    /**
     * Umpan balik langsung saat kamera menyala (D-186): cocok atau tidak dengan wajah terdaftar. Hanya ya/tidak.
     * Bukan keputusan absen; itu tetap diambil di Masuk/Keluar.
     */
    public function CekWajah(Request $permintaan, AbsenSaya $saya, CekKecocokanWajah $cek, PembacaKirimanAbsensi $pembaca): JsonResponse
    {
        return response()->json(['Cocok' => $cek->Jalankan($this->CariKaryawan($saya), $pembaca->BacaSidikCekWajah($permintaan))]);
    }

    public function Masuk(Request $permintaan, AbsenSaya $saya, CatatAbsensiWeb $catat, PembacaKirimanAbsensi $pembaca): JsonResponse
    {
        $absensi = $catat->Masuk($this->CariKaryawan($saya), $pembaca->BacaDataAbsen($permintaan));

        return response()->json(['Uuid' => $absensi->Uuid, 'MasukPada' => $absensi->MasukPada->toIso8601String(), 'JarakMeter' => $absensi->JarakMasukMeter, 'PerluDicek' => $absensi->PerluDicek]);
    }

    public function Keluar(Request $permintaan, AbsenSaya $saya, CatatAbsensiWeb $catat, PembacaKirimanAbsensi $pembaca): JsonResponse
    {
        $absensi = $catat->Keluar($this->CariKaryawan($saya), $pembaca->BacaDataAbsen($permintaan));

        return response()->json(['Uuid' => $absensi->Uuid, 'KeluarPada' => $absensi->KeluarPada?->toIso8601String(), 'JarakMeter' => $absensi->JarakKeluarMeter, 'PerluDicek' => $absensi->PerluDicek]);
    }

    /** "Minta koreksi ke manajer": absen terus gagal, karyawan menulis alasannya; manajer yang mencatat jamnya. */
    public function MintaKoreksi(Request $permintaan, AbsenSaya $saya, AjukanKoreksiAbsensi $ajukan, PembacaKirimanAbsensi $pembaca): JsonResponse
    {
        $valid = $pembaca->BacaPermintaanKoreksi($permintaan);
        $baris = $ajukan->Jalankan($this->CariKaryawan($saya), $valid['Jenis'], $valid['Alasan']);

        return response()->json(['Uuid' => $baris->Uuid, 'Status' => $baris->Status->value], 201);
    }

    /** Karyawan pengguna ini yang wajib absen; selain itu halaman dianggap tidak ada. */
    private function CariKaryawan(AbsenSaya $saya): Karyawan
    {
        $karyawan = $saya->Cari($this->Pelaku()->Id);
        abort_if($karyawan === null, 404);

        return $karyawan;
    }
}
