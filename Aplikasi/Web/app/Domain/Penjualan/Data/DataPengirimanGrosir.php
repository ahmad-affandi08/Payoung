<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;

/**
 * Perubahan status pengiriman satu surat jalan (F-12 §9.7). Yang dipakai tergantung `tujuan`:
 * - `DalamPerjalanan`: `uuidArmada` (opsional) + `namaPengemudi` (bawaan dari armada);
 * - `Diterima`: `namaPenerima` wajib, `waktuLokal` (`Y-m-d H:i` di zona waktu outlet surat jalan; bawaan: sekarang), `catatan`;
 * - `Gagal`: `catatan` = alasan wajib;
 * - `Disiapkan`: batal berangkat, tanpa isian.
 */
final readonly class DataPengirimanGrosir
{
    public function __construct(
        public string $uuidSuratJalan,
        public StatusPengirimanGrosir $tujuan,
        public ?string $uuidArmada = null,
        public ?string $namaPengemudi = null,
        public ?string $namaPenerima = null,
        public ?string $catatan = null,
        public ?string $waktuLokal = null,
    ) {}
}
