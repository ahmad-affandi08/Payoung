<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Aksi\TerapkanTurunPaket;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tiap jam menerapkan turun paket yang sudah waktunya (BR-P04.4): ke Gratis saat periode berbayar berakhir, ke paket
 * berbayar lebih murah begitu perpanjangannya lunas. Outlet dan anggota di luar batas paket baru diarsipkan, tidak dihapus.
 * Hanya tenant yang punya permintaan menunggu yang dikunjungi (pola D-81); galat satu tenant tidak menghentikan yang lain.
 */
final class TerapkanTurunPaketPerintah extends Command
{
    protected $signature = 'langganan:terapkan-turun-paket';

    protected $description = 'Menerapkan permintaan turun paket yang sudah waktunya (BR-P04.4).';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, TerapkanTurunPaket $terapkan): int
    {
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            foreach ($pengelola->IdTenantDenganPekerjaan(PermintaanTurunPaket::class, fn ($q) => $q->where('Status', StatusPermintaanTurunPaket::Menunggu->value)) as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $jumlah += $terapkan->Jalankan();
                } catch (Throwable $galat) {
                    Log::warning('Turun paket gagal diterapkan, dicoba lagi putaran berikutnya.', ['IdTenant' => $idTenant, 'Galat' => $galat->getMessage()]);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$jumlah} permintaan turun paket diterapkan.");

        return self::SUCCESS;
    }
}
