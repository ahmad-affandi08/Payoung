<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan draf penyesuaian stok (F-05b): tanpa mutasi atau jurnal; dokumen tetap tersimpan berstatus Dibatalkan.
 * Penyesuaian yang sudah dicatat tidak bisa dibatalkan (koreksi = penyesuaian baru). Audit `penyesuaian-stok.batal`.
 */
final class BatalkanPenyesuaianStok
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(PenyesuaianStok $dokumen, int $idPengguna): PenyesuaianStok
    {
        return DB::transaction(function () use ($dokumen, $idPengguna): PenyesuaianStok {
            $dokumen = PenyesuaianStok::query()->whereKey($dokumen->Id)->lockForUpdate()->firstOrFail();

            if ($dokumen->Status === StatusPenyesuaianStok::Dibatalkan) {
                return $dokumen;
            }

            if ($dokumen->Status !== StatusPenyesuaianStok::Draf) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', 'Hanya draf penyesuaian yang bisa dibatalkan. Penyesuaian tercatat dikoreksi dengan penyesuaian baru.');
            }

            $dokumen->UbahStatus(StatusPenyesuaianStok::Dibatalkan);
            $dokumen->fill(['DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now(), 'DiubahOleh' => $idPengguna]);
            $dokumen->save();
            $this->riwayat->Catat(PenyesuaianStok::JENIS_DOKUMEN, $dokumen->Id, StatusPenyesuaianStok::Draf->value, StatusPenyesuaianStok::Dibatalkan->value, $idPengguna);
            $this->audit->Catat('penyesuaian-stok.batal', $dokumen, ['Status' => StatusPenyesuaianStok::Draf->value], ['Status' => StatusPenyesuaianStok::Dibatalkan->value], idPengguna: $idPengguna);

            return $dokumen;
        });
    }
}
