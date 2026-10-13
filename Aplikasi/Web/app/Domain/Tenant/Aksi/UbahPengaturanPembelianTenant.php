<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menulis pengaturan pembelian (`BatasPersetujuanPo`, `ToleransiPenerimaanPersen`) ke `Tenant.Pengaturan` tenant aktif
 * (F-04 fase 1). Dipanggil `Pembelian\Aksi\UbahPengaturanPembelian` yang memvalidasi dan mencatat audit. Kunci lain di
 * `Pengaturan` tidak disentuh.
 */
final class UbahPengaturanPembelianTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
    ) {}

    public function Jalankan(DataPengaturanPembelian $data): void
    {
        DB::transaction(function () use ($data): void {
            $tenant = $this->penguncian->Kunci($this->konteks->Wajib());
            $pengaturan = $tenant->Pengaturan ?? [];
            $pengaturan['BatasPersetujuanPo'] = $data->batasPersetujuanPo->KeString();
            $pengaturan['ToleransiPenerimaanPersen'] = (string) $data->toleransiPenerimaanPersen;
            $pengaturan['DrafPoOtomatis'] = $data->drafPoOtomatis;
            $pengaturan['SumberDrafPo'] = $data->sumberDrafPo;
            $pengaturan['HariCakupanDrafPo'] = $data->hariCakupanDrafPo;
            $tenant->Pengaturan = $pengaturan;

            if ($tenant->isDirty('Pengaturan')) {
                $tenant->save();
            }
        });
    }
}
