<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Kontrak;

/**
 * Jembatan dari sisi tenant ke Platform Pengelola untuk akses dukungan (PGL-16). Kode tenant tidak boleh memakai domain
 * Pengelola (test arsitektur), jadi dua kebutuhan ini dilewatkan kontrak yang dipenuhi domain Pengelola:
 * (1) apakah petugas masih aktif (petugas yang dinonaktifkan kehilangan sesinya seketika), dan
 * (2) menulis `LogAuditPengelola` untuk setiap kejadian penting dalam sesi (BR-P09: dicatat di dua log audit).
 */
interface GerbangPengelola
{
    public function CekPetugasAktif(int $idPenggunaPengelola): bool;

    /**
     * @param  array<string, mixed>|null  $nilai
     */
    public function CatatJejak(string $aksi, int $idPenggunaPengelola, int $idTenant, ?array $nilai = null, ?string $alasan = null, ?string $ip = null): void;
}
