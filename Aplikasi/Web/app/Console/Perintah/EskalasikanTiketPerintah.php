<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Dukungan\Aksi\EskalasikanTiketLewatSla;
use Illuminate\Console\Command;

/**
 * P-09: eskalasi otomatis tiket yang melewati SLA respons pertama (terjadwal tiap 10 menit, routes/console.php).
 */
final class EskalasikanTiketPerintah extends Command
{
    protected $signature = 'dukungan:eskalasikan-tiket';

    protected $description = 'Menaikkan tiket dukungan yang melewati SLA respons pertama ke L2 lalu Teknis (P-09).';

    public function handle(EskalasikanTiketLewatSla $eskalasi): int
    {
        $hasil = $eskalasi->Jalankan();
        $this->info("{$hasil['L2']} tiket naik ke L2, {$hasil['Teknis']} tiket naik ke Teknis.");

        return self::SUCCESS;
    }
}
