<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Pemberitahuan ke Owner: refund tagihan langganan disetujui dan layanan dari tagihan itu disesuaikan (P-08, BR-P08.2).
 */
final class RefundLanggananDisetujui extends SurelDasar
{
    public function __construct(
        public readonly string $nama,
        public readonly string $nomorTagihan,
        public readonly string $jumlah,
        public readonly ?string $catatan,
    ) {
        $this->subject("Refund tagihan {$nomorTagihan} disetujui")
            ->IsiSurel('Tenant.RefundLanggananDisetujui', [
                'Nama' => $nama,
                'NomorTagihan' => $nomorTagihan,
                'Jumlah' => $jumlah,
                'Catatan' => $catatan,
                'Tautan' => rtrim((string) config('app.url'), '/').'/kelola/langganan',
            ]);
    }
}
