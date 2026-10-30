<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Kueri\TenantAktif;

/**
 * Tenant tempat seorang pengguna menjadi anggota aktif (BR-00.1: pemilih tenant setelah masuk).
 */
final class KeanggotaanPengguna
{
    public function __construct(private readonly TenantAktif $tenantAktif) {}

    /**
     * @return list<int>
     */
    public function AmbilIdTenant(int $idPengguna): array
    {
        return array_values(array_map('intval', TenantPengguna::query()
            ->where('IdPengguna', $idPengguna)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->orderBy('Id')
            ->pluck('IdTenant')
            ->all()));
    }

    /**
     * OWN-01: keanggotaan aktif pengguna beserta tanda pemilik (daftar tenant Aplikasi Owner).
     *
     * @return array<int, bool> IdTenant → Pemilik
     */
    public function AmbilTandaPemilik(int $idPengguna): array
    {
        $hasil = [];

        foreach (TenantPengguna::query()
            ->where('IdPengguna', $idPengguna)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->orderBy('Id')
            ->get(['IdTenant', 'Pemilik']) as $anggota) {
            $hasil[$anggota->IdTenant] = $anggota->Pemilik;
        }

        return $hasil;
    }

    /**
     * Semua tenant aktif yang punya anggota (dipakai perintah terjadwal yang menyapu tenant dan perintah penyelarasan
     * peran bawaan, F-02). Tenant yang akunnya sudah ditutup tidak ikut: mereka tidak punya pekerjaan lagi, dan dulu tetap
     * dikunjungi setiap putaran (bahkan tiap jam atau tiap 5 menit).
     *
     * @return list<int>
     */
    public function AmbilSemuaIdTenant(): array
    {
        return array_values(array_map('intval', TenantPengguna::query()
            ->whereIn('IdTenant', $this->tenantAktif->KueriId())
            ->distinct()
            ->orderBy('IdTenant')
            ->pluck('IdTenant')
            ->all()));
    }

    public function CekAnggota(int $idPengguna, int $idTenant): bool
    {
        return TenantPengguna::query()
            ->where('IdPengguna', $idPengguna)
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->exists();
    }
}
