<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Bukti penghapusan data ke Owner (UU PDP, P-07): apa yang dihapus, dianonimkan, dan tetap disimpan beserta alasannya.
 * Tidak memuat data pribadi apa pun selain nama penerima dan nama usaha yang diambil sebelum dianonimkan.
 */
final class PenghapusanDataSelesai extends SurelDasar
{
    /**
     * @param  list<string>  $dihapus
     * @param  list<string>  $dianonimkan
     * @param  list<string>  $dipertahankan
     */
    public function __construct(
        public readonly string $nama,
        public readonly string $namaUsaha,
        public readonly string $dilaksanakanPada,
        public readonly string $kodeBukti,
        public readonly array $dihapus,
        public readonly array $dianonimkan,
        public readonly array $dipertahankan,
    ) {
        $this->subject("Bukti penghapusan data {$namaUsaha}")
            ->IsiSurel('Tenant.PenghapusanDataSelesai', [
                'Nama' => $nama,
                'NamaUsaha' => $namaUsaha,
                'DilaksanakanPada' => $dilaksanakanPada,
                'KodeBukti' => $kodeBukti,
                'Dihapus' => $dihapus,
                'Dianonimkan' => $dianonimkan,
                'Dipertahankan' => $dipertahankan,
                'EmailDukungan' => config('mail.from.address'),
            ]);
    }
}
