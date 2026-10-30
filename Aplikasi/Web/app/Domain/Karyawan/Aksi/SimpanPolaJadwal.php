<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Layanan\PemeriksaBentrokJadwal;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PolaJadwalKerja;
use App\Domain\Karyawan\Model\ShiftKerja;
use Illuminate\Support\Facades\DB;

/**
 * Atur pola jadwal mingguan berulang seorang karyawan di satu outlet (F-18, D-251, izin `karyawan.kelola`). Daftar baris
 * menggantikan seluruh pola karyawan itu, daftar kosong menghapus pola. `HariKe` 1 = Senin … 7 = Minggu, dan shift di hari
 * yang sama tidak boleh saling tumpang tindih. Audit `pola-jadwal.simpan`.
 */
final class SimpanPolaJadwal
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  list<array{HariKe: int, JamMulai: string, JamSelesai: string, UuidShift: string|null}>  $baris
     * @return int jumlah baris pola tersimpan
     */
    public function Jalankan(int $idOutlet, string $uuidKaryawan, array $baris, int $idPengguna): int
    {
        $karyawan = Karyawan::query()->where('Uuid', $uuidKaryawan)->first();

        if (! $karyawan instanceof Karyawan) {
            throw new PelanggaranAturanBisnis('KaryawanTidakDitemukan', 'Karyawan tidak ditemukan.', 'UuidKaryawan');
        }

        $uuidShift = array_values(array_unique(array_filter(array_column($baris, 'UuidShift'))));
        $shift = $uuidShift === [] ? collect() : ShiftKerja::query()->whereIn('Uuid', $uuidShift)->get()->keyBy('Uuid');
        $tanggalContoh = ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11'];
        $periksa = [];
        $kunci = [];

        foreach ($baris as $i => $b) {
            if ($b['HariKe'] < 1 || $b['HariKe'] > 7) {
                throw new PelanggaranAturanBisnis('HariTidakValid', 'Hari pola harus Senin sampai Minggu.', "Baris.{$i}.HariKe");
            }

            if (! preg_match(SimpanJadwalMingguan::POLA_JAM, $b['JamMulai']) || ! preg_match(SimpanJadwalMingguan::POLA_JAM, $b['JamSelesai']) || $b['JamMulai'] === $b['JamSelesai']) {
                throw new PelanggaranAturanBisnis('JamTidakValid', 'Jam pola tidak valid (format JJ:MM, mulai tidak sama dengan selesai).', "Baris.{$i}.JamMulai");
            }

            if ($b['UuidShift'] !== null && ! $shift->has($b['UuidShift'])) {
                throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift tidak ditemukan.', "Baris.{$i}.JamMulai");
            }

            if (isset($kunci[$b['HariKe'].'|'.$b['JamMulai']])) {
                throw new PelanggaranAturanBisnis('PolaBertumpuk', 'Dua shift pada hari yang sama dimulai di jam yang sama.', "Baris.{$i}.JamMulai");
            }

            $kunci[$b['HariKe'].'|'.$b['JamMulai']] = true;
            $periksa[] = ['Tanggal' => $tanggalContoh[$b['HariKe'] - 1], 'JamMulai' => $b['JamMulai'], 'JamSelesai' => $b['JamSelesai']];
        }

        // Minggu malam yang lewat tengah malam ikut menabrak Senin pola berikutnya, jadi Minggu diulang sebagai hari sebelum Senin.
        $dengan = $periksa;

        foreach ($periksa as $p) {
            if ($p['Tanggal'] === '2026-01-11') {
                $dengan[] = ['Tanggal' => '2026-01-04', 'JamMulai' => $p['JamMulai'], 'JamSelesai' => $p['JamSelesai']];
            }
        }

        if (PemeriksaBentrokJadwal::Cari($dengan) !== null) {
            throw new PelanggaranAturanBisnis('PolaBertumpuk', 'Ada shift dalam pola yang saling tumpang tindih.', 'Baris.0.JamMulai');
        }

        return DB::transaction(function () use ($karyawan, $idOutlet, $baris, $shift, $idPengguna): int {
            PolaJadwalKerja::query()->where('IdKaryawan', $karyawan->Id)->delete();

            foreach ($baris as $b) {
                PolaJadwalKerja::query()->create([
                    'IdKaryawan' => $karyawan->Id,
                    'IdOutlet' => $idOutlet,
                    'IdShiftKerja' => $b['UuidShift'] === null ? null : $shift->get($b['UuidShift'])?->Id,
                    'HariKe' => $b['HariKe'],
                    'JamMulai' => $b['JamMulai'],
                    'JamSelesai' => $b['JamSelesai'],
                ]);
            }

            $this->audit->Catat('pola-jadwal.simpan', null, nilaiBaru: ['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $idOutlet, 'Jumlah' => count($baris)], idPengguna: $idPengguna);

            return count($baris);
        }, 3);
    }
}
