<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Model\SesiAksesDukungan;

/**
 * Mengakhiri satu sesi akses dukungan (PGL-16): petugas menekan "Akhiri sesi", Owner mencabut izin, waktu habis, atau
 * petugas dinonaktifkan. Idempoten: sesi yang sudah berakhir tidak diubah dan tidak dicatat dua kali. Dicatat di
 * `LogAudit` tenant (bertanda petugas) dan `LogAuditPengelola`.
 */
final class AkhiriSesiAksesDukungan
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly GerbangPengelola $gerbang,
    ) {}

    /** @return bool true bila sesi ini yang diakhiri sekarang */
    public function Jalankan(SesiAksesDukungan $sesi, AlasanAkhirSesiDukungan $alasan): bool
    {
        if ($sesi->DiakhiriPada !== null) {
            return false;
        }

        $sesi->DiakhiriPada = now();
        $sesi->AlasanAkhir = $alasan;
        $sesi->save();

        $this->audit->Catat('dukungan.sesi.berakhir', $sesi, nilaiBaru: [
            'Petugas' => $sesi->NamaPetugas,
            'Alasan' => $alasan->value,
        ], idPenggunaPengelola: $sesi->IdPenggunaPengelola);
        $this->gerbang->CatatJejak('dukungan.sesi.berakhir', $sesi->IdPenggunaPengelola, $sesi->IdTenant, ['Alasan' => $alasan->value, 'UuidSesi' => $sesi->Uuid]);

        return true;
    }
}
