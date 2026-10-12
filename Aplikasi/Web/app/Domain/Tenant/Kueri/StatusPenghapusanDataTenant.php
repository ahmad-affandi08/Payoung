<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Model\EksporDataTenant;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;

/**
 * Keadaan penghapusan data untuk halaman Data saya (UU PDP, P-07): apakah tersedia di edisi ini, sudah adakah ekspor
 * yang diunduh (syarat mengajukan), dan permintaan yang sedang menunggu beserta riwayat terakhir.
 */
final class StatusPenghapusanDataTenant
{
    /**
     * @return array{Tersedia: bool, HariTunggu: int, EksporSudahDiunduh: bool, Menunggu: array{Uuid: string, BerlakuPada: string, DiajukanPada: string|null, Alasan: string|null}|null, Riwayat: list<array{Uuid: string, Status: string, DiajukanPada: string|null, DiputuskanPada: string|null, CatatanPemutus: string|null}>}
     */
    public function Ambil(): array
    {
        $menunggu = PermintaanPenghapusanData::query()->where('Status', StatusPermintaanPenghapusanData::Menunggu->value)->orderByDesc('Id')->first();

        return [
            'Tersedia' => ! EdisiAplikasi::CekLisensi(),
            'HariTunggu' => (int) config('tenant.HariTungguPenghapusan'),
            'EksporSudahDiunduh' => EksporDataTenant::query()->whereNotNull('DiunduhPada')->exists(),
            'Menunggu' => $menunggu === null ? null : [
                'Uuid' => $menunggu->Uuid,
                'BerlakuPada' => $menunggu->BerlakuPada->toIso8601ZuluString(),
                'DiajukanPada' => $menunggu->DibuatPada?->toIso8601ZuluString(),
                'Alasan' => $menunggu->Alasan,
            ],
            'Riwayat' => array_values(PermintaanPenghapusanData::query()
                ->where('Status', '<>', StatusPermintaanPenghapusanData::Menunggu->value)
                ->orderByDesc('Id')->limit(5)->get()
                ->map(fn (PermintaanPenghapusanData $p): array => [
                    'Uuid' => $p->Uuid,
                    'Status' => $p->Status->value,
                    'DiajukanPada' => $p->DibuatPada?->toIso8601ZuluString(),
                    'DiputuskanPada' => ($p->DiputuskanPada ?? $p->DibatalkanPada)?->toIso8601ZuluString(),
                    'CatatanPemutus' => $p->CatatanPemutus,
                ])->all()),
        ];
    }
}
