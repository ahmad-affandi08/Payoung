<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\TimInternal\Aksi\SiapkanPeranBawaan;
use Illuminate\Console\Command;

/**
 * P-01: menyelaraskan izin tujuh peran internal bawaan konsol dengan `PeranPengelolaBawaan`. Izin peran disimpan di
 * basis data, jadi izin yang baru ditambahkan (misal `komunikasi.kelola`, `tenant.hapus-permanen`) tidak otomatis
 * dimiliki akun yang dibuat sebelum deploy. Idempoten; dijalankan `rilis:pasang` di setiap rilis.
 */
final class SiapkanPeranPengelolaPerintah extends Command
{
    protected $signature = 'pengelola:siapkan-peran';

    protected $description = 'Menyelaraskan izin peran internal bawaan konsol Pengelola dengan kode (P-01).';

    public function handle(SiapkanPeranBawaan $siapkan): int
    {
        $siapkan->Jalankan();

        $this->info('Peran internal bawaan konsol diselaraskan.');

        return self::SUCCESS;
    }
}
