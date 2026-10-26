<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Model\HalamanSitus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D-21/D-25: menimpa halaman situs pemasaran dengan isi bawaan terbaru dari `KontenSitusBawaan`.
 *
 * `SiapkanHalamanSitusBawaan` sengaja tidak menyentuh halaman yang sudah ada (idempoten), dan
 * `PenyusunHalamanSitus::AmbilTerbit()` selalu memakai baris database bila ada. Akibatnya perubahan copy di
 * `KontenSitusBawaan` tidak pernah tampil di pemasangan yang halamannya sudah terlanjur dibuat. Perintah ini
 * jalan keluarnya, dipakai sekali setelah copy bawaan diperbarui.
 *
 * **Menimpa, bukan menggabung.** Semua suntingan konsol pada halaman yang disegarkan akan hilang, karena itu
 * perintah ini meminta konfirmasi dan menyebutkan halaman mana saja yang akan ditimpa.
 */
final class SegarkanHalamanSitusBawaanPerintah extends Command
{
    protected $signature = 'situs:segarkan-bawaan
        {--halaman=* : Slug halaman tertentu (kosong = semua halaman bawaan)}
        {--paksa : Lewati konfirmasi (untuk skrip deploy)}';

    protected $description = 'Menimpa halaman situs pemasaran dengan isi bawaan terbaru (D-21, D-25).';

    public function handle(): int
    {
        $bawaan = KontenSitusBawaan::AmbilHalaman();
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): string => is_string($nilai) ? $nilai : '',
            (array) $this->option('halaman'),
        ), fn (string $slug): bool => $slug !== ''));

        if ($diminta !== []) {
            $takDikenal = array_diff($diminta, array_keys($bawaan));

            if ($takDikenal !== []) {
                $this->error('Slug tidak dikenal: '.implode(', ', $takDikenal));
                $this->line('Yang tersedia: '.implode(', ', array_keys($bawaan)));

                return self::FAILURE;
            }

            $bawaan = array_intersect_key($bawaan, array_flip($diminta));
        }

        $adaDiDatabase = HalamanSitus::query()
            ->whereIn('Slug', array_keys($bawaan))
            ->pluck('Slug')
            ->all();

        if ($adaDiDatabase === []) {
            $this->info('Tidak ada halaman yang perlu disegarkan. Situs sudah memakai isi bawaan.');

            return self::SUCCESS;
        }

        $this->warn('Halaman berikut akan DITIMPA dengan isi bawaan, termasuk suntingan yang dibuat di konsol:');

        foreach ($adaDiDatabase as $slug) {
            $this->line('  - '.$slug);
        }

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan?', false)) {
            $this->info('Dibatalkan. Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $jumlah = 0;

        DB::transaction(function () use ($bawaan, $adaDiDatabase, &$jumlah): void {
            foreach ($bawaan as $slug => $isi) {
                if (! in_array($slug, $adaDiDatabase, true)) {
                    continue;
                }

                // Draf dan terbit diisi sekaligus supaya perubahan langsung tampil, sama seperti saat
                // halaman bawaan pertama kali dibuat.
                HalamanSitus::query()->where('Slug', $slug)->update([
                    'Judul' => $isi['Judul'],
                    'JudulSeo' => $isi['JudulSeo'],
                    'DeskripsiSeo' => $isi['DeskripsiSeo'],
                    'BagianDraf' => $isi['Bagian'],
                    'BagianTerbit' => $isi['Bagian'],
                    'JudulTerbit' => $isi['Judul'],
                    'JudulSeoTerbit' => $isi['JudulSeo'],
                    'DeskripsiSeoTerbit' => $isi['DeskripsiSeo'],
                    'DiterbitkanPada' => now(),
                ]);
                $jumlah++;
            }
        });

        $this->info("Selesai: {$jumlah} halaman disegarkan dan langsung terbit.");

        return self::SUCCESS;
    }
}
