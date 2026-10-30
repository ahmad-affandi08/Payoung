<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Model\KebutuhanStaf;
use Illuminate\Support\Facades\DB;

/**
 * Atur kebutuhan staf minimal sebuah outlet (F-18, D-251, izin `karyawan.kelola`): daftar baris menggantikan seluruh
 * kebutuhan outlet itu, daftar kosong menghapusnya. Rentang jam tidak boleh melewati tengah malam. Audit `kebutuhan-staf.simpan`.
 */
final class SimpanKebutuhanStaf
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  list<array{HariKe: int, JamMulai: string, JamSelesai: string, Minimal: int}>  $baris
     */
    public function Jalankan(int $idOutlet, array $baris, int $idPengguna): int
    {
        foreach ($baris as $i => $b) {
            if ($b['HariKe'] < 1 || $b['HariKe'] > 7) {
                throw new PelanggaranAturanBisnis('HariTidakValid', 'Hari harus Senin sampai Minggu.', "Baris.{$i}.HariKe");
            }

            if (! preg_match(SimpanJadwalMingguan::POLA_JAM, $b['JamMulai']) || ! preg_match(SimpanJadwalMingguan::POLA_JAM, $b['JamSelesai']) || $b['JamSelesai'] <= $b['JamMulai']) {
                throw new PelanggaranAturanBisnis('JamTidakValid', 'Rentang jam kebutuhan staf tidak valid (selesai harus setelah mulai di hari yang sama).', "Baris.{$i}.JamMulai");
            }

            if ($b['Minimal'] < 1 || $b['Minimal'] > 99) {
                throw new PelanggaranAturanBisnis('MinimalTidakValid', 'Jumlah staf minimal antara 1 dan 99.', "Baris.{$i}.Minimal");
            }
        }

        return DB::transaction(function () use ($idOutlet, $baris, $idPengguna): int {
            KebutuhanStaf::query()->where('IdOutlet', $idOutlet)->delete();

            foreach ($baris as $b) {
                KebutuhanStaf::query()->create(['IdOutlet' => $idOutlet, 'HariKe' => $b['HariKe'], 'JamMulai' => $b['JamMulai'], 'JamSelesai' => $b['JamSelesai'], 'Minimal' => $b['Minimal']]);
            }

            $this->audit->Catat('kebutuhan-staf.simpan', null, nilaiBaru: ['IdOutlet' => $idOutlet, 'Jumlah' => count($baris)], idPengguna: $idPengguna);

            return count($baris);
        }, 3);
    }
}
