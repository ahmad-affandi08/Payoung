<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Data;

use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;

/**
 * Keluaran satu alat bantu dukungan sebelum dicatat `PelaksanaAlatDukungan` (BR-P09.3): status, ringkasan satu kalimat,
 * keadaan sebelum dan sesudah (angka, pengenal, dan kode saja; tanpa data pribadi), serta `data` untuk ditampilkan ke
 * petugas bila alat itu mengembalikan daftar.
 */
final readonly class HasilKerjaAlat
{
    /**
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>|null  $sesudah
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public StatusHasilAlatDukungan $status,
        public string $ringkasan,
        public ?array $sebelum = null,
        public ?array $sesudah = null,
        public array $data = [],
    ) {}
}
