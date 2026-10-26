<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * D-38 (four-eyes adaptif): apakah ada anggota aktif **selain** pelaku yang bisa menyetujui dokumen — Pemilik, atau
 * peran ber-[izin] yang menjangkau outlet dokumen (semua outlet, atau `OutletPengguna` untuk outlet itu). Aturan izin
 * dan outlet sama dengan `AksesPengguna`. Dipakai aksi pengajuan: tanpa penyetuju lain, dokumen di atas batas dicatat
 * langsung dengan jejak audit alih-alih menunggu persetujuan yang tidak mungkin datang.
 */
final class PenyetujuLain
{
    public function CekAda(int $idTenant, int $idPelaku, IzinTenant $izin, ?int $idOutlet): bool
    {
        $anggota = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->where('IdPengguna', '!=', $idPelaku)
            ->get(['IdPengguna', 'Pemilik', 'IdPeran', 'SemuaOutlet']);

        if ($anggota->contains(fn (TenantPengguna $a): bool => $a->Pemilik)) {
            return true;
        }

        $peranBerizin = array_map('intval', PeranIzin::query()->where('KunciIzin', $izin->value)->pluck('IdPeran')->all());

        foreach ($anggota as $a) {
            if ($a->IdPeran === null || ! in_array($a->IdPeran, $peranBerizin, true)) {
                continue;
            }

            if ($a->SemuaOutlet || $idOutlet === null || OutletPengguna::query()->where('IdPengguna', $a->IdPengguna)->where('IdOutlet', $idOutlet)->exists()) {
                return true;
            }
        }

        return false;
    }
}
