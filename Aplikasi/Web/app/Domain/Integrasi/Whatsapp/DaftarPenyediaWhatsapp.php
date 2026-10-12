<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Integrasi\Enum\PenyediaWhatsapp;

/**
 * Penyedia WhatsApp yang boleh dipasang tenant di nomornya sendiri (D-108) dan bidang isiannya, diambil dari
 * katalog `PenyediaWhatsapp` yang sama dengan konsol. Templat pengingat tagihan langganan tidak ikut karena itu pesan
 * Payoung kepada pemilik, bukan pesan tenant.
 */
final class DaftarPenyediaWhatsapp
{
    private const BIDANG_PLATFORM = ['NamaTemplatPengingatTagihan'];

    /**
     * @return list<PenyediaWhatsapp>
     */
    public static function Ambil(): array
    {
        return PenyediaWhatsapp::cases();
    }

    /**
     * @return list<array{Kunci: string, Label: string, Jenis: string, Wajib: bool, Opsi?: list<string>, Bawaan?: string|int, Keterangan?: string}>
     */
    public static function AmbilBidangPengaturan(PenyediaWhatsapp $penyedia): array
    {
        return array_values(array_filter($penyedia->AmbilBidangPengaturan(), fn (array $b): bool => ! in_array($b['Kunci'], self::BIDANG_PLATFORM, true)));
    }

    public static function CekDikenal(PenyediaWhatsapp $penyedia): bool
    {
        return in_array($penyedia, self::Ambil(), true);
    }
}
