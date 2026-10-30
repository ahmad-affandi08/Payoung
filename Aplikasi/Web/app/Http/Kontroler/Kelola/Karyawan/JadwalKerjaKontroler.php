<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Aksi\IsiMassalJadwal;
use App\Domain\Karyawan\Aksi\SalinJadwalMingguLalu;
use App\Domain\Karyawan\Aksi\SimpanJadwalMingguan;
use App\Domain\Karyawan\Aksi\SimpanKebutuhanStaf;
use App\Domain\Karyawan\Aksi\SimpanPolaJadwal;
use App\Domain\Karyawan\Aksi\TerapkanPolaJadwal;
use App\Domain\Karyawan\Aksi\UmumkanJadwalMingguan;
use App\Domain\Karyawan\Data\DataJadwalMingguan;
use App\Domain\Karyawan\Kueri\JadwalMingguan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal kerja mingguan per outlet (F-18, EMP-02, `/kelola/karyawan/jadwal?outlet=&minggu=`): isian Senin–Minggu per
 * karyawan, simpan, dan salin minggu lalu. Lihat `karyawan.lihat`; ubah `karyawan.kelola`. Outlet di luar akses = 404.
 */
final class JadwalKerjaKontroler extends DasarKelolaKontroler
{
    public function Tampil(Request $permintaan, JadwalMingguan $jadwal, PetaUuidOutlet $peta): Response
    {
        $opsi = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        $uuid = $permintaan->query('outlet');
        $outlet = is_string($uuid) && $uuid !== '' ? $this->CariOutlet($uuid) : null;
        $idOutlet = $outlet === null ? ($opsi[0]['Id'] ?? null) : $outlet->Id;
        $senin = self::AmbilSenin($permintaan->query('minggu'), $idOutlet);

        return Inertia::render('Kelola/Karyawan/Jadwal', [
            'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $opsi),
            'UuidOutlet' => $outlet === null ? ($opsi[0]['Uuid'] ?? null) : $outlet->Uuid,
            'Senin' => $senin->toDateString(),
            'Jadwal' => $idOutlet === null ? ['Hari' => [], 'Baris' => []] : $jadwal->Ambil($idOutlet, $senin),
            'Izin' => ['Kelola' => app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::KaryawanKelola)],
        ]);
    }

    public function Simpan(Request $permintaan, SimpanJadwalMingguan $simpan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'Senin' => ['required', 'date_format:Y-m-d'],
            'Sel' => ['present', 'array', 'max:700'],
            'Sel.*.UuidKaryawan' => ['required', 'string', 'ulid'],
            'Sel.*.Tanggal' => ['required', 'date_format:Y-m-d'],
            // Bentuk lama (satu shift per hari): JamMulai/JamSelesai, kosong = hapus.
            'Sel.*.JamMulai' => ['nullable', 'string', 'size:5'],
            'Sel.*.JamSelesai' => ['nullable', 'string', 'size:5'],
            // D-251: daftar shift per hari, daftar kosong = hapus.
            'Sel.*.Shift' => ['sometimes', 'array', 'max:6'],
            'Sel.*.Shift.*.JamMulai' => ['required', 'string', 'size:5'],
            'Sel.*.Shift.*.JamSelesai' => ['required', 'string', 'size:5'],
            'Sel.*.Shift.*.UuidShift' => ['nullable', 'string', 'ulid'],
        ], attributes: ['UuidOutlet' => 'outlet', 'Senin' => 'minggu']);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $senin = self::AmbilSenin($valid['Senin'], $outlet->Id);
        /** @var list<array{UuidKaryawan: string, Tanggal: string, JamMulai?: string|null, JamSelesai?: string|null, Shift?: list<array{JamMulai: string, JamSelesai: string, UuidShift?: string|null}>}> $sel */
        $sel = $valid['Sel'];
        $jumlah = $simpan->Jalankan(new DataJadwalMingguan(
            $outlet->Id,
            $senin,
            array_map(function (array $s): array {
                if (array_key_exists('Shift', $s)) {
                    $shift = array_map(fn (array $x): array => ['JamMulai' => $x['JamMulai'], 'JamSelesai' => $x['JamSelesai'], 'UuidShift' => ($x['UuidShift'] ?? '') === '' ? null : $x['UuidShift']], $s['Shift']);
                } else {
                    $shift = ($s['JamMulai'] ?? '') === '' ? [] : [['JamMulai' => (string) $s['JamMulai'], 'JamSelesai' => (string) ($s['JamSelesai'] ?? ''), 'UuidShift' => null]];
                }

                return ['UuidKaryawan' => $s['UuidKaryawan'], 'Tanggal' => $s['Tanggal'], 'Shift' => $shift];
            }, $sel),
            $this->Pelaku()->Id,
        ));

        return back()->with('Kilat', $jumlah === 0 ? 'Tidak ada perubahan jadwal.' : "Jadwal {$outlet->Nama} disimpan ({$jumlah} hari berubah).");
    }

    /** D-251: satu shift bernama diterapkan ke banyak karyawan dan hari sekaligus (hanya hari yang masih kosong). */
    public function IsiMassal(Request $permintaan, IsiMassalJadwal $isi): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'Senin' => ['required', 'date_format:Y-m-d'],
            'UuidShift' => ['required', 'string', 'ulid'],
            'UuidKaryawan' => ['required', 'array', 'min:1', 'max:200'],
            'UuidKaryawan.*' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'array', 'min:1', 'max:7'],
            'Tanggal.*' => ['required', 'date_format:Y-m-d'],
        ], attributes: ['UuidShift' => 'shift', 'UuidKaryawan' => 'karyawan', 'Tanggal' => 'hari']);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $senin = self::AmbilSenin($valid['Senin'], $outlet->Id);
        $hasil = $isi->Jalankan($outlet->Id, $senin, (string) $valid['UuidShift'], array_values($valid['UuidKaryawan']), array_values($valid['Tanggal']), $this->Pelaku()->Id);

        return back()->with('Kilat', $hasil['Terisi'] === 0
            ? 'Tidak ada hari kosong yang bisa diisi.'
            : "{$hasil['Terisi']} jadwal terisi".($hasil['Dilewati'] > 0 ? ", {$hasil['Dilewati']} hari dilewati karena sudah berjadwal atau bertumpuk." : '.'));
    }

    /** D-251: pola jadwal berulang satu karyawan (menggantikan seluruh pola karyawan itu). */
    public function SimpanPola(Request $permintaan, string $karyawan, SimpanPolaJadwal $simpan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'Baris' => ['present', 'array', 'max:42'],
            'Baris.*.HariKe' => ['required', 'integer', 'between:1,7'],
            'Baris.*.JamMulai' => ['required', 'string', 'size:5'],
            'Baris.*.JamSelesai' => ['required', 'string', 'size:5'],
            'Baris.*.UuidShift' => ['nullable', 'string', 'ulid'],
        ]);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $jumlah = $simpan->Jalankan($outlet->Id, $karyawan, array_map(fn (array $b): array => [
            'HariKe' => (int) $b['HariKe'],
            'JamMulai' => (string) $b['JamMulai'],
            'JamSelesai' => (string) $b['JamSelesai'],
            'UuidShift' => ($b['UuidShift'] ?? '') === '' ? null : (string) $b['UuidShift'],
        ], $valid['Baris']), $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Pola jadwal dihapus.' : "Pola jadwal disimpan ({$jumlah} shift per minggu).");
    }

    public function TerapkanPola(Request $permintaan, TerapkanPolaJadwal $terapkan): RedirectResponse
    {
        $valid = $permintaan->validate(['UuidOutlet' => ['required', 'string', 'ulid'], 'Senin' => ['required', 'date_format:Y-m-d']]);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $jumlah = $terapkan->Jalankan($outlet->Id, self::AmbilSenin($valid['Senin'], $outlet->Id), $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Tidak ada hari kosong yang bisa diisi dari pola.' : "{$jumlah} shift terisi dari pola jadwal.");
    }

    public function SimpanKebutuhan(Request $permintaan, SimpanKebutuhanStaf $simpan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'Baris' => ['present', 'array', 'max:70'],
            'Baris.*.HariKe' => ['required', 'integer', 'between:1,7'],
            'Baris.*.JamMulai' => ['required', 'string', 'size:5'],
            'Baris.*.JamSelesai' => ['required', 'string', 'size:5'],
            'Baris.*.Minimal' => ['required', 'integer', 'between:1,99'],
        ]);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $jumlah = $simpan->Jalankan($outlet->Id, array_map(fn (array $b): array => [
            'HariKe' => (int) $b['HariKe'],
            'JamMulai' => (string) $b['JamMulai'],
            'JamSelesai' => (string) $b['JamSelesai'],
            'Minimal' => (int) $b['Minimal'],
        ], $valid['Baris']), $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Kebutuhan staf dihapus.' : "Kebutuhan staf disimpan ({$jumlah} aturan).");
    }

    public function Umumkan(Request $permintaan, UmumkanJadwalMingguan $umumkan): RedirectResponse
    {
        $valid = $permintaan->validate(['UuidOutlet' => ['required', 'string', 'ulid'], 'Senin' => ['required', 'date_format:Y-m-d']]);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);

        try {
            $jumlah = $umumkan->Jalankan($outlet->Id, self::AmbilSenin($valid['Senin'], $outlet->Id), $this->Pelaku()->Id);
        } catch (PelanggaranAturanBisnis $galat) {
            return back()->withErrors(['Umum' => $galat->getMessage()]);
        }

        return back()->with('Kilat', "Jadwal sedang dikirim lewat WhatsApp ke {$jumlah} karyawan.");
    }

    public function Salin(Request $permintaan, SalinJadwalMingguLalu $salin): RedirectResponse
    {
        $valid = $permintaan->validate(['UuidOutlet' => ['required', 'string', 'ulid'], 'Senin' => ['required', 'date_format:Y-m-d']]);
        $outlet = $this->CariOutlet((string) $valid['UuidOutlet']);
        $jumlah = $salin->Jalankan($outlet->Id, self::AmbilSenin($valid['Senin'], $outlet->Id), $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Tidak ada jadwal minggu lalu yang bisa disalin.' : "{$jumlah} jadwal disalin dari minggu lalu.");
    }

    /** Senin dari tanggal `YYYY-MM-DD` (atau minggu ini menurut tanggal bisnis outlet). */
    private static function AmbilSenin(mixed $tanggal, ?int $idOutlet): CarbonImmutable
    {
        $dasar = is_string($tanggal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $tanggal, 'UTC')
            : null;
        $dasar = $dasar instanceof CarbonImmutable ? $dasar : CarbonImmutable::parse(app(TanggalBisnisOutlet::class)->Hitung($idOutlet)->toDateString(), 'UTC');

        return $dasar->startOfWeek(CarbonImmutable::MONDAY);
    }
}
