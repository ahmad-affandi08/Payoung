<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Komunikasi\Aksi\KirimKampanyeProspekJatuhTempo;
use Illuminate\Console\Command;

/** Mengirim kampanye email prospek yang berjalan, sedikit demi sedikit tiap lima menit pada jam kerja (D-216). */
final class KirimKampanyeProspekPerintah extends Command
{
    protected $signature = 'prospek:kirim-kampanye';

    protected $description = 'Mengirim kampanye email prospek yang berjalan (bertahap, 08.00–17.00 WIB).';

    public function handle(KirimKampanyeProspekJatuhTempo $kirim): int
    {
        $hasil = $kirim->Jalankan();
        $this->line("{$hasil['Kampanye']} kampanye diproses, {$hasil['Terkirim']} email terkirim.");

        return self::SUCCESS;
    }
}
