<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;

/**
 * Jawaban penyedia atas pendaftaran merchant, sudah dipetakan ke bahasa Payoung (D-179). Adaptor yang menerjemahkan
 * status penyedia ke `StatusPendaftaranMerchant`; kode lain tidak perlu tahu kosakata penyedia.
 */
final readonly class HasilPendaftaranMerchant
{
    /**
     * @param  string|null  $statusPenyedia  status mentah penyedia, ditampilkan apa adanya di konsol (null bila kosong)
     */
    public function __construct(
        public StatusPendaftaranMerchant $status,
        public ?string $idBisnis,
        public ?string $idBrand,
        public ?string $kunciBersama,
        public ?string $statusPenyedia = null,
        public ?string $alasanPenolakan = null,
    ) {}
}
