<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Kueri\AturanKehadiranTenant;
use App\Domain\Karyawan\Model\AturanKehadiran;
use Illuminate\Support\Facades\DB;

/**
 * Simpan aturan kehadiran tenant (F-18 bagian 5, D-44; izin `karyawan.kelola`). Semua menit bulat: masuk paling awal
 * 0–480, toleransi terlambat & pulang cepat 0–120, ambang lembur 0–240, pengingat shift 5–240 menit sebelum mulai,
 * peringatan pengelola 5–240 menit setelah jam mulai dan tidak lebih awal dari toleransi terlambat (peringatan
 * "belum masuk" yang datang sebelum batas terlambat akan keliru). D-180: saklar simpan foto bukti dan tolak absen di luar
 * radius. D-250: saklar tutup otomatis absensi yang lupa keluar dan batas tunggunya, 0–720 menit setelah jam selesai
 * jadwal. Audit `aturan-kehadiran.simpan`.
 */
final class SimpanAturanKehadiran
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  array{WajibJadwal: bool, MasukPalingAwalMenit: int, ToleransiTerlambatMenit: int, ToleransiPulangCepatMenit: int, LemburSetelahMenit: int, PengingatShiftAktif: bool, PengingatShiftMenitSebelum: int, PeringatanPengelolaAktif: bool, PeringatanPengelolaSetelahMenit: int, SimpanFotoBukti: bool, TolakDiLuarRadius: bool, TutupOtomatisAktif: bool, TutupOtomatisSetelahMenit: int}  $nilai
     */
    public function Jalankan(array $nilai, int $idPengguna): AturanKehadiran
    {
        $this->Periksa($nilai);

        return DB::transaction(function () use ($nilai, $idPengguna): AturanKehadiran {
            $aturan = AturanKehadiran::query()->lockForUpdate()->first() ?? new AturanKehadiran;
            $lama = AturanKehadiranTenant::Petakan($aturan);
            $aturan->fill($nilai);
            $aturan->save();

            if ($lama !== $nilai) {
                $this->audit->Catat('aturan-kehadiran.simpan', $aturan, $lama, $nilai, idPengguna: $idPengguna);
            }

            return $aturan;
        });
    }

    /**
     * @param  array<string, bool|int>  $nilai
     */
    private function Periksa(array $nilai): void
    {
        $batas = [
            'MasukPalingAwalMenit' => [0, 480, 'Masuk paling awal'],
            'ToleransiTerlambatMenit' => [0, 120, 'Toleransi terlambat'],
            'ToleransiPulangCepatMenit' => [0, 120, 'Toleransi pulang cepat'],
            'LemburSetelahMenit' => [0, 240, 'Ambang lembur'],
            'PengingatShiftMenitSebelum' => [5, 240, 'Pengingat shift'],
            'PeringatanPengelolaSetelahMenit' => [5, 240, 'Peringatan ke pengelola'],
            'TutupOtomatisSetelahMenit' => [0, 720, 'Batas tunggu tutup otomatis'],
        ];

        foreach ($batas as $kunci => [$min, $maks, $nama]) {
            $v = $nilai[$kunci] ?? null;

            if (! is_int($v) || $v < $min || $v > $maks) {
                throw new PelanggaranAturanBisnis('NilaiAturanTidakValid', "{$nama} harus {$min}–{$maks} menit.", $kunci);
            }
        }

        if ((int) $nilai['PeringatanPengelolaSetelahMenit'] < (int) $nilai['ToleransiTerlambatMenit']) {
            throw new PelanggaranAturanBisnis('PeringatanTerlaluAwal', 'Peringatan ke pengelola tidak boleh lebih awal dari toleransi terlambat.', 'PeringatanPengelolaSetelahMenit');
        }
    }
}
