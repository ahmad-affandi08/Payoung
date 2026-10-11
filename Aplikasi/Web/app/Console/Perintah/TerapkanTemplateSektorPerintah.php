<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\TemplateSektor\Aksi\TerapkanTemplateBawaan;
use Illuminate\Console\Command;

/**
 * Satu perintah untuk menerapkan template sektor bawaan rilis ke server production, langsung terbit tanpa draf (D-88).
 * Aman diulang. `--kering` hanya menampilkan rencana. Berakhir dengan kode gagal bila ada template yang tidak lolos
 * validasi, supaya skrip deploy tidak menganggapnya berhasil.
 */
final class TerapkanTemplateSektorPerintah extends Command
{
    protected $signature = 'template:terapkan {--kering : Hanya tampilkan rencana, jangan ubah data}';

    protected $description = 'Menerapkan template sektor bawaan rilis ke server: template baru & versi baru langsung terbit (tanpa draf), tenant lama tidak berubah.';

    public function handle(TerapkanTemplateBawaan $terapkan): int
    {
        $kering = (bool) $this->option('kering');
        $awalan = $kering ? '[kering] ' : '';
        $hasil = $terapkan->Jalankan(terapkan: ! $kering);
        $berubah = 0;
        $gagal = 0;

        foreach ($hasil as $baris) {
            $versi = $baris['Versi'] === null ? '' : " (versi {$baris['Versi']})";
            $this->line("{$awalan}template {$baris['Kode']}: {$baris['Tindakan']}{$versi}");

            foreach ($baris['Catatan'] as $catatan) {
                $this->line("{$awalan}    - {$catatan}");
            }

            if ($baris['Tindakan'] === 'Gagal validasi') {
                $gagal++;
            } elseif ($baris['Tindakan'] !== 'Tanpa perubahan' && $baris['Tindakan'] !== 'Dilewati') {
                $berubah++;
            }
        }

        $this->info(($kering ? 'Akan menerbitkan ' : 'Menerbitkan ')."{$berubah} template/versi, ".count($hasil)." template diperiksa, {$gagal} gagal validasi.");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
