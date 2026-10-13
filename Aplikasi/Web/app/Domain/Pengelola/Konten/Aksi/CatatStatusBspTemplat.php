<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\StatusBspTemplat;
use App\Domain\Tenant\Model\TemplateKomunikasi;

/**
 * PGL-07 / P-06: mencatat status persetujuan templat WhatsApp oleh BSP/Meta (`MenungguPersetujuan`, `Disetujui`,
 * `Ditolak`) beserta catatannya. Ini metadata persetujuan penyedia, jadi boleh dicatat juga pada versi yang sudah
 * terbit; isi pesan tidak berubah. Hanya untuk kanal WhatsApp.
 */
final class CatatStatusBspTemplat
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, TemplateKomunikasi $templat, ?StatusBspTemplat $status, ?string $catatan): TemplateKomunikasi
    {
        if ($templat->Kanal !== KanalKomunikasi::WhatsApp) {
            throw new PelanggaranAturanBisnis('BukanWhatsApp', 'Status BSP hanya untuk templat WhatsApp.', 'StatusBsp');
        }

        $catatan = $catatan === null ? null : trim($catatan);
        $catatan = $catatan === '' ? null : $catatan;
        $lama = ['StatusBsp' => $templat->StatusBsp?->value, 'CatatanBsp' => $templat->CatatanBsp];
        $templat->StatusBsp = $status;
        $templat->CatatanBsp = $catatan;

        if ($templat->isDirty(['StatusBsp', 'CatatanBsp'])) {
            $templat->save();
            $this->audit->Catat('komunikasi.templat.status-bsp', $templat, nilaiLama: $lama, nilaiBaru: ['StatusBsp' => $status?->value, 'CatatanBsp' => $catatan]);
        }

        return $templat;
    }
}
