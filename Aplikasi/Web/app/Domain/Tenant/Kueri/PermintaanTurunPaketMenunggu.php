<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Model\PermintaanTurunPaket;

/**
 * Permintaan turun paket yang masih menunggu untuk tenant aktif (BR-P04.4). Dipakai penerbit tagihan perpanjangan
 * (paket tujuan menggantikan paket berjalan) dan halaman Langganan.
 */
final class PermintaanTurunPaketMenunggu
{
    public function Cari(int $idLangganan): ?PermintaanTurunPaket
    {
        return PermintaanTurunPaket::query()
            ->with(['PaketTujuan', 'PaketAsal'])
            ->where('IdLangganan', $idLangganan)
            ->where('Status', StatusPermintaanTurunPaket::Menunggu->value)
            ->orderByDesc('Id')
            ->first();
    }
}
