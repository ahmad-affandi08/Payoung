<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Katalog\Aksi\TerapkanKatalogBawaan;
use Illuminate\Console\Command;

/**
 * Satu perintah untuk menerapkan harga paket, add-on, dan fitur katalog dari rilis ke server production (D-86).
 * Aman diulang. `--kering` hanya menampilkan rencana.
 */
final class TerapkanKatalogPerintah extends Command
{
    protected $signature = 'katalog:terapkan
        {--kering : Hanya tampilkan rencana, jangan ubah data}
        {--tanpa-pelanggan-lama : Harga baru hanya untuk langganan baru. Langganan yang sudah ada tetap memakai harga lamanya}
        {--tanpa-aktifkan-addon : Jangan mengaktifkan add-on yang masih diarsipkan}';

    protected $description = 'Menerapkan harga paket, add-on, dan fitur katalog dari berkas data rilis ke server (langsung terbit, berlaku untuk tenant yang sudah ada pada tagihan berikutnya).';

    public function handle(TerapkanKatalogBawaan $terapkan): int
    {
        $kering = (bool) $this->option('kering');
        $awalan = $kering ? '[kering] ' : '';
        $hasil = $terapkan->Jalankan(
            terapkan: ! $kering,
            pelangganLama: ! (bool) $this->option('tanpa-pelanggan-lama'),
            aktifkanAddon: ! (bool) $this->option('tanpa-aktifkan-addon'),
        );

        foreach ($hasil['FiturBaru'] as $kunci) {
            $this->line("{$awalan}fitur baru: {$kunci}");
        }

        foreach ($hasil['PenambahanFitur'] as $baris) {
            $this->line("{$awalan}paket {$baris['Paket']} + {$baris['Fitur']}");
        }

        foreach ($hasil['Harga'] as $baris) {
            $lama = $baris['HargaBulananLama'] ?? 'belum ada';
            $sampai = $baris['BerlakuSampai'] === null ? 'seterusnya' : "sampai {$baris['BerlakuSampai']}";
            $this->line("{$awalan}harga {$baris['Jenis']} paket {$baris['Paket']}: {$lama} → {$baris['HargaBulanan']} per bulan ({$baris['HargaTahunan']} per tahun), berlaku {$baris['BerlakuMulai']} {$sampai}");
        }

        foreach ($hasil['Addon'] as $baris) {
            $this->line("{$awalan}add-on {$baris['Kode']} ({$baris['Tindakan']}): ".implode(', ', $baris['Perubahan']));
        }

        $this->info(($kering ? 'Akan mengubah ' : 'Mengubah ').count($hasil['Harga']).' versi harga paket, '.count($hasil['Addon']).' add-on, '.count($hasil['FiturBaru']).' fitur katalog, dan '.count($hasil['PenambahanFitur']).' fitur paket.');

        return self::SUCCESS;
    }
}
