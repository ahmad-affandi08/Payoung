<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan permintaan turun paket yang belum berlaku (BR-P04.4). Setelah berlaku, paket hanya bisa diubah dengan
 * naik paket atau permintaan baru.
 */
final class BatalkanTurunPaket
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(string $uuid, int $idPengguna, string $alasan = 'Dibatalkan pemilik usaha.'): PermintaanTurunPaket
    {
        return DB::transaction(function () use ($uuid, $idPengguna, $alasan): PermintaanTurunPaket {
            $permintaan = PermintaanTurunPaket::query()->where('Uuid', $uuid)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('TurunPaketTidakAda', 'Permintaan turun paket tidak ditemukan.');

            if ($permintaan->Status !== StatusPermintaanTurunPaket::Menunggu) {
                throw new PelanggaranAturanBisnis('TurunPaketSudahFinal', "Permintaan ini sudah {$permintaan->Status->AmbilLabel()}.");
            }

            $permintaan->update(['Status' => StatusPermintaanTurunPaket::Dibatalkan, 'DibatalkanPada' => now(), 'AlasanBatal' => $alasan]);
            $this->audit->Catat('langganan.turun-paket-batal', $permintaan, nilaiBaru: ['Alasan' => $alasan], idTenant: $permintaan->IdTenant, idPengguna: $idPengguna);

            return $permintaan;
        });
    }
}
