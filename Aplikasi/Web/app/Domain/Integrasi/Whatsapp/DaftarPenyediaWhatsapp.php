<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;

/**
 * Penyedia WhatsApp yang boleh dipasang tenant di nomornya sendiri (D-108) dan bidang isiannya, diambil dari
 * definisi penyedia P-05 yang sama dengan konsol. Templat pengingat tagihan langganan tidak ikut karena itu pesan
 * Payoung kepada pemilik, bukan pesan tenant.
 */
final class DaftarPenyediaWhatsapp
{
    private const BIDANG_PLATFORM = ['NamaTemplatPengingatTagihan'];

    /**
     * @return list<PenyediaIntegrasi>
     */
    public static function Ambil(): array
    {
        return array_values(array_filter(PenyediaIntegrasi::cases(), fn (PenyediaIntegrasi $p): bool => $p->AmbilJenis() === JenisIntegrasi::Whatsapp));
    }

    /**
     * @return list<array{Kunci: string, Label: string, Jenis: string, Wajib: bool, Opsi?: list<string>, Bawaan?: string|int, Keterangan?: string}>
     */
    public static function AmbilBidangPengaturan(PenyediaIntegrasi $penyedia): array
    {
        return array_values(array_filter($penyedia->AmbilBidangPengaturan(), fn (array $b): bool => ! in_array($b['Kunci'], self::BIDANG_PLATFORM, true)));
    }

    public static function CekDikenal(PenyediaIntegrasi $penyedia): bool
    {
        return in_array($penyedia, self::Ambil(), true);
    }
}
