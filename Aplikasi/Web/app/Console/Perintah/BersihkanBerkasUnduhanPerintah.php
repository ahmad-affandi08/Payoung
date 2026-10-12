<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Unduhan\Aksi\BersihkanBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;

/**
 * Harian merawat Pusat Unduhan (D-124): berkas kedaluwarsa dihapus, penyiapan yang macet ditandai Gagal, jejak lama
 * dibuang. Hanya tenant yang punya pekerjaan yang dikunjungi.
 */
final class BersihkanBerkasUnduhanPerintah extends Command
{
    protected $signature = 'unduhan:bersihkan';

    protected $description = 'Menghapus berkas Pusat Unduhan yang kedaluwarsa dan menandai penyiapan yang macet.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, BersihkanBerkasUnduhan $bersihkan): int
    {
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            foreach ($pengelola->IdTenantDenganPekerjaan(BerkasUnduhan::class, fn ($q) => BersihkanBerkasUnduhan::SaringPekerjaan($q)) as $idTenant) {
                $konteks->Atur($idTenant);
                $jumlah += $bersihkan->Jalankan();
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$jumlah} berkas unduhan dirawat.");

        return self::SUCCESS;
    }
}
