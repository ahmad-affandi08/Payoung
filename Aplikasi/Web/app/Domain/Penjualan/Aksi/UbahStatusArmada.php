<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Penjualan\Model\Armada;
use Illuminate\Support\Facades\DB;

/**
 * Menonaktifkan atau mengaktifkan kembali armada (F-12 §9.7). Armada tidak dihapus: surat jalan lama menunjuknya.
 * Idempoten menurut keadaan akhir. Audit `armada.aktifkan` / `armada.nonaktifkan`.
 */
final class UbahStatusArmada
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(Armada $armada, bool $aktif, int $idPengguna): Armada
    {
        return DB::transaction(function () use ($armada, $aktif, $idPengguna): Armada {
            $armada = Armada::query()->whereKey($armada->Id)->lockForUpdate()->firstOrFail();

            if ($armada->Aktif === $aktif) {
                return $armada;
            }

            $armada->Aktif = $aktif;
            $armada->save();
            $this->audit->Catat($aktif ? 'armada.aktifkan' : 'armada.nonaktifkan', $armada, ['Aktif' => ! $aktif], ['Aktif' => $aktif], idPengguna: $idPengguna);

            return $armada;
        });
    }
}
