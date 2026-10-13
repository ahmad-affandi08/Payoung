<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Pemberitahuan eskalasi tiket (P-09 L1 → L2 → Teknis) ke tim tujuan. Tidak memuat isi pesan tenant.
 */
final class TiketDukunganDieskalasi extends SurelDasar
{
    public function __construct(
        public readonly string $nomor,
        public readonly string $judul,
        public readonly string $namaTenant,
        public readonly string $tingkat,
        public readonly string $alasan,
        public readonly string $batasSla,
        public readonly string $tautan,
    ) {
        $this->subject("[Eskalasi {$tingkat}] Tiket {$nomor}: {$judul}")
            ->IsiSurel('Pengelola.TiketDukunganDieskalasi', [
                'Nomor' => $nomor, 'Judul' => $judul, 'NamaTenant' => $namaTenant, 'Tingkat' => $tingkat,
                'Alasan' => $alasan, 'BatasSla' => $batasSla, 'Tautan' => $tautan,
            ]);
    }
}
