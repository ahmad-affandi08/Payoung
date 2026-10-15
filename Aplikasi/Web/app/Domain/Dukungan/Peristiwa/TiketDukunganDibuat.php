<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Peristiwa;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tenant membuat tiket baru (P-09). Dikirim setelah commit; penangan (pemberitahuan tim Dukungan) berjalan di queue.
 * Membawa data ringkas agar penangan tidak perlu membaca data tenant lintas tenant.
 */
final class TiketDukunganDibuat implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idTiket,
        public readonly string $uuid,
        public readonly string $nomor,
        public readonly string $judul,
        public readonly string $kategori,
        public readonly string $prioritas,
        public readonly string $batasSlaPada,
        public readonly bool $obrolan = false,
    ) {}
}
