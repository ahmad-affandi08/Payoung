<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Panel absen satu karyawan di back-office (F-18 bagian 4, D-37, D-180, D-198; hanya `karyawan.kelola`): tautan absen
 * pribadi (ditampilkan ulang dari token terenkripsi) dan wajah terdaftar terakhir. Sidik wajah tidak pernah ikut; foto
 * diunduh lewat rute berizin.
 */
final class AbsenHpKaryawan
{
    public function __construct(private readonly ProfilTenant $profil) {}

    /**
     * @return array{Tautan: string|null, TautanDibuatPada: string|null, Wajah: array{Status: string, Label: string, JumlahFoto: int, AlasanTolak: string|null, DibuatPada: string|null, DitinjauPada: string|null}|null}
     */
    public function Ambil(Karyawan $karyawan): array
    {
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $karyawan->Id)->latest('Id')->first();

        $slug = $this->profil->AmbilSlug($karyawan->IdTenant);

        return [
            'Tautan' => $karyawan->TokenAbsen === null ? null : url("/{$slug}/absen/{$karyawan->TokenAbsen}"),
            'TautanDibuatPada' => $karyawan->TokenAbsenDibuatPada?->toIso8601String(),
            'Wajah' => $wajah === null ? null : [
                'Status' => $wajah->Status->value,
                'Label' => $wajah->Status->AmbilLabel(),
                'JumlahFoto' => count($wajah->PathFoto),
                'AlasanTolak' => $wajah->AlasanTolak,
                'DibuatPada' => $wajah->DibuatPada?->toIso8601String(),
                'DitinjauPada' => $wajah->DitinjauPada?->toIso8601String(),
            ],
        ];
    }
}
