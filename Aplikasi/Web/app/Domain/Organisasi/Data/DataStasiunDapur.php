<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

/**
 * Isian stasiun dapur tingkat tenant (F-10a).
 */
final readonly class DataStasiunDapur
{
    public function __construct(
        public string $nama,
        public int $urutan,
        /** D-310: null = tidak diubah (pemanggil lama); true = stasiun checker (hanya menerima salinan tiket). */
        public ?bool $salinan = null,
    ) {}
}
