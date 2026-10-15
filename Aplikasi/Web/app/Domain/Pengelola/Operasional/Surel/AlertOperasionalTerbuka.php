<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Alert BR-P11.1 / backup terlambat (P-11). Dikirim sekali per insiden. `selesaiPada` terisi bila insiden sudah pulih
 * sebelum email ini sempat terkirim (pengiriman pertama gagal): isinya menyebut insiden sudah selesai, bukan terbuka.
 */
final class AlertOperasionalTerbuka extends SurelDasar
{
    public function __construct(
        public readonly string $label,
        public readonly string $tingkat,
        public readonly string $pesan,
        public readonly string $mulaiPada,
        public readonly string $tautan,
        public readonly ?string $selesaiPada = null,
    ) {
        $this->subject('['.mb_strtoupper($tingkat)."] {$label} (".config('app.name').')')
            ->IsiSurel('Pengelola.AlertOperasional', [
                'Label' => $label, 'Tingkat' => $tingkat, 'Pesan' => $pesan, 'MulaiPada' => $mulaiPada, 'Tautan' => $tautan,
                'SelesaiPada' => $selesaiPada,
            ]);
    }
}
