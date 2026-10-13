<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Layanan\PemeriksaOpnameRak;
use App\Domain\Persediaan\Model\RakGudang;
use Illuminate\Support\Facades\DB;

/**
 * F-05c INV-07: menonaktifkan atau mengaktifkan kembali rak. Rak tidak dihapus (riwayat opname merujuknya). Rak nonaktif
 * tidak bisa dipilih untuk opname baru; penempatan produknya dipertahankan. Menonaktifkan ditolak selama opname rak itu
 * masih berjalan. Idempoten menurut keadaan akhir.
 */
final class UbahStatusRakGudang
{
    public function __construct(
        private readonly PemeriksaOpnameRak $pemeriksa,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(RakGudang $rak, bool $aktif, int $idPengguna): RakGudang
    {
        return DB::transaction(function () use ($rak, $aktif, $idPengguna): RakGudang {
            $rak = RakGudang::query()->whereKey($rak->Id)->lockForUpdate()->firstOrFail();

            if ($rak->Aktif === $aktif) {
                return $rak;
            }

            if (! $aktif) {
                $opname = $this->pemeriksa->CariOpnameAktif([$rak->Id]);

                if ($opname !== null) {
                    throw new PelanggaranAturanBisnis('RakSedangDiopname', "Rak {$rak->Nama} masih diopname ({$opname}). Selesaikan atau batalkan dulu.");
                }
            }

            $rak->Aktif = $aktif;
            $rak->save();
            $this->audit->Catat($aktif ? 'rak-gudang.aktifkan' : 'rak-gudang.nonaktifkan', $rak, ['Aktif' => ! $aktif], ['Aktif' => $aktif], idPengguna: $idPengguna);

            return $rak;
        });
    }
}
