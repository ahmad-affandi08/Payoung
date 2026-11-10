<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

/**
 * Satu lembar dalam buku kerja Excel banyak lembar (D-255): definisi laporan, nama lembar (disaring oleh penulis), dan
 * apakah lembar disembunyikan (tetap ada supaya rumus lembar lain berjalan).
 */
final readonly class LembarBuku
{
    public function __construct(
        public DefinisiLaporan $definisi,
        public string $nama,
        public bool $tersembunyi = false,
    ) {}
}
