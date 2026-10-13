<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

/**
 * Susunan anggaran satu tahun untuk satu cakupan: seluruh usaha (`idOutlet` null) atau satu outlet. `idOutletBoleh`
 * null = pelaku boleh mengakses semua outlet; selain itu hanya outlet di daftar itu yang boleh dianggarkan.
 */
final readonly class DataAnggaran
{
    /**
     * @param  list<DataBarisAnggaran>  $baris
     * @param  list<int>|null  $idOutletBoleh
     */
    public function __construct(
        public int $tahun,
        public ?int $idOutlet,
        public array $baris,
        public ?array $idOutletBoleh,
    ) {}
}
