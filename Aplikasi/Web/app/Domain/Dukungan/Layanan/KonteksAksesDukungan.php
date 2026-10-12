<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use App\Domain\Dukungan\Data\DataSesiDukunganAktif;

/**
 * Sesi akses dukungan yang sah pada permintaan berjalan (PGL-16). Scoped: dibuat ulang tiap permintaan/job, jadi tidak
 * ada hak akses petugas yang terbawa ke permintaan lain. Diisi satu-satunya oleh perantara `PasangAksesDukungan` setelah
 * sesi, izin induk, dan petugasnya diperiksa; `AksesPengguna` membacanya untuk memberi identitas teknis petugas hak
 * akses (di luar konteks ini identitas itu tidak punya hak apa pun).
 */
final class KonteksAksesDukungan
{
    private ?DataSesiDukunganAktif $sesi = null;

    public function Atur(DataSesiDukunganAktif $sesi): void
    {
        $this->sesi = $sesi;
    }

    public function Kosongkan(): void
    {
        $this->sesi = null;
    }

    public function Aktif(): bool
    {
        return $this->sesi instanceof DataSesiDukunganAktif;
    }

    public function Ambil(): ?DataSesiDukunganAktif
    {
        return $this->sesi;
    }

    /** True hanya bila [idPengguna] adalah identitas teknis petugas pada sesi yang sedang sah untuk [idTenant] ini. */
    public function CekPengguna(int $idTenant, int $idPengguna): bool
    {
        return $this->sesi instanceof DataSesiDukunganAktif && $this->sesi->idTenant === $idTenant && $this->sesi->idPengguna === $idPengguna;
    }
}
