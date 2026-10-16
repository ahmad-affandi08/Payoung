<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use Carbon\CarbonImmutable;

/**
 * Isian draf penawaran (F-21 bagian 3, D-168). `terminHari` null = ikut `Pelanggan.TerminHari` (dipakai invoice hasil
 * penawaran ini). Baris memakai `DataBarisInvoice` yang sama, jadi aturan barisnya identik dengan invoice.
 */
final readonly class DataPenawaran
{
    /**
     * @param  list<DataBarisInvoice>  $baris
     */
    public function __construct(
        public string $uuidPelanggan,
        public int $idOutlet,
        public CarbonImmutable $tanggal,
        public CarbonImmutable $berlakuSampai,
        public ?int $terminHari,
        public array $baris,
        public bool $hargaTermasukPajak = false,
        public ?string $perihal = null,
        public ?string $nomorReferensi = null,
        public ?string $catatan = null,
        public ?string $syarat = null,
    ) {}
}
