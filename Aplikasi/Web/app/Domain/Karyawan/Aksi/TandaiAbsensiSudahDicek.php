<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Karyawan\Model\Absensi;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola menandai absensi yang "perlu dicek" (lokasi janggal, F-18, D-180) sebagai sudah diperiksa, izin
 * `karyawan.kelola`. Jam dan lokasinya tidak berubah; hanya tandanya yang turun, dan catatan lama tetap tersimpan di
 * log audit. Idempoten.
 */
final class TandaiAbsensiSudahDicek
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(Absensi $absensi, int $idPengguna): Absensi
    {
        return DB::transaction(function () use ($absensi, $idPengguna): Absensi {
            $terkunci = Absensi::query()->whereKey($absensi->Id)->lockForUpdate()->firstOrFail();

            if (! $terkunci->PerluDicek) {
                return $terkunci;
            }

            $catatan = $terkunci->CatatanPeriksa;
            $terkunci->forceFill(['PerluDicek' => false])->save();
            $this->audit->Catat('absensi.dicek', $terkunci, ['PerluDicek' => true, 'CatatanPeriksa' => $catatan], ['PerluDicek' => false], idPengguna: $idPengguna);

            return $terkunci;
        });
    }
}
