<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Layanan\PengakhirPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;

/**
 * peminta atau pengirim menutup permintaan yang baru terpenuhi sebagian; sisanya tidak akan dikirim (F-05b, D-105): DiprosesSebagian → Ditutup, alasan wajib. Tidak mengubah stok atau jurnal. Audit `permintaan-stok.tutup`.
 */
final class TutupPermintaanStok
{
    public function __construct(private readonly PengakhirPermintaanStok $pengakhir) {}

    public function Jalankan(PermintaanStok $permintaan, string $alasan, int $idPengguna): PermintaanStok
    {
        return $this->pengakhir->Akhiri($permintaan, StatusPermintaanStok::Ditutup, [StatusPermintaanStok::DiprosesSebagian], $alasan, $idPengguna, 'permintaan-stok.tutup');
    }
}
