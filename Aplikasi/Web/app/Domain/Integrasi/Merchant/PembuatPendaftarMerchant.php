<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use InvalidArgumentException;

/**
 * Memilih adaptor `PendaftarMerchantPembayaran` dari `integrasi.PenyediaPendaftaranMerchant` (bawaan `Doku`). Menambah
 * penyedia baru: tulis adaptornya, tambahkan kodenya di `PENYEDIA` dan satu baris di `Buat()`, lalu setel konfigurasinya
 * (D-179, `Panduan/PenyediaPembayaran.md`).
 */
final class PembuatPendaftarMerchant
{
    /** @var list<string> */
    public const PENYEDIA = [AdaptorPendaftarDoku::KODE];

    public function __construct(
        private readonly KlienPartnerDoku $klienDoku,
        private readonly PenyimpanBerkasKyc $berkas,
    ) {}

    /** Kode penyedia yang sedang dipakai (`integrasi.PenyediaPendaftaranMerchant`, bawaan `Doku`). */
    public static function AmbilKodeAktif(): string
    {
        $kode = config('integrasi.PenyediaPendaftaranMerchant');

        return is_string($kode) && $kode !== '' ? $kode : AdaptorPendaftarDoku::KODE;
    }

    public function Buat(?string $kode = null): PendaftarMerchantPembayaran
    {
        $kode ??= self::AmbilKodeAktif();

        return match ($kode) {
            AdaptorPendaftarDoku::KODE => new AdaptorPendaftarDoku($this->klienDoku, $this->berkas),
            default => throw new InvalidArgumentException("Penyedia pendaftaran merchant \"{$kode}\" tidak dikenal. Pilihan: ".implode(', ', self::PENYEDIA).'.'),
        };
    }
}
