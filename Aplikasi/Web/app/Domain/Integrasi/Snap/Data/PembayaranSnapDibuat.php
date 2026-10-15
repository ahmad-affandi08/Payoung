<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap\Data;

use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use Carbon\CarbonImmutable;

/**
 * Hasil membuat satu pembayaran SNAP di DOKU. `rincian` disimpan di `PembayaranLangganan.RincianBayar` supaya halaman
 * bayar bisa ditampilkan ulang dan status bisa ditanyakan tanpa membuat transaksi baru.
 */
final class PembayaranSnapDibuat
{
    /**
     * @param  array<string, string>  $rincian  kunci: `IsiQr`, `NomorVa`, `NamaBank`, `UrlAlih`, `PartnerServiceId`, `CustomerNo`, `ReferensiGerbang`
     */
    public function __construct(
        public readonly MetodeBayarSnap $metode,
        public readonly ?string $kanal,
        public readonly CarbonImmutable $kedaluwarsaPada,
        public readonly array $rincian,
    ) {}
}
