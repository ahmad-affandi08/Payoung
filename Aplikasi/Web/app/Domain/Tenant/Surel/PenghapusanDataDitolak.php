<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/** Pemberitahuan ke Owner bahwa permintaan penghapusan data ditolak Super Admin, lengkap dengan alasannya. */
final class PenghapusanDataDitolak extends SurelDasar
{
    public function __construct(public readonly string $nama, public readonly string $namaUsaha, public readonly string $alasan)
    {
        $this->subject("Permintaan penghapusan data {$namaUsaha} belum bisa diproses")
            ->IsiSurel('Tenant.PenghapusanDataDitolak', [
                'Nama' => $nama,
                'NamaUsaha' => $namaUsaha,
                'Alasan' => $alasan,
                'EmailDukungan' => config('mail.from.address'),
            ]);
    }
}
