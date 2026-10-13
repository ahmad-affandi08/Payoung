<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Data;

/**
 * Pesan komunikasi yang sudah dirakit (PGL-07): `Subjek`/`Judul` hanya terisi untuk email. `Sumber` = `Templat` (versi
 * terbit dari konsol) atau `Bawaan` (teks kode); `Versi` hanya untuk `Templat`.
 */
final readonly class PesanKomunikasi
{
    public function __construct(
        public ?string $subjek,
        public ?string $judul,
        public string $isi,
        public string $sumber,
        public ?int $versi,
    ) {}
}
