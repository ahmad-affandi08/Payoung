<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

/**
 * Kode halaman invoice publik `/i/{kodeInvoice}` (F-21): `{IdTenant basis-36}.{KodePublik 32 karakter acak}`. Bagian tenant
 * hanya menetapkan scope pencarian (seperti kode struk digital); rahasianya ada di 32 karakter acak yang dibuat server
 * saat draf dibuat dan tidak pernah dapat ditebak dari nomor atau Uuid invoice.
 */
final class KodeInvoicePublik
{
    public const POLA = '[0-9a-z]{1,13}\.[0-9A-Za-z]{32}';

    public static function Buat(int $idTenant, string $kodePublik): string
    {
        return base_convert((string) $idTenant, 10, 36).'.'.$kodePublik;
    }

    /**
     * @return array{IdTenant: int, KodePublik: string}|null
     */
    public static function Urai(string $kode): ?array
    {
        if (preg_match('/^'.self::POLA.'$/', $kode) !== 1) {
            return null;
        }

        [$tenant, $rahasia] = explode('.', $kode, 2);
        $idTenant = (int) base_convert($tenant, 36, 10);

        return $idTenant > 0 ? ['IdTenant' => $idTenant, 'KodePublik' => $rahasia] : null;
    }
}
