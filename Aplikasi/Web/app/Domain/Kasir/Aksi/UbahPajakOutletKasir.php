<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\SimpanProfilPajakOutlet;
use App\Domain\Organisasi\Data\DataProfilPajakOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah pajak dan biaya layanan satu outlet dari halaman Atur kasir (D-263). Sebelumnya pengaturan ini hanya bisa
 * diubah lewat langkah Pajak di Panduan awal, padahal ia menentukan total setiap transaksi. Aturannya sama dengan
 * `KonfirmasiPajakPanduan` (PBJT butuh kota outlet karena tarifnya per daerah, status PKP mengikuti profil usaha,
 * tarif tidak disimpan di outlet) tetapi tidak menyentuh status langkah panduan. Perubahan hanya berlaku untuk
 * penjualan berikutnya, penjualan lama menyimpan snapshot pengaturannya sendiri.
 */
final class UbahPajakOutletKasir
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profilTenant,
        private readonly PenguncianTenant $penguncian,
        private readonly SimpanProfilPajakOutlet $simpanProfilPajak,
    ) {}

    public function Jalankan(Outlet $outlet, bool $pungutPbjt, bool $biayaLayananAktif, string $persenBiayaLayanan, bool $hargaTermasukPajak): Outlet
    {
        if ($pungutPbjt && $outlet->KodeKota === null) {
            throw new PelanggaranAturanBisnis('KotaBelumDiisi', 'Isi kota outlet di Profil usaha dulu. Tarif PBJT mengikuti kota.', 'PungutPbjt');
        }

        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($outlet, $pungutPbjt, $biayaLayananAktif, $persenBiayaLayanan, $hargaTermasukPajak, $idTenant): Outlet {
            // Urutan kunci Tenant lalu Outlet, sama dengan penerapan template dan langkah Pajak panduan.
            $this->penguncian->Kunci($idTenant);
            $pkp = $this->profilTenant->Ambil($idTenant)['Pkp'];

            return $this->simpanProfilPajak->Jalankan($outlet, new DataProfilPajakOutlet(
                pkp: $pkp,
                pungutPbjt: $pungutPbjt,
                biayaLayananAktif: $biayaLayananAktif,
                persenBiayaLayanan: $biayaLayananAktif ? $persenBiayaLayanan : '0',
                hargaTermasukPajak: $hargaTermasukPajak,
            ));
        });
    }
}
