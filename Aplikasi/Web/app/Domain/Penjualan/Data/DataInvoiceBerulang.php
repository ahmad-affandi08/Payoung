<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use Carbon\CarbonImmutable;

/**
 * Isian templat invoice berulang (F-21). `isi` adalah isian invoice biasa (pelanggan, outlet, baris, PPh, catatan); `tanggal`
 * di dalamnya hanya pengisi, karena tanggal tiap invoice mengikuti siklusnya. `batasKali` dan `tanggalBerakhir` boleh
 * sama-sama diisi: yang tercapai lebih dulu menghentikan jadwal.
 */
final readonly class DataInvoiceBerulang
{
    public function __construct(
        public string $nama,
        public FrekuensiInvoiceBerulang $frekuensi,
        public int $interval,
        public CarbonImmutable $tanggalMulai,
        public ?CarbonImmutable $tanggalBerakhir,
        public ?int $batasKali,
        public ModeInvoiceBerulang $mode,
        public DataInvoice $isi,
    ) {}
}
