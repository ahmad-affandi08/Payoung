<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Layanan\PenghapusBerkasTenant;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;

/**
 * Menghapus lampiran pembukuan tenant yang akunnya sudah ditutup setelah masa retensi pajak lewat
 * (`tenant.TahunRetensiPembukuan`, UU PDP, P-07). Lampiran itu dipertahankan saat penghapusan data karena menjadi bukti
 * pembukuan. Dipanggil dalam konteks tenant yang bersangkutan.
 */
final class HapusBerkasPembukuanKedaluwarsa
{
    public function __construct(private readonly PenghapusBerkasTenant $penghapus) {}

    /** @return int jumlah tenant yang berkas pembukuannya dihapus */
    public function Jalankan(int $idTenant): int
    {
        $batas = now()->subYears((int) config('tenant.TahunRetensiPembukuan'));
        $jumlah = 0;

        $kueri = PermintaanPenghapusanData::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusPermintaanPenghapusanData::Dilaksanakan->value)
            ->whereNull('BerkasPembukuanDihapusPada')
            ->where('DilaksanakanPada', '<=', $batas);

        foreach ($kueri->get() as $permintaan) {
            $this->penghapus->HapusPembukuan($idTenant);
            $permintaan->forceFill(['BerkasPembukuanDihapusPada' => now()])->saveQuietly();
            $jumlah++;
        }

        return $jumlah;
    }
}
