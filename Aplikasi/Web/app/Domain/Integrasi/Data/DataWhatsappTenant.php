<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Data;

use App\Domain\Integrasi\Enum\PenyediaWhatsapp;

final readonly class DataWhatsappTenant
{
    /**
     * @param  array<string, string|int>  $pengaturan
     * @param  array<string, string>  $kredensial  Hanya bidang yang diisi; bidang kosong = pertahankan nilai lama.
     */
    public function __construct(
        public PenyediaWhatsapp $penyedia,
        public array $pengaturan,
        public array $kredensial,
    ) {}
}
