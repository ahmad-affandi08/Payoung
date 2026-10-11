<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Data;

use Carbon\CarbonImmutable;

/**
 * Masukan `SimpanTransferStok` (F-05b): draf transfer dari lokasi asal ke lokasi tujuan. `uuid` klien untuk
 * idempotensi buat; `versiDiubahPada` wajib saat mengubah draf (penjaga `DokumenBerubah`).
 */
final readonly class DataTransferStok
{
    /**
     * @param  list<DataBarisDokumenStok>  $baris
     */
    public function __construct(
        public ?string $uuid,
        public int $idGudangAsal,
        public int $idGudangTujuan,
        public CarbonImmutable $tanggal,
        public ?string $catatan,
        public array $baris,
        public ?string $versiDiubahPada = null,
        /** D-105: transfer ini menjawab permintaan stok (uuid); hanya dipakai saat membuat draf. */
        public ?string $uuidPermintaanStok = null,
    ) {}
}
