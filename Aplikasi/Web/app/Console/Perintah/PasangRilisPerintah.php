<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use Illuminate\Console\Command;
use Throwable;

/**
 * Satu perintah untuk semua langkah sesudah kode baru terpasang: migrasi, menyelaraskan data tenant lama dengan rilis
 * (peran, data bawaan, fitur paket, template sektor, artikel bantuan, kategori biaya), lalu menyegarkan cache. Setiap
 * langkah aman diulang, jadi cukup jalankan `php artisan rilis:pasang` setiap kali memperbarui, tanpa menghafal daftar.
 *
 * Sengaja TIDAK dijalankan di sini karena mengubah keputusan pemilik produk atau menimpa suntingan: `katalog:terapkan`
 * (harga paket), `situs:segarkan-bawaan` (halaman situs; hanya dengan `--dengan-situs`), dan `legal:siapkan-pembaruan`.
 *
 * Langkah yang tidak berlaku di edisi tertentu dipilih dari `config('lisensi.Edisi')`. Kegagalan migrasi menghentikan
 * semuanya (langkah lain bergantung pada skema); kegagalan langkah lain dicatat, sisanya tetap jalan, dan perintah
 * berakhir dengan kode gagal supaya skrip deploy tidak menganggapnya berhasil.
 */
final class PasangRilisPerintah extends Command
{
    /** Langkah data: `Kering` = perintah mengerti `--kering` (hanya menampilkan rencana). */
    public const LANGKAH_DATA_SAAS = [
        ['Perintah' => 'organisasi:siapkan-peran', 'Kering' => false],
        ['Perintah' => 'pengelola:siapkan-peran', 'Kering' => false],
        ['Perintah' => 'organisasi:rapikan-nama-bawaan', 'Kering' => false],
        ['Perintah' => 'panduan-awal:siapkan-bawaan', 'Kering' => false],
        ['Perintah' => 'katalog:lengkapi-fitur', 'Kering' => true],
        ['Perintah' => 'template:terapkan', 'Kering' => true],
        ['Perintah' => 'bantuan:siapkan-bawaan', 'Kering' => false],
        ['Perintah' => 'kasir:siapkan-kategori-biaya', 'Kering' => false],
    ];

    public const LANGKAH_DATA_LISENSI = [
        ['Perintah' => 'organisasi:siapkan-peran', 'Kering' => false],
        ['Perintah' => 'organisasi:rapikan-nama-bawaan', 'Kering' => false],
        ['Perintah' => 'panduan-awal:siapkan-bawaan', 'Kering' => false],
        ['Perintah' => 'lisensi:siapkan-data', 'Kering' => false],
        ['Perintah' => 'kasir:siapkan-kategori-biaya', 'Kering' => false],
    ];

    protected $signature = 'rilis:pasang
        {--kering : Hanya tampilkan rencana dari langkah yang mendukungnya; tidak migrasi dan tidak mengubah data}
        {--dengan-situs : Ikut menimpa halaman situs pemasaran dengan isi bawaan terbaru (situs:segarkan-bawaan)}';

    protected $description = 'Menjalankan semua langkah sesudah memperbarui kode: migrasi, penyelarasan data tenant lama, dan cache.';

    public function handle(): int
    {
        $kering = (bool) $this->option('kering');
        $lisensi = config('lisensi.Edisi') === 'Lisensi';
        $gagal = [];

        if (! $kering && ! $this->Jalankan('migrate', ['--force' => true])) {
            $this->error('Migrasi gagal; langkah lain dihentikan. Perbaiki dulu, lalu jalankan ulang.');

            return self::FAILURE;
        }

        foreach ($lisensi ? self::LANGKAH_DATA_LISENSI : self::LANGKAH_DATA_SAAS as $langkah) {
            if ($kering && ! $langkah['Kering']) {
                $this->line("[kering] {$langkah['Perintah']}: dilewati (tidak punya mode kering).");

                continue;
            }

            if (! $this->Jalankan($langkah['Perintah'], $kering ? ['--kering' => true] : [])) {
                $gagal[] = $langkah['Perintah'];
            }
        }

        if (! $kering && ! $lisensi && (bool) $this->option('dengan-situs')) {
            if (! $this->Jalankan('situs:segarkan-bawaan', ['--paksa' => true])) {
                $gagal[] = 'situs:segarkan-bawaan';
            }
        }

        if (! $kering) {
            foreach (['optimize:clear', 'optimize', 'queue:restart'] as $perintah) {
                if (! $this->Jalankan($perintah)) {
                    $gagal[] = $perintah;
                }
            }
        }

        if (! $lisensi) {
            $this->newLine();
            $this->line('Tidak dijalankan otomatis (keputusan sendiri): katalog:terapkan (harga paket), legal:siapkan-pembaruan'.((bool) $this->option('dengan-situs') ? '.' : ', situs:segarkan-bawaan (tambahkan --dengan-situs bila teks situs berubah).'));
        }

        if ($gagal !== []) {
            $this->error('Selesai dengan masalah pada: '.implode(', ', $gagal).'.');

            return self::FAILURE;
        }

        $this->info($kering ? 'Rencana selesai; tidak ada yang diubah.' : 'Rilis terpasang.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $argumen
     */
    private function Jalankan(string $perintah, array $argumen = []): bool
    {
        $this->newLine();
        $this->info("> {$perintah}");

        try {
            return $this->call($perintah, $argumen) === self::SUCCESS;
        } catch (Throwable $galat) {
            $this->error("{$perintah}: {$galat->getMessage()}");

            return false;
        }
    }
}
