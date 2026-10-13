<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Karyawan\Aksi\KelolaCutiKaryawan;
use App\Domain\Karyawan\Enum\JenisCuti;
use App\Domain\Karyawan\Kueri\DaftarCutiKaryawan;
use App\Domain\Karyawan\Kueri\DaftarKaryawan;
use App\Domain\Karyawan\Model\CutiKaryawan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Cuti/izin/sakit karyawan (F-18, EMP-03, `/kelola/karyawan/cuti`): lihat `karyawan.lihat`; catat dan batalkan
 * `karyawan.kelola`. Cuti berbayar tidak dihitung "tidak masuk" di rekap gaji dan menahan pengingat/peringatan kehadiran.
 */
final class CutiKaryawanKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarCutiKaryawan $daftar, DaftarKaryawan $karyawan, AksesPengguna $akses): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarCutiKaryawan::KOLOM_URUT, DaftarCutiKaryawan::URUT_BAWAAN, DaftarCutiKaryawan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Karyawan/Cuti', 'Cuti', fn (): array => $daftar->Ambil($tabel), fn (): array => [
            'OpsiKaryawan' => $karyawan->AmbilPilihan(),
            'Izin' => ['Kelola' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::KaryawanKelola)],
        ]);
    }

    public function Simpan(Request $permintaan, KelolaCutiKaryawan $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Karyawan' => ['required', 'string', 'size:26'],
            'Jenis' => ['required', 'string', Rule::enum(JenisCuti::class)],
            'TanggalMulai' => ['required', 'date_format:Y-m-d'],
            'TanggalSelesai' => ['required', 'date_format:Y-m-d'],
            'Berbayar' => ['required', 'boolean'],
            'Alasan' => ['nullable', 'string', 'max:255'],
        ], [
            'Karyawan.*' => 'Pilih karyawan.',
            'Jenis.*' => 'Pilih jenis: cuti, sakit, atau izin.',
            'TanggalMulai.*' => 'Isi tanggal mulai.',
            'TanggalSelesai.*' => 'Isi tanggal selesai.',
            'Berbayar.*' => 'Pilih apakah tetap digaji.',
            'Alasan.*' => 'Alasan paling panjang 255 karakter.',
        ]);
        $alasan = is_string($data['Alasan'] ?? null) && trim($data['Alasan']) !== '' ? trim($data['Alasan']) : null;
        $cuti = $kelola->Catat(
            $data['Karyawan'],
            JenisCuti::from($data['Jenis']),
            self::Tanggal($data['TanggalMulai']),
            self::Tanggal($data['TanggalSelesai']),
            (bool) $data['Berbayar'],
            $alasan,
            $this->Pelaku()->Id,
        );

        return to_route('kelola.karyawan.cuti')->with('Kilat', $cuti->Jenis->AmbilLabel().' dicatat.');
    }

    public function Batalkan(string $cuti, Request $permintaan, KelolaCutiKaryawan $kelola): RedirectResponse
    {
        $permintaan->validate(['Alasan' => ['required', 'string', 'max:500']], ['Alasan.*' => 'Tulis alasan membatalkan catatan ini.']);
        $kelola->Batalkan(CutiKaryawan::query()->where('Uuid', $cuti)->firstOrFail(), $permintaan->string('Alasan')->toString(), $this->Pelaku()->Id);

        return to_route('kelola.karyawan.cuti')->with('Kilat', 'Catatan dibatalkan.');
    }

    private static function Tanggal(string $tanggal): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $tanggal) ?: CarbonImmutable::today();
    }
}
