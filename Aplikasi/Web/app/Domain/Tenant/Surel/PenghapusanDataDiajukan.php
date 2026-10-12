<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Konfirmasi ke Owner saat permintaan penghapusan data diajukan (UU PDP, P-07): kapan paling cepat dilaksanakan dan
 * cara membatalkan. Berfungsi juga sebagai peringatan bila permintaan diajukan orang lain dari sesi yang diambil alih.
 */
final class PenghapusanDataDiajukan extends SurelDasar
{
    public function __construct(public readonly string $nama, public readonly string $namaUsaha, public readonly string $berlakuPada)
    {
        $this->subject("Permintaan penghapusan data {$namaUsaha} diterima")
            ->IsiSurel('Tenant.PenghapusanDataDiajukan', [
                'Nama' => $nama,
                'NamaUsaha' => $namaUsaha,
                'BerlakuPada' => $berlakuPada,
                'Tautan' => rtrim((string) config('app.url'), '/').'/kelola/pengaturan/data-saya',
                'EmailDukungan' => config('mail.from.address'),
            ]);
    }
}
