<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-17 bagian 4: sakelar kios pesan sendiri per outlet dan tautan rahasianya. Menghidupkan butuh outlet aktif dan
 * fitur paket `kanal.self-order` aktif di outlet (add-on, BR-P04.7); token dibuat saat pertama kali dihidupkan.
 * Mematikan selalu boleh dan menyimpan token (tablet tidak perlu dipasang ulang saat dihidupkan lagi).
 * `BuatUlangToken` mematikan tautan lama seketika (tablet yang hilang). Audit `outlet.kios.ubah` dan
 * `outlet.kios.buat-ulang`; token tidak pernah dicatat.
 */
final class AturKiosOutlet
{
    public const PANJANG_TOKEN = 32;

    public function __construct(
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(Outlet $outlet, bool $aktif): Outlet
    {
        return DB::transaction(function () use ($outlet, $aktif): Outlet {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->Id);
            $lama = $outlet->KiosAktif;

            if ($aktif && $outlet->Status !== StatusOrganisasi::Aktif) {
                throw new PelanggaranAturanBisnis('OutletDiarsipkan', 'Outlet ini diarsipkan. Pulihkan outlet dulu untuk mengaktifkan kios.');
            }

            if ($aktif && ! $this->fitur->CekAktif($outlet->IdTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI)) {
                throw new PelanggaranAturanBisnis('FiturTidakAktif', 'Kios pesan sendiri belum aktif di paket usaha ini. Tambahkan add-on Self-order QR di menu Langganan.');
            }

            if ($lama !== $aktif || ($aktif && $outlet->TokenKios === null)) {
                $outlet->forceFill([
                    'KiosAktif' => $aktif,
                    'TokenKios' => $outlet->TokenKios ?? ($aktif ? Str::random(self::PANJANG_TOKEN) : null),
                ])->save();
                $this->audit->Catat('outlet.kios.ubah', $outlet, nilaiLama: ['KiosAktif' => $lama], nilaiBaru: ['KiosAktif' => $aktif]);
            }

            return $outlet;
        });
    }

    public function BuatUlangToken(Outlet $outlet): Outlet
    {
        return DB::transaction(function () use ($outlet): Outlet {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->Id);
            $adaLama = $outlet->TokenKios !== null;
            $outlet->forceFill(['TokenKios' => Str::random(self::PANJANG_TOKEN)])->save();
            $this->audit->Catat('outlet.kios.buat-ulang', $outlet, nilaiLama: ['AdaToken' => $adaLama], nilaiBaru: ['AdaToken' => true]);

            return $outlet;
        });
    }
}
