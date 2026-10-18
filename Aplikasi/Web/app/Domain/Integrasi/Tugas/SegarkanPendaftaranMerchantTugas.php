<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Aksi\SegarkanStatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\GalatPendaftaranMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Antrean: baca status KYB terbaru dari DOKU setelah webhook callback diterima. Isi webhook tidak pernah dipercaya;
 * tugas ini hanya memicu pembacaan Get Business Data. Penyapu terjadwal tetap menjadi jalur cadangan.
 */
final class SegarkanPendaftaranMerchantTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $idTenant) {}

    public function handle(KonteksTenant $konteks, SegarkanStatusPendaftaranMerchant $segarkan): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $segarkan->Jalankan();
        } catch (GalatPendaftaranMerchant $galat) {
            if (! $galat->tidakPasti) {
                // Penolakan pasti tidak membaik dengan diulang; penyapu 30 menit tetap mencoba lagi.
                return;
            }

            throw $galat;
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
