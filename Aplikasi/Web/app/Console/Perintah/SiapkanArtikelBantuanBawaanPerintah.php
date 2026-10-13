<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Konten\Aksi\SiapkanArtikelBantuanBawaan;
use Illuminate\Console\Command;

/**
 * PLT-12: membuat artikel Pusat Bantuan awal (terbit) bila belum ada. Aman dijalankan berulang: artikel yang sudah ada
 * tidak ditimpa.
 */
final class SiapkanArtikelBantuanBawaanPerintah extends Command
{
    protected $signature = 'bantuan:siapkan-bawaan';

    protected $description = 'Membuat artikel Pusat Bantuan awal yang belum ada (PLT-12). Artikel yang sudah ada tidak ditimpa.';

    public function handle(SiapkanArtikelBantuanBawaan $siapkan): int
    {
        $dibuat = $siapkan->Jalankan();

        if ($dibuat === []) {
            $this->info('Semua artikel bantuan awal sudah ada. Tidak ada yang dibuat.');

            return self::SUCCESS;
        }

        foreach ($dibuat as $slug) {
            $this->line("Artikel dibuat dan diterbitkan: {$slug}");
        }

        $this->info(count($dibuat).' artikel dibuat. Sunting atau tarik lewat konsol (Situs pemasaran › Bantuan).');

        return self::SUCCESS;
    }
}
