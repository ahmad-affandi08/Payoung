<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Layanan\PengakhirPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;

/**
 * pengirim (lokasi asal) menolak permintaan yang belum dijawab (F-05b, D-105): Diajukan → Ditolak, alasan wajib. Tidak mengubah stok atau jurnal. Audit `permintaan-stok.tolak`.
 */
final class TolakPermintaanStok
{
    public function __construct(private readonly PengakhirPermintaanStok $pengakhir) {}

    public function Jalankan(PermintaanStok $permintaan, string $alasan, int $idPengguna): PermintaanStok
    {
        return $this->pengakhir->Akhiri($permintaan, StatusPermintaanStok::Ditolak, [StatusPermintaanStok::Diajukan], $alasan, $idPengguna, 'permintaan-stok.tolak');
    }
}
