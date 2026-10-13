<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Enum;

/**
 * Kanal tempat ketersediaan produk ditandai (F-17 BR-17.2, PRD-11): `Semua` = habis di semua kanal outlet itu (arti
 * tanda habis sebelum v6.01), sisanya hanya satu kanal. `SelfOrder` mencakup QR meja dan kios pesan sendiri; `Kasir`
 * adalah layar Jual di aplikasi Kasir; `TokoOnline` adalah toko online publik. Kanal marketplace/ojol menunggu sinkron
 * menu dengan mitra (belum ada konsumen yang bisa dipengaruhi).
 */
enum KanalKetersediaan: string
{
    case Semua = 'Semua';
    case Kasir = 'Kasir';
    case TokoOnline = 'TokoOnline';
    case SelfOrder = 'SelfOrder';

    /**
     * Kanal tunggal (tanpa `Semua`) menurut urutan tampil.
     *
     * @return list<self>
     */
    public static function Tunggal(): array
    {
        return [self::Kasir, self::TokoOnline, self::SelfOrder];
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Semua => 'Semua kanal',
            self::Kasir => 'Kasir',
            self::TokoOnline => 'Toko online',
            self::SelfOrder => 'Self-order & kios',
        };
    }
}
