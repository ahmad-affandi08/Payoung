<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Layanan;

use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Memenuhi kontrak `GerbangPengelola` untuk sisi tenant (PGL-16): status aktif dan izin masuk petugas dibaca dari akun pengelolanya,
 * dan setiap kejadian sesi akses dukungan ditulis ke `LogAuditPengelola` atas nama petugas itu.
 */
final class GerbangPengelolaDukungan implements GerbangPengelola
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function CekPetugasAktif(int $idPenggunaPengelola): bool
    {
        // Petugas yang dinonaktifkan atau dicabut izin masuknya (ganti peran) kehilangan sesinya pada permintaan berikutnya.
        $petugas = PenggunaPengelola::query()->whereKey($idPenggunaPengelola)->where('Aktif', true)->first();

        return $petugas instanceof PenggunaPengelola
            && ($petugas->PunyaIzin(IzinPengelola::DukunganAksesMasuk) || $petugas->PunyaIzin(IzinPengelola::DukunganAksesDarurat));
    }

    public function CatatJejak(string $aksi, int $idPenggunaPengelola, int $idTenant, ?array $nilai = null, ?string $alasan = null, ?string $ip = null): void
    {
        $this->audit->Catat($aksi, nilaiBaru: $nilai, alasan: $alasan, idPelaku: $idPenggunaPengelola, idTenant: $idTenant, ip: $ip);
    }
}
