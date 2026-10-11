<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Layanan\PengakhirPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;

/**
 * peminta (lokasi tujuan) membatalkan permintaan yang belum dijawab (F-05b, D-105): Diajukan → Dibatalkan, alasan wajib. Tidak mengubah stok atau jurnal. Audit `permintaan-stok.batalkan`.
 */
final class BatalkanPermintaanStok
{
    public function __construct(private readonly PengakhirPermintaanStok $pengakhir) {}

    public function Jalankan(PermintaanStok $permintaan, string $alasan, int $idPengguna): PermintaanStok
    {
        return $this->pengakhir->Akhiri($permintaan, StatusPermintaanStok::Dibatalkan, [StatusPermintaanStok::Diajukan], $alasan, $idPengguna, 'permintaan-stok.batalkan');
    }
}
