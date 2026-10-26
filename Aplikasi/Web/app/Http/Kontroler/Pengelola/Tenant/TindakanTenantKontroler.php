<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Pengelola\Tenant\Aksi\AktifkanKembaliTenant;
use App\Domain\Pengelola\Tenant\Aksi\BuatOverrideTenant;
use App\Domain\Pengelola\Tenant\Aksi\CabutOverrideTenant;
use App\Domain\Pengelola\Tenant\Aksi\HapusPermanenTenantUji;
use App\Domain\Pengelola\Tenant\Aksi\PerpanjangTrial;
use App\Domain\Pengelola\Tenant\Aksi\TangguhkanTenant;
use App\Domain\Pengelola\Tenant\Aksi\TulisCatatanTenant;
use App\Domain\Pengelola\Tenant\Aksi\UbahPenandaTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Tenant\AlasanTindakanPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\BuatOverrideTenantPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\HapusPermanenTenantPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\PerpanjangTrialPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\TangguhkanTenantPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\TulisCatatanTenantPermintaan;
use App\Http\Permintaan\Pengelola\Tenant\UbahPenandaTenantPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * Tindakan pengelola pada satu tenant (P-07, tabel "Tindakan pengelola"). Semua wajib beralasan dan tercatat di
 * LogAuditPengelola dengan IdTenant (BR-P07.3). Izin per tindakan ditegakkan di rute (§19.3).
 */
final class TindakanTenantKontroler extends Kontroler
{
    use PelakuPengelola;

    public function PerpanjangTrial(Tenant $tenant, PerpanjangTrialPermintaan $permintaan, PerpanjangTrial $perpanjang): RedirectResponse
    {
        $langganan = $perpanjang->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilHari(), $permintaan->AmbilAlasan());
        $sampai = $langganan->TrialBerakhirPada?->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i');

        return back()->with('Kilat', "Trial {$tenant->Nama} diperpanjang sampai {$sampai} WIB.");
    }

    public function BuatOverride(Tenant $tenant, BuatOverrideTenantPermintaan $permintaan, BuatOverrideTenant $buat): RedirectResponse
    {
        $override = $buat->Jalankan(
            $this->AmbilPelaku(),
            $tenant,
            $permintaan->AmbilJenis(),
            $permintaan->AmbilKunci(),
            $permintaan->AmbilNilai(),
            $permintaan->AmbilBerakhirPada(),
            $permintaan->AmbilAlasan(),
        );

        return back()->with('Kilat', "Override {$override->Kunci} berlaku sampai {$override->BerakhirPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y')}.");
    }

    public function CabutOverride(Tenant $tenant, OverrideTenant $overrideTenant, AlasanTindakanPermintaan $permintaan, CabutOverrideTenant $cabut): RedirectResponse
    {
        abort_unless($overrideTenant->IdTenant === $tenant->Id, 404);
        $cabut->Jalankan($this->AmbilPelaku(), $overrideTenant, $permintaan->AmbilAlasan());

        return back()->with('Kilat', "Override {$overrideTenant->Kunci} dicabut.");
    }

    public function Tangguhkan(Tenant $tenant, TangguhkanTenantPermintaan $permintaan, TangguhkanTenant $tangguhkan): RedirectResponse
    {
        $tangguhkan->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilKategori(), $permintaan->AmbilCatatan());

        return back()->with('Kilat', "{$tenant->Nama} ditangguhkan. Owner diberi tahu lewat email.");
    }

    public function Aktifkan(Tenant $tenant, AlasanTindakanPermintaan $permintaan, AktifkanKembaliTenant $aktifkan): RedirectResponse
    {
        $langganan = $aktifkan->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilAlasan());

        return back()->with('Kilat', "{$tenant->Nama} aktif kembali dengan status {$langganan->Status->AmbilLabel()}.");
    }

    public function TulisCatatan(Tenant $tenant, TulisCatatanTenantPermintaan $permintaan, TulisCatatanTenant $tulis): RedirectResponse
    {
        $tulis->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilIsi());

        return back()->with('Kilat', 'Catatan internal disimpan.');
    }

    public function UbahPenanda(Tenant $tenant, UbahPenandaTenantPermintaan $permintaan, UbahPenandaTenant $ubah): RedirectResponse
    {
        $tenant = $ubah->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilPenanda(), $permintaan->AmbilAlasan());

        return back()->with('Kilat', $tenant->Penanda === null
            ? 'Penanda dihapus. Tenant kembali dihitung di metrik bisnis.'
            : "Tenant ditandai {$tenant->Penanda->AmbilLabel()} dan dikecualikan dari metrik bisnis.");
    }

    /**
     * D-213: hapus permanen tenant uji. Izin `tenant.hapus-permanen` dan 2FA baru ditegakkan di rute; penanda, tagihan,
     * dan slug ditegakkan aksi. Halaman tenant sudah tidak ada sesudahnya, jadi kembali ke daftar.
     */
    public function HapusPermanen(Tenant $tenant, HapusPermanenTenantPermintaan $permintaan, HapusPermanenTenantUji $hapus): RedirectResponse
    {
        $hasil = $hapus->Jalankan($this->AmbilPelaku(), $tenant, $permintaan->AmbilKonfirmasiSlug(), $permintaan->AmbilAlasan());
        $gagal = $hasil['BerkasGagal'] > 0 ? " {$hasil['BerkasGagal']} berkas gagal dihapus dari penyimpanan dan perlu dibersihkan manual." : '';

        return redirect('/tenant')->with('Kilat', "{$hasil['Nama']} dihapus permanen: {$hasil['Baris']} baris data dari {$hasil['Tabel']} tabel, {$hasil['Pengguna']} akun pengguna, dan {$hasil['Berkas']} berkas.{$gagal}");
    }
}
