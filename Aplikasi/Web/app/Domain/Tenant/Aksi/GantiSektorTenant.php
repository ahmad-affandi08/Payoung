<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Ganti jenis usaha: daftar sektor tenant (`Pengaturan.Sektor`) memuat sektor baru dan tidak lagi memuat sektor lama,
 * kecuali sektor lama masih dipakai outlet lain. Sektor lain (usaha campuran) tidak disentuh.
 */
final class GantiSektorTenant
{
    public function __construct(
        private readonly PenguncianTenant $penguncian,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return list<string> daftar sektor setelah diganti
     */
    public function Jalankan(int $idTenant, ?string $lama, string $baru, bool $lamaMasihDipakaiOutletLain): array
    {
        return DB::transaction(function () use ($idTenant, $lama, $baru, $lamaMasihDipakaiOutletLain): array {
            $tenant = $this->penguncian->Kunci($idTenant);
            $pengaturan = $tenant->Pengaturan ?? [];
            $sebelum = is_array($pengaturan['Sektor'] ?? null) ? array_values(array_filter($pengaturan['Sektor'], 'is_string')) : [];
            $sesudah = array_values(array_unique([
                ...array_filter($sebelum, fn (string $kode): bool => $lama === null || $lamaMasihDipakaiOutletLain || $kode !== $lama),
                $baru,
            ]));

            if ($sesudah !== $sebelum) {
                $pengaturan['Sektor'] = $sesudah;
                $tenant->Pengaturan = $pengaturan;
                $tenant->save();
                $this->audit->Catat('tenant.sektor.ganti', $tenant, nilaiLama: ['Sektor' => $sebelum], nilaiBaru: ['Sektor' => $sesudah]);
            }

            return $sesudah;
        });
    }
}
