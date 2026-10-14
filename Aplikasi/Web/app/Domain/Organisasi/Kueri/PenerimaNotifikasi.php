<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\NotifikasiOutletDibisukan;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Anggota aktif tenant yang boleh menerima notifikasi tentang kejadian di satu outlet (OWN-03, F31): memegang izin yang
 * relevan (pemilik selalu), outlet kejadian ada di cakupan aksesnya, dan outlet itu tidak ia bisukan (PLT-08). Dipanggil
 * dengan tenant aktif sudah ditetapkan.
 */
final class PenerimaNotifikasi
{
    public function __construct(private readonly AksesPengguna $akses) {}

    /**
     * @return list<int> Id pengguna
     */
    public function AmbilIdPengguna(int $idTenant, IzinTenant $izin, ?int $idOutlet): array
    {
        $hasil = [];
        $dibisukan = $idOutlet === null
            ? []
            : NotifikasiOutletDibisukan::query()->where('IdOutlet', $idOutlet)->pluck('IdPengguna')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (TenantPengguna::query()->where('IdTenant', $idTenant)->where('Status', StatusKeanggotaan::Aktif->value)->pluck('IdPengguna') as $id) {
            $idPengguna = (int) $id;

            if (in_array($idPengguna, $dibisukan, true)) {
                continue;
            }

            if (! $this->akses->CekIzin($idTenant, $idPengguna, $izin)) {
                continue;
            }

            $outlet = $this->akses->AmbilIdOutlet($idTenant, $idPengguna);

            if ($idOutlet === null || $outlet === null || in_array($idOutlet, $outlet, true)) {
                $hasil[] = $idPengguna;
            }
        }

        return $hasil;
    }
}
