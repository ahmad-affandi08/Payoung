<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use Carbon\CarbonImmutable;

/** Konteks Kotak Tindakan (D-23 C) untuk pengguna di tenant: status pemilik, kunci izin peran, dan outlet akses. */
final class KonteksTindakanPengguna
{
    public function __construct(private readonly AksesPengguna $akses, private readonly SektorOutletTenant $sektor) {}

    public function Buat(int $idTenant, int $idPengguna, CarbonImmutable $hariIni): DataKonteksTindakan
    {
        $data = $this->akses->Ambil($idTenant, $idPengguna);

        return new DataKonteksTindakan(
            $idTenant,
            $idPengguna,
            $data['Pemilik'] ?? false,
            $data['Izin'] ?? [],
            $this->akses->AmbilIdOutlet($idTenant, $idPengguna),
            $hariIni,
            $this->sektor->AmbilKode(),
        );
    }
}
