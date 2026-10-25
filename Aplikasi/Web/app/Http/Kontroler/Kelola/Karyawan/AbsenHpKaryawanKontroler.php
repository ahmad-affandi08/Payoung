<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Aksi\AturTautanAbsen;
use App\Domain\Karyawan\Aksi\HapusWajahKaryawan;
use App\Domain\Karyawan\Aksi\TinjauWajahKaryawan;
use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Kueri\AbsenHpKaryawan;
use App\Domain\Karyawan\Layanan\PenyimpanSwafoto;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-18 bagian 4 (D-37, D-180, D-198) panel absen karyawan (`/kelola/karyawan/{uuid}/absen-hp`, izin `karyawan.kelola`): buat
 * ulang / cabut tautan absen pribadi, lihat foto pendaftaran wajah, setujui / tolak (beralasan) / atur ulang wajah.
 * Karyawan tenant lain = 404 (`MilikTenant`).
 */
final class AbsenHpKaryawanKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(string $karyawan, AbsenHpKaryawan $kueri): JsonResponse
    {
        return response()->json($kueri->Ambil($this->CariKaryawan($karyawan)));
    }

    public function BuatTautan(string $karyawan, AturTautanAbsen $atur): RedirectResponse
    {
        $baris = $this->CariKaryawan($karyawan);
        $atur->Jalankan($baris, true, $this->Pelaku()->Id);

        return back()->with('Kilat', "Tautan absen {$baris->Nama} dibuat. Tautan lama tidak berlaku lagi.");
    }

    public function CabutTautan(string $karyawan, AturTautanAbsen $atur): RedirectResponse
    {
        $baris = $this->CariKaryawan($karyawan);
        $atur->Jalankan($baris, false, $this->Pelaku()->Id);

        return back()->with('Kilat', "Tautan absen {$baris->Nama} dicabut.");
    }

    public function Tinjau(Request $permintaan, string $karyawan, TinjauWajahKaryawan $tinjau): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Setujui' => ['required', 'boolean'],
            'Alasan' => ['nullable', 'string', 'max:200'],
        ]);
        $baris = $this->CariKaryawan($karyawan);
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $baris->Id)->whereIn('Status', [StatusWajahKaryawan::Menunggu->value, StatusWajahKaryawan::Disetujui->value])->first()
            ?? throw new PelanggaranAturanBisnis('WajahTidakMenunggu', 'Karyawan ini belum punya wajah terdaftar yang bisa ditinjau.', 'Wajah');
        $setujui = (bool) $valid['Setujui'];
        $tinjau->Jalankan($wajah, $setujui, is_string($valid['Alasan'] ?? null) ? $valid['Alasan'] : null, $this->Pelaku()->Id);

        return back()->with('Kilat', $setujui
            ? "Wajah {$baris->Nama} disetujui. Ia sudah bisa absen dari HP."
            : "Wajah {$baris->Nama} ditolak. Ia bisa mendaftar ulang dari halaman Absen.");
    }

    public function HapusWajah(string $karyawan, HapusWajahKaryawan $hapus): RedirectResponse
    {
        $baris = $this->CariKaryawan($karyawan);
        $hapus->Jalankan($baris, $this->Pelaku()->Id);

        return back()->with('Kilat', "Wajah {$baris->Nama} dihapus. Ia perlu mendaftarkan wajah lagi sebelum absen dari HP.");
    }

    /** Foto pendaftaran wajah ke-[indeks] (data biometrik: hanya `karyawan.kelola`, tidak di-cache bersama). */
    public function Foto(string $karyawan, int $indeks, PenyimpanSwafoto $penyimpan): StreamedResponse
    {
        $baris = $this->CariKaryawan($karyawan);
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $baris->Id)->latest('Id')->first();

        return $penyimpan->Unduh($wajah?->PathFoto[$indeks] ?? null);
    }

    private function CariKaryawan(string $uuid): Karyawan
    {
        return Karyawan::query()->where('Uuid', $uuid)->firstOrFail();
    }
}
