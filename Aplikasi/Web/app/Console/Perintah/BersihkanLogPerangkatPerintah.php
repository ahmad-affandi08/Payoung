<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Model\LogPerangkatDiunggah;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PGL-16 bagian 2: menghapus log perangkat yang diunggah atas permintaan dukungan setelah
 * `LogPerangkatDiunggah::HARI_SIMPAN` hari (jadwal harian). Log hanya alat bantu pemecahan masalah, bukan arsip. Hanya tenant
 * yang punya log kedaluwarsa yang dikunjungi (pola D-81).
 */
final class BersihkanLogPerangkatPerintah extends Command
{
    protected $signature = 'dukungan:bersihkan-log-perangkat';

    protected $description = 'Menghapus log perangkat yang diunggah untuk dukungan setelah masa simpan habis (PGL-16).';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks): int
    {
        $batas = now()->subDays(LogPerangkatDiunggah::HARI_SIMPAN);
        $sebelumnya = $konteks->Ambil();
        $dihapus = 0;

        try {
            foreach ($pengelola->IdTenantDenganPekerjaan(LogPerangkatDiunggah::class, fn ($q) => $q->where('DiunggahPada', '<', $batas), termasukDitutup: true) as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $dihapus += LogPerangkatDiunggah::query()->where('DiunggahPada', '<', $batas)->delete();
                } catch (Throwable $galat) {
                    Log::warning('Log perangkat dukungan gagal dibersihkan, dicoba lagi besok.', ['IdTenant' => $idTenant, 'Galat' => $galat->getMessage()]);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$dihapus} log perangkat dihapus.");

        return self::SUCCESS;
    }
}
