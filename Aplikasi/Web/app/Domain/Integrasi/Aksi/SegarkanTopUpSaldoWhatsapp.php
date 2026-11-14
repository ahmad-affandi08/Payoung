<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * D-300: menanyakan status top-up yang masih Menunggu ke API status penyedia (saat halaman Saldo dibuka, dan oleh
 * rekonsiliasi terjadwal), tanpa menunggu webhook. Top-up lewat 24 jam tanpa lunas ditutup Kedaluwarsa. Galat apa pun
 * tidak menggagalkan halaman. Dibatasi satu pertanyaan per top-up tiap 15 detik.
 */
final class SegarkanTopUpSaldoWhatsapp
{
    public const JEDA_DETIK = 15;

    public function __construct(
        private readonly PenyediaTagihanPlatform $penyedia,
        private readonly TerimaNotifikasiTopUpWhatsapp $terima,
    ) {}

    /** @return bool true bila ada top-up yang berubah status. */
    public function Jalankan(TopUpSaldoWhatsapp $topUp): bool
    {
        if ($topUp->Status !== StatusTopUpSaldoWhatsapp::Menunggu || $topUp->RefGateway === null || ! Cache::add("topup-wa.segarkan.{$topUp->Uuid}", 1, self::JEDA_DETIK)) {
            return false;
        }

        try {
            $notifikasi = $this->penyedia->TanyaStatus($topUp->RefGateway, null, null, null, $topUp->DibuatPada ?? now(), $topUp->Jumlah);

            if ($notifikasi !== null && $notifikasi->status !== StatusPembayaranGerbang::Menunggu) {
                return $this->terima->Jalankan($notifikasi);
            }

            if ($topUp->DibuatPada !== null && $topUp->DibuatPada->lt(now()->subHours(TopUpSaldoWhatsapp::JAM_KEDALUWARSA))) {
                $topUp->update(['Status' => StatusTopUpSaldoWhatsapp::Kedaluwarsa]);

                return true;
            }
        } catch (Throwable $galat) {
            report($galat);
        }

        return false;
    }
}
