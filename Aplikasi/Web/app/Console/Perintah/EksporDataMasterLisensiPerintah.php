<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\DataBawaan\Aksi\EksporDataMasterLisensi;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * D-35: dijalankan di server SaaS Payoung untuk membuat paket data master (tarif pajak & hari libur terbit) bagi server
 * edisi Lisensi. Kirim berkasnya ke pembeli bersama rilis; pembeli memasangnya dengan `lisensi:impor-data-master`.
 */
final class EksporDataMasterLisensiPerintah extends Command
{
    protected $signature = 'lisensi:ekspor-data-master {keluaran : Berkas JSON tujuan, misal data-master-2026-10.json}';

    protected $description = 'Mengekspor tarif pajak & hari libur terbit untuk server edisi Lisensi (D-35).';

    public function handle(EksporDataMasterLisensi $ekspor): int
    {
        $keluaran = Str::of(is_string($this->argument('keluaran')) ? $this->argument('keluaran') : '')->toString();
        $paket = $ekspor->Jalankan();

        if (file_put_contents($keluaran, json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n") === false) {
            $this->error("Tidak bisa menulis {$keluaran}.");

            return self::FAILURE;
        }

        $this->info(sprintf('%d tarif pajak & %d hari libur ditulis ke %s.', count($paket['TarifPajak']), count($paket['HariLibur']), $keluaran));

        return self::SUCCESS;
    }
}
