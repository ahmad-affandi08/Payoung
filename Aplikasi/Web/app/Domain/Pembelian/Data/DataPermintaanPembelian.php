<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Data;

use Carbon\CarbonImmutable;

/**
 * Masukan `SimpanPermintaanPembelian` (F-04 PR). `idGudang` sudah diperiksa batas outlet pelaku oleh kontroler;
 * `uuidPemasok` opsional (usulan). Harga baris = perkiraan, diskon diabaikan.
 */
final readonly class DataPermintaanPembelian
{
    /**
     * @param  list<DataBarisPesananPembelian>  $baris
     */
    public function __construct(
        public int $idGudang,
        public ?string $uuidPemasok,
        public CarbonImmutable $tanggal,
        public ?CarbonImmutable $dibutuhkanPada,
        public ?string $catatan,
        public array $baris,
        public int $idPengguna,
    ) {}
}
