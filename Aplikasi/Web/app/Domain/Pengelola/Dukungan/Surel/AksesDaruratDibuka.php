<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Pemberitahuan ke Owner bahwa Super Admin Payoung membuka akses darurat tanpa izinnya (PGL-16, P-09 langkah 5).
 * Memuat kategori, alasan, cakupan, dan batas waktu supaya Owner tahu apa yang terjadi; tidak memuat data usaha.
 */
final class AksesDaruratDibuka extends SurelDasar
{
    public function __construct(
        public readonly string $nama,
        public readonly string $namaUsaha,
        public readonly string $kategori,
        public readonly string $alasan,
        public readonly string $cakupan,
        public readonly string $berakhirPada,
        public readonly string $tautan,
    ) {
        $this->subject("Akses darurat ke akun {$namaUsaha} dibuka")
            ->IsiSurel('Pengelola.AksesDaruratDibuka', [
                'Nama' => $nama,
                'NamaUsaha' => $namaUsaha,
                'Kategori' => $kategori,
                'Alasan' => $alasan,
                'Cakupan' => $cakupan,
                'BerakhirPada' => $berakhirPada,
                'Tautan' => $tautan,
                'EmailDukungan' => config('mail.from.address'),
            ]);
    }
}
