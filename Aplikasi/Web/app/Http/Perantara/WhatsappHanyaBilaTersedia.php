<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman dan aksi khusus WhatsApp di tenant hanya ada bila layanan WhatsApp sudah diaktifkan di konsol dan paket
 * tenant memuatnya (`KetersediaanWhatsapp`). Selain itu rutenya dianggap tidak ada (404), sama seperti menunya yang
 * disembunyikan, sehingga tautan lama atau tautan yang diketik tidak membuka fitur yang tidak tersedia.
 */
final class WhatsappHanyaBilaTersedia
{
    public function __construct(private readonly KetersediaanWhatsapp $ketersediaan) {}

    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        abort_unless($this->ketersediaan->Tersedia(), 404);

        return $berikutnya($permintaan);
    }
}
