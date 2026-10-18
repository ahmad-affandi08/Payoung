<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Billing;

use App\Domain\Integrasi\Snap\GerbangBillingSnap;
use InvalidArgumentException;

/**
 * Memilih adaptor `PenyediaTagihanPlatform` untuk tagihan langganan dari `integrasi.PenyediaTagihan` (bawaan `Doku`).
 * Menambah penyedia baru: tulis adaptornya, tambahkan kodenya di `PENYEDIA` dan satu baris di `Buat()`, lalu setel
 * `integrasi.PenyediaTagihan`. Kode lain tidak berubah (D-179, `Panduan/PenyediaPembayaran.md`).
 */
final class PembuatPenyediaTagihan
{
    /** @var list<string> */
    public const PENYEDIA = [AdaptorTagihanDoku::KODE];

    public function __construct(
        private readonly GerbangBillingPlatform $checkoutDoku,
        private readonly GerbangBillingSnap $snapDoku,
    ) {}

    /** Kode penyedia yang sedang dipakai (`integrasi.PenyediaTagihan`, bawaan `Doku`). */
    public static function AmbilKodeAktif(): string
    {
        $kode = config('integrasi.PenyediaTagihan');

        return is_string($kode) && $kode !== '' ? $kode : AdaptorTagihanDoku::KODE;
    }

    public function Buat(?string $kode = null): PenyediaTagihanPlatform
    {
        $kode ??= self::AmbilKodeAktif();

        return match ($kode) {
            AdaptorTagihanDoku::KODE => new AdaptorTagihanDoku($this->checkoutDoku, $this->snapDoku),
            default => throw new InvalidArgumentException("Penyedia tagihan \"{$kode}\" tidak dikenal. Pilihan: ".implode(', ', self::PENYEDIA).'.'),
        };
    }
}
