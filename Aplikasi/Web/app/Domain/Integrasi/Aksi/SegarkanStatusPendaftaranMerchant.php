<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\GalatPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;

/**
 * Membaca status KYB terbaru dari penyedia pembayaran (DOKU: Get Business Data, sumber kebenaran) dan menyimpannya: `UPDATING` = Ditinjau,
 * `ACTIVE` = Aktif (menyimpan ID Brand dan shared key terenkripsi), ditolak = Ditolak beserta alasannya. Dipakai
 * penyapu terjadwal (jalur cadangan), tugas pemicu webhook KYB, dan tombol "Segarkan status" di konsol.
 *
 * Konteks tenant harus sudah diatur pemanggil. Pendaftaran yang belum terdaftar di DOKU diabaikan (null). Galat penyedia
 * dilempar sebagai `GalatPendaftaranMerchant` (pesan sudah tersaring) dan tidak mengubah status yang tersimpan.
 */
final class SegarkanStatusPendaftaranMerchant
{
    public const AKSI_AUDIT = 'merchant-pembayaran.status';

    public function __construct(
        private readonly PendaftarMerchantPembayaran $penyedia,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws GalatPendaftaranMerchant
     */
    public function Jalankan(): ?PendaftaranMerchantPembayaran
    {
        $pendaftaran = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->first();

        if ($pendaftaran === null || ! $pendaftaran->CekSudahTerdaftar() || ! $this->penyedia->CekAktif()) {
            return null;
        }

        $hasil = $this->penyedia->AmbilStatus($pendaftaran);
        $lama = $pendaftaran->Status;
        $baru = $hasil->status;
        $dapatBerubah = in_array($lama, [StatusPendaftaranMerchant::Ditinjau, StatusPendaftaranMerchant::Aktif, StatusPendaftaranMerchant::Ditolak], true);

        $pendaftaran->DiperiksaPada = now();
        $pendaftaran->StatusDoku = $hasil->statusPenyedia ?? $pendaftaran->StatusDoku;

        if ($hasil->idBrand !== null) {
            $pendaftaran->IdBrandDoku = $hasil->idBrand;
        }

        if ($hasil->kunciBersama !== null) {
            $pendaftaran->KunciBersama = $hasil->kunciBersama;
        }

        if ($dapatBerubah) {
            $pendaftaran->Status = $baru;
            $pendaftaran->AlasanPenolakan = $baru === StatusPendaftaranMerchant::Ditolak ? ($hasil->alasanPenolakan ?? $pendaftaran->AlasanPenolakan ?? $this->penyedia->AmbilLabel().' menolak pendaftaran ini.') : null;

            if ($baru === StatusPendaftaranMerchant::Aktif && $pendaftaran->DisetujuiPada === null) {
                $pendaftaran->DisetujuiPada = now();
            }
        }

        $pendaftaran->save();

        if ($dapatBerubah && $lama !== $baru) {
            $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, ['Status' => $lama->value], ['Status' => $baru->value]);
        }

        return $pendaftaran;
    }
}
