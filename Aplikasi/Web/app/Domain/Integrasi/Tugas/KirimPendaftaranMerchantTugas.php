<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Aksi\KirimPendaftaranMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Antrean: unggah berkas KYC dan registrasi merchant tenant ke DOKU Partner API. Payload hanya Id (tidak ada NIK,
 * rekening, atau foto). Galat tidak pasti diulang dengan jeda; percobaan habis mengembalikan pendaftaran ke `Draf`.
 */
final class KirimPendaftaranMerchantTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPendaftaran,
    ) {}

    public function handle(KonteksTenant $konteks, KirimPendaftaranMerchant $kirim): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            if (! $kirim->Jalankan()) {
                return;
            }

            if ($this->attempts() < $this->tries) {
                $this->release([60, 300, 900][min($this->attempts() - 1, 2)]);

                return;
            }

            $kirim->Menyerah();
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
