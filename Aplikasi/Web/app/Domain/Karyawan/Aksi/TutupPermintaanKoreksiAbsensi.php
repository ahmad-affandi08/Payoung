<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\StatusPermintaanKoreksi;
use App\Domain\Karyawan\Model\PermintaanKoreksiAbsensi;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola menutup permintaan koreksi absensi (F-18, D-180, izin `karyawan.kelola`): `Selesai` (jamnya sudah dicatat lewat
 * absensi manual, atau cukup dibereskan di tempat) atau `Ditolak`. Idempoten pada status yang sama; permintaan yang sudah
 * ditutup tidak bisa diubah ke status lain.
 */
final class TutupPermintaanKoreksiAbsensi
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(PermintaanKoreksiAbsensi $permintaan, StatusPermintaanKoreksi $status, int $idPengguna): PermintaanKoreksiAbsensi
    {
        if ($status === StatusPermintaanKoreksi::Menunggu) {
            throw new PelanggaranAturanBisnis('StatusTidakValid', 'Permintaan hanya bisa ditutup sebagai Selesai atau Ditolak.', 'Status');
        }

        return DB::transaction(function () use ($permintaan, $status, $idPengguna): PermintaanKoreksiAbsensi {
            $terkunci = PermintaanKoreksiAbsensi::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === $status) {
                return $terkunci;
            }

            if ($terkunci->Status !== StatusPermintaanKoreksi::Menunggu) {
                throw new PelanggaranAturanBisnis('PermintaanSudahDitutup', 'Permintaan ini sudah ditutup.', 'Status');
            }

            $terkunci->forceFill(['Status' => $status, 'DiprosesOleh' => $idPengguna, 'DiprosesPada' => now()])->save();
            $this->audit->Catat('absensi.koreksi.ditutup', $terkunci, ['Status' => 'Menunggu'], ['Status' => $status->value], idPengguna: $idPengguna);

            return $terkunci;
        });
    }
}
