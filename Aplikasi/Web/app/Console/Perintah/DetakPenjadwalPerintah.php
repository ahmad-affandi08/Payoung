<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Sinkron\Layanan\PencatatGalatSinkron;
use App\Domain\Pengelola\Operasional\Aksi\CatatDetakPenjadwal;
use App\Domain\Pengelola\Operasional\Aksi\PeriksaKondisiOperasional;
use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use Illuminate\Console\Command;

/**
 * BR-P11.1: detak scheduler tiap menit (routes/console.php), lalu memeriksa alert operasional.
 */
final class DetakPenjadwalPerintah extends Command
{
    protected $signature = 'pengelola:detak';

    protected $description = 'Mencatat detak scheduler dan memeriksa alert operasional (P-11, BR-P11.1).';

    public function handle(CatatDetakPenjadwal $catatDetak, PeriksaKondisiOperasional $periksa, PencatatMetrikApi $metrik, PencatatGalatSinkron $galatSinkron): int
    {
        $catatDetak->Jalankan();
        // PGL-20: pindahkan penghitung metrik API menit-menit yang sudah lewat ke tabel sebelum alert diperiksa.
        $metrik->Vaskan();
        $metrik->VaskanGalatSinkron($galatSinkron);
        $aktif = $periksa->Jalankan();

        $this->info($aktif === [] ? 'Detak tercatat. Tidak ada alert aktif.' : 'Detak tercatat. Alert aktif: '.implode(', ', $aktif).'.');

        return self::SUCCESS;
    }
}
