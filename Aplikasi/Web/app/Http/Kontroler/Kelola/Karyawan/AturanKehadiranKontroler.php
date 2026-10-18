<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Karyawan\Aksi\SimpanAturanKehadiran;
use App\Domain\Karyawan\Kueri\AturanKehadiranTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aturan kehadiran tenant (F-18 bagian 5, D-44), `/kelola/karyawan/aturan-kehadiran`: bagaimana jadwal kerja dipakai
 * absensi (wajib berjadwal, batas masuk awal, toleransi, ambang lembur) dan notifikasi WhatsApp (pengingat shift,
 * peringatan terlambat/belum masuk). Lihat `karyawan.lihat`; ubah `karyawan.kelola`.
 */
final class AturanKehadiranKontroler extends DasarKelolaKontroler
{
    public function Tampil(AturanKehadiranTenant $aturan, PemeriksaFiturTenant $fitur): Response
    {
        return Inertia::render('Kelola/Karyawan/AturanKehadiran', [
            'Aturan' => $aturan->Ambil(),
            'WhatsappAktif' => $fitur->CekAktif($this->IdTenant(), PemeriksaFiturTenant::KUNCI_WHATSAPP),
            'Izin' => ['Kelola' => app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::KaryawanKelola)],
        ]);
    }

    public function Simpan(Request $permintaan, SimpanAturanKehadiran $simpan, AturanKehadiranTenant $aturan): RedirectResponse
    {
        $saatIni = $aturan->Ambil();
        $data = $permintaan->validate([
            'WajibJadwal' => ['required', 'boolean'],
            'MasukPalingAwalMenit' => ['required', 'integer'],
            'ToleransiTerlambatMenit' => ['required', 'integer'],
            'ToleransiPulangCepatMenit' => ['required', 'integer'],
            'LemburSetelahMenit' => ['required', 'integer'],
            'PengingatShiftAktif' => ['required', 'boolean'],
            'PengingatShiftMenitSebelum' => ['required', 'integer'],
            'PeringatanPengelolaAktif' => ['required', 'boolean'],
            'PeringatanPengelolaSetelahMenit' => ['required', 'integer'],
            // D-180: tidak dikirim = nilai tersimpan tidak berubah.
            'SimpanFotoBukti' => ['sometimes', 'boolean'],
            'TolakDiLuarRadius' => ['sometimes', 'boolean'],
        ], [
            '*.required' => 'Lengkapi isian ini.',
            '*.integer' => 'Isi dengan bilangan bulat (menit).',
        ]);
        $simpan->Jalankan([
            'WajibJadwal' => (bool) $data['WajibJadwal'],
            'MasukPalingAwalMenit' => (int) $data['MasukPalingAwalMenit'],
            'ToleransiTerlambatMenit' => (int) $data['ToleransiTerlambatMenit'],
            'ToleransiPulangCepatMenit' => (int) $data['ToleransiPulangCepatMenit'],
            'LemburSetelahMenit' => (int) $data['LemburSetelahMenit'],
            'PengingatShiftAktif' => (bool) $data['PengingatShiftAktif'],
            'PengingatShiftMenitSebelum' => (int) $data['PengingatShiftMenitSebelum'],
            'PeringatanPengelolaAktif' => (bool) $data['PeringatanPengelolaAktif'],
            'PeringatanPengelolaSetelahMenit' => (int) $data['PeringatanPengelolaSetelahMenit'],
            'SimpanFotoBukti' => (bool) ($data['SimpanFotoBukti'] ?? $saatIni['SimpanFotoBukti']),
            'TolakDiLuarRadius' => (bool) ($data['TolakDiLuarRadius'] ?? $saatIni['TolakDiLuarRadius']),
        ], $this->Pelaku()->Id);

        return back()->with('Kilat', 'Aturan kehadiran disimpan.');
    }
}
