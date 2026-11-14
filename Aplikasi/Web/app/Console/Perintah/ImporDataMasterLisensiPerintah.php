<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\DataBawaan\Aksi\ImporDataMasterLisensi;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * D-35 edisi Lisensi: memasang paket data master dari Payoung (tarif pajak & hari libur yang sudah terbit di konsol).
 * Aman dijalankan berulang.
 */
final class ImporDataMasterLisensiPerintah extends Command
{
    protected $signature = 'lisensi:impor-data-master {berkas : Berkas JSON dari Payoung}';

    protected $description = 'Memasang paket tarif pajak & hari libur dari Payoung di server edisi Lisensi (D-35).';

    public function handle(ImporDataMasterLisensi $impor): int
    {
        $berkas = Str::of(is_string($this->argument('berkas')) ? $this->argument('berkas') : '')->toString();
        $isi = is_readable($berkas) ? file_get_contents($berkas) : false;

        if ($isi === false) {
            $this->error("Berkas {$berkas} tidak bisa dibaca.");

            return self::FAILURE;
        }

        try {
            $hasil = $impor->Jalankan($isi);
        } catch (PelanggaranAturanBisnis $galat) {
            $this->error($galat->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Tarif pajak baru: %d (sudah ada/dilewati: %d). Hari libur baru: %d, dibatalkan: %d.',
            $hasil['TarifBaru'],
            $hasil['TarifDilewati'],
            $hasil['HariLiburBaru'],
            $hasil['HariLiburDibatalkan'],
        ));

        return self::SUCCESS;
    }
}
