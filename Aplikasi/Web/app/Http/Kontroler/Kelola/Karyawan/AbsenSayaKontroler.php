<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Karyawan\Aksi\AjukanKoreksiAbsensi;
use App\Domain\Karyawan\Aksi\CatatAbsensiWeb;
use App\Domain\Karyawan\Aksi\DaftarkanWajahKaryawan;
use App\Domain\Karyawan\Data\DataAbsensiWeb;
use App\Domain\Karyawan\Kueri\AbsenSaya;
use App\Domain\Karyawan\Kueri\StatusAbsensiWeb;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Absen berlogin (F-18 bagian 4, D-37, D-180) `/kelola/absen`: karyawan masuk dengan akunnya (email + kata sandi
 * atau Google), mendaftarkan wajah sekali, lalu absen masuk/keluar dengan satu tombol (lokasi GPS + pindai wajah).
 * Mobile-first. Tanpa izin back-office: yang dibutuhkan hanya karyawan aktif yang ikut absensi dan tertaut ke akunnya
 * (selain itu 404, sama seperti halaman yang tidak ada). Kiriman berbentuk JSON dari halaman; galat memakai format
 * galat seragam.
 */
final class AbsenSayaKontroler extends DasarKelolaKontroler
{
    /** Pola derajat desimal (lintang & bujur) dari Geolocation API. */
    private const POLA_KOORDINAT = '/^-?\d{1,3}(\.\d{1,12})?$/';

    public function Tampilkan(AbsenSaya $saya, StatusAbsensiWeb $status, NamaTampilUsaha $nama, AksesPengguna $akses): Response
    {
        $karyawan = $this->CariKaryawan($saya);
        $izin = $akses->Ambil($this->IdTenant(), $this->Pelaku()->Id);

        return Inertia::render('Kelola/Absen/Saya', [
            'NamaToko' => $nama->UntukTenant($this->IdTenant()),
            'NamaKaryawan' => $karyawan->Nama,
            'AlamatModelWajah' => asset('model-wajah'),
            // Staf yang hanya bisa absen tidak punya menu lain; yang punya akses dashboard diberi jalan ke sana.
            'BisaDashboard' => $izin !== null && ($izin['Pemilik'] || $izin['Izin'] !== []),
            ...$status->Ambil($karyawan),
        ]);
    }

    public function DaftarWajah(Request $permintaan, AbsenSaya $saya, DaftarkanWajahKaryawan $daftar): JsonResponse
    {
        $karyawan = $this->CariKaryawan($saya);
        $valid = $permintaan->validate([
            'SidikWajah' => ['required', 'array', 'max:'.(int) config('karyawan.JumlahFotoDaftarWajah')],
            'SidikWajah.*' => ['array', 'max:1024'],
            'SidikWajah.*.*' => ['integer'],
            'Foto' => ['required', 'array', 'max:'.(int) config('karyawan.JumlahFotoDaftarWajah')],
            'Foto.*' => ['string', 'max:450000'],
            'Persetujuan' => ['required', 'boolean'],
        ]);
        $wajah = $daftar->Jalankan($karyawan, array_values((array) $valid['SidikWajah']), array_values((array) $valid['Foto']), (bool) $valid['Persetujuan']);

        return response()->json(['Status' => $wajah->Status->value, 'Pesan' => 'Wajah terdaftar. Anda sudah bisa absen.'], 201);
    }

    public function Masuk(Request $permintaan, AbsenSaya $saya, CatatAbsensiWeb $catat): JsonResponse
    {
        $absensi = $catat->Masuk($this->CariKaryawan($saya), $this->AmbilData($permintaan));

        return response()->json(['Uuid' => $absensi->Uuid, 'MasukPada' => $absensi->MasukPada->toIso8601String(), 'JarakMeter' => $absensi->JarakMasukMeter, 'PerluDicek' => $absensi->PerluDicek]);
    }

    public function Keluar(Request $permintaan, AbsenSaya $saya, CatatAbsensiWeb $catat): JsonResponse
    {
        $absensi = $catat->Keluar($this->CariKaryawan($saya), $this->AmbilData($permintaan));

        return response()->json(['Uuid' => $absensi->Uuid, 'KeluarPada' => $absensi->KeluarPada?->toIso8601String(), 'JarakMeter' => $absensi->JarakKeluarMeter, 'PerluDicek' => $absensi->PerluDicek]);
    }

    /** "Minta koreksi ke manajer": absen terus gagal, karyawan menulis alasannya; manajer yang mencatat jamnya. */
    public function MintaKoreksi(Request $permintaan, AbsenSaya $saya, AjukanKoreksiAbsensi $ajukan): JsonResponse
    {
        $valid = $permintaan->validate([
            'Jenis' => ['required', 'string', 'in:Masuk,Keluar'],
            'Alasan' => ['required', 'string', 'max:200'],
        ]);
        $baris = $ajukan->Jalankan($this->CariKaryawan($saya), (string) $valid['Jenis'], (string) $valid['Alasan']);

        return response()->json(['Uuid' => $baris->Uuid, 'Status' => $baris->Status->value], 201);
    }

    /** Karyawan pengguna ini yang wajib absen; selain itu halaman dianggap tidak ada. */
    private function CariKaryawan(AbsenSaya $saya): Karyawan
    {
        $karyawan = $saya->Cari($this->Pelaku()->Id);
        abort_if($karyawan === null, 404);

        return $karyawan;
    }

    private function AmbilData(Request $permintaan): DataAbsensiWeb
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'Lintang' => ['required', 'string', 'regex:'.self::POLA_KOORDINAT],
            'Bujur' => ['required', 'string', 'regex:'.self::POLA_KOORDINAT],
            'AkurasiMeter' => ['required', 'integer', 'min:0', 'max:100000'],
            'SidikWajah' => ['required', 'array', 'max:1024'],
            'SidikWajah.*' => ['integer'],
            // Hanya dikirim halaman bila aturan menyimpan foto bukti; server yang memastikan wajib-tidaknya.
            'Swafoto' => ['nullable', 'string', 'max:450000'],
        ]);
        $lintang = BigDecimal::of((string) $valid['Lintang']);
        $bujur = BigDecimal::of((string) $valid['Bujur']);

        if ($lintang->abs()->isGreaterThan(90) || $bujur->abs()->isGreaterThan(180)) {
            abort(422, 'Koordinat di luar jangkauan.');
        }

        return new DataAbsensiWeb(
            uuid: (string) $valid['Uuid'],
            lintang: (string) $lintang->toScale(7, RoundingMode::HalfUp),
            bujur: (string) $bujur->toScale(7, RoundingMode::HalfUp),
            akurasiMeter: (int) $valid['AkurasiMeter'],
            sidikWajah: array_values((array) $valid['SidikWajah']),
            swafoto: is_string($valid['Swafoto'] ?? null) ? $valid['Swafoto'] : null,
        );
    }
}
