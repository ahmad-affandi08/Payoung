<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;

/**
 * Permintaan penghapusan data satu tenant untuk tampilan 360° (UU PDP, P-07). `PermintaanPenghapusanData` adalah catatan
 * platform tentang tenant (`MODEL_DATA_PLATFORM`), jadi dibaca tanpa log akses per kueri. Hanya angka ringkasan
 * pelaksanaan yang ditampilkan, tidak ada data pribadi.
 */
final class PenghapusanDataTenant
{
    public const BATAS = 10;

    public function __construct(private readonly KonteksPengelola $konteks) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function Ambil(int $idTenant): array
    {
        return array_values($this->konteks->KueriDataPlatform(PermintaanPenghapusanData::class)
            ->where('IdTenant', $idTenant)
            ->orderByDesc('Id')
            ->limit(self::BATAS)
            ->get()
            ->map(function (PermintaanPenghapusanData $permintaan): array {
                $ringkasan = $permintaan->Ringkasan ?? [];
                $berkas = is_array($ringkasan['Berkas'] ?? null) ? $ringkasan['Berkas'] : [];
                $menunggu = $permintaan->Status === StatusPermintaanPenghapusanData::Menunggu;

                return [
                    'Uuid' => $permintaan->Uuid,
                    'Status' => $permintaan->Status->value,
                    'DiajukanPada' => $permintaan->DibuatPada?->toIso8601String(),
                    'BerlakuPada' => $permintaan->BerlakuPada->toIso8601String(),
                    'MasaTungguSelesai' => $menunggu && ! $permintaan->BerlakuPada->isFuture(),
                    'Alasan' => $permintaan->Alasan,
                    'CatatanPemutus' => $permintaan->CatatanPemutus,
                    'DiputuskanPada' => $permintaan->DiputuskanPada?->toIso8601String(),
                    'DilaksanakanPada' => $permintaan->DilaksanakanPada?->toIso8601String(),
                    'BuktiDikirimPada' => $permintaan->BuktiDikirimPada?->toIso8601String(),
                    'BerkasPembukuanDihapusPada' => $permintaan->BerkasPembukuanDihapusPada?->toIso8601String(),
                    'TabelDianonimkan' => is_array($ringkasan['Dianonimkan'] ?? null) ? count($ringkasan['Dianonimkan']) : 0,
                    'TabelDihapus' => is_array($ringkasan['Dihapus'] ?? null) ? count($ringkasan['Dihapus']) : 0,
                    'PenggunaDianonimkan' => (int) ($ringkasan['PenggunaDianonimkan'] ?? 0),
                    'BerkasDihapus' => (int) ($berkas['Berkas'] ?? 0),
                    'BerkasGagal' => (int) ($berkas['Gagal'] ?? 0),
                ];
            })
            ->all());
    }
}
