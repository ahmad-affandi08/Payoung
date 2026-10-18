<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\Tenant;

/**
 * Pengelola mengisi manual `IdPedagangQris` (merchantId) dan `IdTerminalQris` (terminalId) hasil aktivasi QRIS per Brand
 * di DOKU Dashboard. Kolom ini hanya penampung sampai cara membacanya otomatis diketahui: TIDAK dipakai di mana pun
 * (jalur QRIS toko tidak disentuh). Tercatat di `LogAuditPengelola` (`tenant.merchant.penampung-qris`).
 */
final class AturPenampungQrisMerchantTenant
{
    public const AKSI_AUDIT = 'tenant.merchant.penampung-qris';

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, Tenant $tenant, ?string $idPedagang, ?string $idTerminal): PendaftaranMerchantPembayaran
    {
        return $this->konteks->JalankanLintasTenant('Mengisi penampung merchantId/terminalId QRIS DOKU', function () use ($pelaku, $tenant, $idPedagang, $idTerminal): PendaftaranMerchantPembayaran {
            $pendaftaran = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->first();

            if ($pendaftaran === null) {
                throw new PelanggaranAturanBisnis('BelumTerdaftar', 'Tenant ini belum punya pendaftaran merchant.', statusHttp: 409);
            }

            $lama = ['IdPedagangQris' => $pendaftaran->IdPedagangQris, 'IdTerminalQris' => $pendaftaran->IdTerminalQris];
            $baru = ['IdPedagangQris' => $idPedagang, 'IdTerminalQris' => $idTerminal];
            $pendaftaran->update($baru);
            $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, $lama, $baru, idPelaku: $pelaku->Id, idTenant: $tenant->Id);

            return $pendaftaran;
        }, $tenant->Id);
    }
}
