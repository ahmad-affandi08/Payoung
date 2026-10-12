<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-17: sakelar pesan sendiri (QR meja) per outlet. Menghidupkan butuh outlet aktif dan fitur paket
 * `kanal.self-order` aktif di outlet (add-on, BR-P04.7, BR-01.3); mematikan selalu boleh. Audit
 * `outlet.pesan-sendiri.ubah`.
 */
final class AturPesanSendiriOutlet
{
    public function __construct(
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(Outlet $outlet, bool $aktif): Outlet
    {
        return DB::transaction(function () use ($outlet, $aktif): Outlet {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->Id);
            $lama = $outlet->PesanSendiriAktif;

            if ($aktif && $outlet->Status !== StatusOrganisasi::Aktif) {
                throw new PelanggaranAturanBisnis('OutletDiarsipkan', 'Outlet ini diarsipkan. Pulihkan outlet dulu untuk menerima pesanan QR.');
            }

            if ($aktif && ! $this->fitur->CekAktif($outlet->IdTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI)) {
                throw new PelanggaranAturanBisnis('FiturTidakAktif', 'Pesan sendiri lewat QR meja belum aktif di paket usaha ini. Tambahkan add-on Self-order QR di menu Langganan.');
            }

            if ($lama !== $aktif) {
                $outlet->forceFill(['PesanSendiriAktif' => $aktif])->save();
                $this->audit->Catat('outlet.pesan-sendiri.ubah', $outlet, nilaiLama: ['PesanSendiriAktif' => $lama], nilaiBaru: ['PesanSendiriAktif' => $aktif]);
            }

            return $outlet;
        });
    }
}
