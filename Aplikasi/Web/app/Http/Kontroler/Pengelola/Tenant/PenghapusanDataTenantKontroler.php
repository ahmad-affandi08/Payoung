<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Pengelola\Tenant\Aksi\LaksanakanPenghapusanDataTenant;
use App\Domain\Pengelola\Tenant\Aksi\TolakPenghapusanDataTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Tenant\AlasanTindakanPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\LaksanakanPenghapusanDataPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * Super Admin memutuskan permintaan penghapusan data dan penutupan akun tenant (UU PDP, P-07). Izin
 * `tenant.penghapusan-data.kelola` dan 2FA baru ditegakkan di rute; masa tunggu 14 hari ditegakkan aksi.
 */
final class PenghapusanDataTenantKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Laksanakan(
        Tenant $tenant,
        string $permintaan,
        LaksanakanPenghapusanDataPermintaan $isian,
        KonteksPengelola $konteks,
        LaksanakanPenghapusanDataTenant $laksanakan,
    ): RedirectResponse {
        $baris = $this->CariPermintaan($konteks, $tenant, $permintaan);
        $laksanakan->Jalankan($this->AmbilPelaku(), $baris, $isian->AmbilCatatan());

        return back()->with('Kilat', 'Penghapusan data dilaksanakan. Akun usaha ditutup dan bukti dikirim ke Owner.');
    }

    public function Tolak(
        Tenant $tenant,
        string $permintaan,
        AlasanTindakanPermintaan $isian,
        KonteksPengelola $konteks,
        TolakPenghapusanDataTenant $tolak,
    ): RedirectResponse {
        $baris = $this->CariPermintaan($konteks, $tenant, $permintaan);
        $tolak->Jalankan($this->AmbilPelaku(), $baris, $isian->AmbilAlasan());

        return back()->with('Kilat', 'Permintaan penghapusan data ditolak. Owner diberi tahu lewat email.');
    }

    private function CariPermintaan(KonteksPengelola $konteks, Tenant $tenant, string $uuid): PermintaanPenghapusanData
    {
        return $konteks->KueriDataPlatform(PermintaanPenghapusanData::class)
            ->where('Uuid', $uuid)
            ->where('IdTenant', $tenant->Id)
            ->firstOrFail();
    }
}
