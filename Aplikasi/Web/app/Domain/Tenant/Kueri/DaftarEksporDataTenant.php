<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;

/**
 * Riwayat ekspor data lengkap usaha untuk halaman Data saya (audit E5).
 */
final class DaftarEksporDataTenant
{
    /**
     * @return list<array{Uuid: string, Status: string, LabelStatus: string, UkuranByte: int, JumlahTabel: int, JumlahBaris: int, DibuatPada: string|null, KedaluwarsaPada: string|null, DiunduhPada: string|null, Galat: string|null, BisaDiunduh: bool}>
     */
    public function Ambil(int $batas = 10): array
    {
        return array_values(EksporDataTenant::query()->orderByDesc('Id')->limit($batas)->get()->map(fn (EksporDataTenant $e): array => [
            'Uuid' => $e->Uuid,
            'Status' => $e->Status->value,
            'LabelStatus' => $e->Status->AmbilLabel(),
            'UkuranByte' => $e->UkuranByte,
            'JumlahTabel' => $e->JumlahTabel,
            'JumlahBaris' => $e->JumlahBaris,
            'DibuatPada' => $e->DibuatPada?->toIso8601ZuluString(),
            'KedaluwarsaPada' => $e->KedaluwarsaPada?->toIso8601ZuluString(),
            'DiunduhPada' => $e->DiunduhPada?->toIso8601ZuluString(),
            'Galat' => $e->Galat,
            'BisaDiunduh' => $e->Status === StatusEksporDataTenant::Siap && $e->KedaluwarsaPada !== null && $e->KedaluwarsaPada->isFuture(),
        ])->all());
    }
}
