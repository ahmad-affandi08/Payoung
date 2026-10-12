<?php

declare(strict_types=1);

namespace App\Domain\Pajak\Peristiwa;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tarif pajak master terbit (P-02 langkah 4–5). Belum ada pendengarnya: POS mendapat tarif baru lewat `data-awal`
 * (tarif dibaca bertanggal berlaku), sedangkan pemberitahuan ke tenant terdampak belum dibuat dan menunggu keputusan
 * saluran (D-33: WhatsApp hanya untuk pesan ke pelanggan tenant; lihat `Panduan/DaftarKekurangan.md` E10).
 */
final readonly class TarifPajakTerbit
{
    use Dispatchable;

    public function __construct(public int $idTarifPajak) {}
}
