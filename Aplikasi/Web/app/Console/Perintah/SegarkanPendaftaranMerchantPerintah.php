<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Aksi\SegarkanStatusPendaftaranMerchant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\GalatPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Jalur cadangan webhook KYB: tiap 30 menit membaca status pendaftaran merchant yang masih
 * `Ditinjau` dari penyedia. Hanya tenant yang punya pekerjaan yang dikunjungi (pola D-81 `IdTenantDenganPekerjaan`), dan yang baru
 * diperiksa kurang dari 25 menit lalu (mis. oleh callback) dilewati. Satu galat penyedia tidak menghentikan tenant lain.
 */
final class SegarkanPendaftaranMerchantPerintah extends Command
{
    protected $signature = 'pembayaran:segarkan-pendaftaran-merchant';

    protected $description = 'Menyegarkan status KYB pendaftaran merchant pembayaran yang masih ditinjau penyedia.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, SegarkanStatusPendaftaranMerchant $segarkan, PendaftarMerchantPembayaran $penyedia): int
    {
        if (! $penyedia->CekAktif()) {
            $this->line("Integrasi {$penyedia->AmbilLabel()} (pendaftaran merchant) belum diisi; dilewati.");

            return self::SUCCESS;
        }

        $sebelumnya = $konteks->Ambil();
        $diperiksa = 0;

        try {
            $batasPeriksa = now()->subMinutes(25);
            $daftar = $pengelola->IdTenantDenganPekerjaan(
                PendaftaranMerchantPembayaran::class,
                fn ($q) => $q->where('Status', StatusPendaftaranMerchant::Ditinjau->value)
                    ->where(fn ($w) => $w->whereNull('DiperiksaPada')->orWhere('DiperiksaPada', '<', $batasPeriksa)),
                (int) config('merchant.BatasPenyapuStatus'),
            );

            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $segarkan->Jalankan();
                    $diperiksa++;
                } catch (GalatPendaftaranMerchant $galat) {
                    // Pesan sudah tersaring dari rahasia; tenant lain tetap diperiksa.
                    Log::warning('Penyegaran status pendaftaran merchant gagal.', ['IdTenant' => $idTenant, 'Pesan' => $galat->getMessage()]);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$diperiksa} pendaftaran merchant diperiksa.");

        return self::SUCCESS;
    }
}
