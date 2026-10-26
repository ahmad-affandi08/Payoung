<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\StatusPesananPenjualan;
use App\Domain\Penjualan\Model\PesananPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Pre-order selesai dibuat & siap diambil (F-12 bagian 2, izin `penjualan.buat`). Audit `pesanan-penjualan.siap`. */
final class TandaiPesananPenjualanSiap
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(PesananPenjualan $pesanan, int $idPengguna): PesananPenjualan
    {
        return DB::transaction(function () use ($pesanan, $idPengguna): PesananPenjualan {
            $pesanan = PesananPenjualan::query()->whereKey($pesanan->Id)->lockForUpdate()->firstOrFail();

            if (! $pesanan->Status->BisaBerubahKe(StatusPesananPenjualan::Siap) || $pesanan->Status !== StatusPesananPenjualan::Dipesan) {
                throw new PelanggaranAturanBisnis('StatusTidakValid', "Pre-order {$pesanan->Nomor} berstatus {$pesanan->Status->AmbilLabel()}. Hanya pesanan Dipesan yang bisa ditandai siap.", 'Status');
            }

            $pesanan->fill(['Status' => StatusPesananPenjualan::Siap, 'SiapPada' => CarbonImmutable::now()]);
            $pesanan->save();
            $this->riwayat->Catat(PesananPenjualan::JENIS_DOKUMEN, $pesanan->Id, StatusPesananPenjualan::Dipesan->value, StatusPesananPenjualan::Siap->value, $idPengguna);
            $this->audit->Catat('pesanan-penjualan.siap', $pesanan, ['Status' => StatusPesananPenjualan::Dipesan->value], ['Status' => StatusPesananPenjualan::Siap->value], idPengguna: $idPengguna);

            return $pesanan;
        });
    }
}
