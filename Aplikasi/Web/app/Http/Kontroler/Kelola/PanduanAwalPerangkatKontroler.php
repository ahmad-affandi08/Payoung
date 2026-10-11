<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Organisasi\Aksi\BuatKodeAktivasi;
use App\Domain\Organisasi\Aksi\BuatPerangkat;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\DaftarPerangkat;
use App\Domain\Organisasi\Kueri\DaftarPinAnggota;
use App\Domain\Organisasi\Kueri\PemakaianPerangkat;
use App\Domain\Organisasi\Layanan\PembuatQrKodeAktivasi;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\PanduanAwal\SimpanPerangkatPanduanPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F-01 langkah 6: perangkat kasir di outlet wizard, memakai Aksi F-02b (`BuatPerangkat`, `BuatKodeAktivasi`) dan kartu
 * kode aktivasi + QR. Kode asli hanya ada di flash sesi sekali tampil. Tes cetak dilakukan di Aplikasi Kasir.
 */
final class PanduanAwalPerangkatKontroler extends DasarPanduanAwalKontroler
{
    private const KUNCI_KODE_BARU = 'KodeAktivasiBaru';

    public function Tampilkan(Request $permintaan, DaftarPerangkat $daftar, PemakaianPerangkat $pemakaian, PastikanBatasPaket $batasPaket, PembuatQrKodeAktivasi $qr, AksesPengguna $akses, DaftarPinAnggota $pin): Response
    {
        $outlet = $this->OutletPanduan();
        $kodeBaru = $permintaan->session()->get(self::KUNCI_KODE_BARU);

        return Inertia::render('Kelola/PanduanAwal/Perangkat', [
            'Progres' => $this->Progres(),
            'Outlet' => [
                'Uuid' => $outlet->Uuid,
                'Kode' => $outlet->Kode,
                'Nama' => $outlet->Nama,
                'BatasPerangkat' => $batasPaket->AmbilRingkasan($this->IdTenant(), 'BatasPerangkatPerOutlet', $pemakaian->HitungAktifDiOutlet($outlet->Id)),
            ],
            'Perangkat' => array_map(fn (array $perangkat): array => [
                'Uuid' => $perangkat['Uuid'],
                'Kode' => $perangkat['Kode'],
                'Nama' => $perangkat['Nama'],
                'LabelJenis' => $perangkat['LabelJenis'],
                'Status' => $perangkat['Status'],
            ], $daftar->Ambil([$outlet->Id])),
            'KodeAktivasiBaru' => is_array($kodeBaru) && is_string($kodeBaru['Kode'] ?? null) ? [
                ...$kodeBaru,
                'QrSvg' => $qr->BuatSvg($kodeBaru['Kode']),
            ] : null,
            'BolehKelolaPerangkat' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::PerangkatKelola),
            // Audit kemudahan pakai #2: PIN kasir pengguna sendiri diminta di sini, bukan baru gagal di aplikasi kasir.
            'PinSayaDiatur' => $pin->CekPinDiatur($this->IdTenant(), $this->Pelaku()->Id),
        ]);
    }

    public function Simpan(SimpanPerangkatPanduanPermintaan $permintaan, BuatPerangkat $buat): RedirectResponse
    {
        $hasil = $buat->Jalankan($this->OutletPanduan(), $permintaan->string('Nama')->toString(), JenisPerangkat::Kasir, $this->Pelaku()->Id);

        return $this->KembaliDenganKode($hasil['Perangkat'], $hasil['Kode'], $hasil['KedaluwarsaPada']);
    }

    public function BuatKodeAktivasi(string $perangkat, DaftarPerangkat $daftar, BuatKodeAktivasi $buatKode): RedirectResponse
    {
        $baris = $daftar->CariDiOutlet($perangkat, $this->OutletPanduan()->Id) ?? abort(404);
        $kode = $buatKode->Jalankan($baris, $this->Pelaku()->Id);

        return $this->KembaliDenganKode($baris, $kode['Kode'], $kode['KedaluwarsaPada']);
    }

    private function KembaliDenganKode(Perangkat $perangkat, string $kode, Carbon $kedaluwarsa): RedirectResponse
    {
        return redirect()->route('kelola.panduan-awal.perangkat')
            ->with(self::KUNCI_KODE_BARU, [
                'UuidPerangkat' => $perangkat->Uuid,
                'NamaPerangkat' => $perangkat->Nama,
                'KodePerangkat' => $perangkat->Kode,
                'Kode' => $kode,
                'KedaluwarsaPada' => $kedaluwarsa->toIso8601String(),
            ]);
    }
}
