<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\StokAwal;
use Illuminate\Support\Facades\DB;

/**
 * Catat stok awal di antrean gagal (DesainF05a C.6.3): di transaksi baru, dokumen yang masih Memproses kembali ke
 * Draf dengan `PesanGalat` (ditampilkan di detail), riwayat status, dan audit `stok-awal.posting-gagal`. Dokumen
 * yang sudah tidak Memproses dibiarkan (idempoten).
 */
final class GagalkanPostingStokAwal
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idStokAwal, string $pesan, int $idPengguna): ?StokAwal
    {
        return DB::transaction(function () use ($idStokAwal, $pesan, $idPengguna): ?StokAwal {
            $dokumen = StokAwal::query()->whereKey($idStokAwal)->lockForUpdate()->first();

            if ($dokumen === null || $dokumen->Status !== StatusStokAwal::Memproses) {
                return $dokumen;
            }

            $pesan = mb_substr($pesan, 0, 500);
            $dokumen->UbahStatus(StatusStokAwal::Draf);
            $dokumen->PesanGalat = $pesan;
            $dokumen->save();

            $this->riwayat->Catat(StokAwal::JENIS_DOKUMEN, $dokumen->Id, StatusStokAwal::Memproses->value, StatusStokAwal::Draf->value, $idPengguna, mb_substr($pesan, 0, 255));
            $this->audit->Catat('stok-awal.posting-gagal', $dokumen, ['Status' => StatusStokAwal::Memproses->value], [
                'Status' => StatusStokAwal::Draf->value,
                'PesanGalat' => $pesan,
            ], idPengguna: $idPengguna);

            return $dokumen;
        });
    }
}
