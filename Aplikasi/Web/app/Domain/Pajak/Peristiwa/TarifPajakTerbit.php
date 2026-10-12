<?php

declare(strict_types=1);

namespace App\Domain\Pajak\Peristiwa;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tarif pajak master terbit (P-02 langkah 4–5). Tidak butuh pendengar: POS mendapat tarif baru lewat `data-awal`
 * (tarif dibaca bertanggal berlaku) dan tenant diberi tahu lewat Kotak Tindakan (`PenyediaTindakanPajak`, audit E10),
 * yang membaca langsung tarif terbit bertanggal berlaku, bukan lewat pesan (D-33: WhatsApp hanya untuk pelanggan tenant).
 */
final readonly class TarifPajakTerbit
{
    use Dispatchable;

    public function __construct(public int $idTarifPajak) {}
}
