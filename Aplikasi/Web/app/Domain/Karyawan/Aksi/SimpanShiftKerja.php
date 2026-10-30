<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Model\ShiftKerja;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Kelola shift bernama usaha (F-18, D-251, izin `karyawan.kelola`): buat, ubah, atau nonaktifkan. Nama unik per usaha.
 * Mengubah shift tidak mengubah jadwal yang sudah ada karena jam disalin saat shift diterapkan. Audit `shift-kerja.simpan`
 * dan `shift-kerja.hapus`.
 */
final class SimpanShiftKerja
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(?string $uuid, string $nama, string $jamMulai, string $jamSelesai, bool $aktif, int $urutan, int $idPengguna): ShiftKerja
    {
        $nama = trim($nama);

        if (! preg_match(SimpanJadwalMingguan::POLA_JAM, $jamMulai) || ! preg_match(SimpanJadwalMingguan::POLA_JAM, $jamSelesai) || $jamMulai === $jamSelesai) {
            throw new PelanggaranAturanBisnis('JamTidakValid', 'Jam shift tidak valid (format JJ:MM, mulai tidak sama dengan selesai).', 'JamMulai');
        }

        $shift = $uuid === null ? new ShiftKerja : ShiftKerja::query()->where('Uuid', $uuid)->first();

        if (! $shift instanceof ShiftKerja) {
            throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift tidak ditemukan.', 'Nama');
        }

        $lama = $shift->exists ? $shift->only(['Nama', 'JamMulai', 'JamSelesai', 'Aktif']) : null;
        $shift->forceFill(['Nama' => $nama, 'JamMulai' => $jamMulai, 'JamSelesai' => $jamSelesai, 'Aktif' => $aktif, 'Urutan' => $urutan]);

        try {
            $shift->save();
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('NamaShiftSudahAda', "Shift bernama {$nama} sudah ada.", 'Nama');
        }

        $this->audit->Catat('shift-kerja.simpan', $shift, nilaiLama: $lama, nilaiBaru: ['Nama' => $nama, 'JamMulai' => $jamMulai, 'JamSelesai' => $jamSelesai, 'Aktif' => $aktif], idPengguna: $idPengguna);

        return $shift;
    }

    public function Hapus(string $uuid, int $idPengguna): void
    {
        $shift = ShiftKerja::query()->where('Uuid', $uuid)->first();

        if (! $shift instanceof ShiftKerja) {
            throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift tidak ditemukan.', 'Nama');
        }

        $this->audit->Catat('shift-kerja.hapus', $shift, nilaiLama: ['Nama' => $shift->Nama], idPengguna: $idPengguna);
        $shift->delete();
    }
}
