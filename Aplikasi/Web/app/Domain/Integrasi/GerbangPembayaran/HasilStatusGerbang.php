<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

/**
 * Jawaban gerbang atas pertanyaan status. [jumlah] string desimal bila penyedia melaporkannya (null = tidak dilaporkan,
 * penerap tidak membandingkan).
 */
final readonly class HasilStatusGerbang
{
    public function __construct(
        public StatusPembayaranGerbang $status,
        public ?string $jumlah = null,
    ) {}
}
