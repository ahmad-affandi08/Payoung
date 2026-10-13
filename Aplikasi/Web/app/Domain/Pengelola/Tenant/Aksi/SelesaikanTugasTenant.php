<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * P-07 PGL-09: tim menandai tugas otomatis sudah ditangani (mis. tenant sudah dihubungi) dengan catatan wajib. Tugas
 * yang ditutup manual tidak dibuka lagi oleh penghitung harian selama `HitungSkorKesehatanTenant::HARI_JEDA_TUGAS`
 * hari, supaya tenant yang sudah dihubungi tidak muncul lagi besok. Audit `tenant.tugas.selesai`.
 */
final class SelesaikanTugasTenant
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    /**
     * @throws PelanggaranAturanBisnis CatatanTerlaluPendek, TugasSudahSelesai
     */
    public function Jalankan(PenggunaPengelola $pelaku, TugasTenant $tugas, string $catatan): TugasTenant
    {
        $catatan = trim($catatan);

        if (mb_strlen($catatan) < 5 || mb_strlen($catatan) > 500) {
            throw new PelanggaranAturanBisnis('CatatanTidakValid', 'Tulis catatan 5 sampai 500 huruf: siapa yang menghubungi dan hasilnya.', 'Catatan');
        }

        return DB::transaction(function () use ($pelaku, $tugas, $catatan): TugasTenant {
            $terkunci = TugasTenant::query()->whereKey($tugas->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status !== TugasTenant::TERBUKA) {
                throw new PelanggaranAturanBisnis('TugasSudahSelesai', 'Tugas ini sudah selesai.', 'Umum', 409);
            }

            $terkunci->forceFill([
                'Status' => TugasTenant::SELESAI,
                'KunciTerbuka' => null,
                'SelesaiPada' => now(),
                'CaraSelesai' => 'Manual',
                'SelesaiOleh' => $pelaku->Id,
                'CatatanSelesai' => $catatan,
            ])->save();
            $tenant = Tenant::query()->whereKey($terkunci->IdTenant)->firstOrFail();
            $this->audit->Catat('tenant.tugas.selesai', $terkunci, ['Status' => TugasTenant::TERBUKA], ['Status' => TugasTenant::SELESAI, 'Jenis' => $terkunci->Jenis->value], $catatan, $pelaku->Id, $tenant->Id);

            return $terkunci;
        });
    }
}
