<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Anggota aktif tenant yang boleh menerima notifikasi tentang kejadian di satu outlet (OWN-03, F31): memegang izin yang
 * relevan (pemilik selalu) dan outlet kejadian ada di cakupan aksesnya. Dipanggil dengan tenant aktif sudah ditetapkan.
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

        foreach (TenantPengguna::query()->where('IdTenant', $idTenant)->where('Status', StatusKeanggotaan::Aktif->value)->pluck('IdPengguna') as $id) {
            $idPengguna = (int) $id;

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
