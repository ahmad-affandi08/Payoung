<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * Gift card tenant aktif (CRM-06, D-153): berlaku bersama fitur `pelanggan.deposit` (kartu bernilai simpanan, Pro ke
 * atas). Batas nilai per kartu dari `config/pelanggan.php` (`GiftCard.MinimalNilai`/`MaksimalNilai`, Rupiah bulat).
 */
final class PengaturanGiftCardTenant
{
    public const KUNCI_FITUR = 'pelanggan.deposit';

    public const MASA_BERLAKU_MAKSIMAL_HARI = 1825;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    public function CekBerlaku(): bool
    {
        return $this->fitur->CekAktif($this->konteks->Wajib(), self::KUNCI_FITUR);
    }

    public static function AmbilMinimalNilai(): Uang
    {
        return Uang::Dari((string) config('pelanggan.GiftCard.MinimalNilai', '10000'));
    }

    public static function AmbilMaksimalNilai(): Uang
    {
        return Uang::Dari((string) config('pelanggan.GiftCard.MaksimalNilai', '5000000'));
    }

    public static function AmbilMasaBerlakuBawaanHari(): int
    {
        return (int) config('pelanggan.GiftCard.MasaBerlakuBawaanHari', 365);
    }
}
