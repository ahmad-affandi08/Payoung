<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;

/**
 * Panel wajah satu karyawan di back-office (F-18 bagian 4, D-37, D-180; hanya `karyawan.kelola`): wajah terdaftar
 * terakhir. Sidik wajah tidak pernah ikut; foto diunduh lewat rute berizin.
 */
final class AbsenHpKaryawan
{
    /**
     * @return array{Wajah: array{Status: string, Label: string, JumlahFoto: int, AlasanTolak: string|null, DibuatPada: string|null, DitinjauPada: string|null}|null}
     */
    public function Ambil(Karyawan $karyawan): array
    {
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $karyawan->Id)->latest('Id')->first();

        return [
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
