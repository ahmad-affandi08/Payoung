<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Data\DataPengaturanKasir;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menulis pengaturan kasir (`BatasKasKeluar`, `ShiftBersama`, `BatasDiskonManual`, `BatasDiskonPenyetuju`,
 * `PembulatanTunai`, `TutupShiftButa`, `ToleransiSelisihKas`, `BatasHariRetur`, `BatasHariLewatJatuhTempo`, `BukaLaciPerluPin`,
 * `BatasReturTanpaStrukHarian`) ke `Tenant.Pengaturan` tenant aktif
 * (F-06, F-07b, F-11, F-09). Dipanggil `Kasir\Aksi\UbahPengaturanKasir` yang memvalidasi dan mencatat audit. Kunci lain di `Pengaturan` tidak disentuh.
 */
final class UbahPengaturanKasirTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
    ) {}

    public function Jalankan(DataPengaturanKasir $data): void
    {
        DB::transaction(function () use ($data): void {
            $tenant = $this->penguncian->Kunci($this->konteks->Wajib());
            $pengaturan = $tenant->Pengaturan ?? [];
            $pengaturan['BatasKasKeluar'] = $data->batasKasKeluar->KeString();
            $pengaturan['ShiftBersama'] = $data->shiftBersama;
            $pengaturan['BatasDiskonManual'] = (string) $data->batasDiskonManual;
            $pengaturan['BatasDiskonPenyetuju'] = (string) $data->batasDiskonPenyetuju;
            $pengaturan['PembulatanTunai'] = $data->AmbilPembulatanTunaiLarik();
            $pengaturan['TutupShiftButa'] = $data->tutupShiftButa;
            $pengaturan['ToleransiSelisihKas'] = $data->toleransiSelisihKas->KeString();
            $pengaturan['BatasHariRetur'] = $data->batasHariRetur;
            $pengaturan['BatasHariLewatJatuhTempo'] = $data->batasHariLewatJatuhTempo;
            $pengaturan['BukaLaciPerluPin'] = $data->bukaLaciPerluPin;
            $pengaturan['BatasReturTanpaStrukHarian'] = $data->batasReturTanpaStrukHarian->KeString();
            $pengaturan['BatasNotifikasiTransaksi'] = $data->batasNotifikasiTransaksi->KeString();
            $tenant->Pengaturan = $pengaturan;

            if ($tenant->isDirty('Pengaturan')) {
                $tenant->save();
            }
        });
    }
}
