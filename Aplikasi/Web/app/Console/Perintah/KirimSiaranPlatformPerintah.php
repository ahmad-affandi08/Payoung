<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Komunikasi\Aksi\KirimSiaranJatuhTempo;
use Illuminate\Console\Command;

/** Mengirim siaran Payoung yang jadwalnya tiba, bertahap tiap lima menit (D-210). */
final class KirimSiaranPlatformPerintah extends Command
{
    protected $signature = 'komunikasi:kirim-siaran';

    protected $description = 'Mengirim siaran platform ke pemilik tenant yang jadwalnya sudah tiba.';

    public function handle(KirimSiaranJatuhTempo $kirim): int
    {
        $hasil = $kirim->Jalankan();
        $this->line("{$hasil['Siaran']} siaran diproses, {$hasil['Terkirim']} pesan terkirim.");

        return self::SUCCESS;
    }
}
