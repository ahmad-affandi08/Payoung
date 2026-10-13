<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;

/** PGL-07 / P-06: menghapus draf templat komunikasi. Versi terbit tidak bisa dihapus (BR-P06.1). */
final class HapusDrafTemplatKomunikasi
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, TemplateKomunikasi $draf): void
    {
        if ($draf->Status !== StatusTemplateKomunikasi::Draf) {
            throw new PelanggaranAturanBisnis('BR-P06.1', 'Hanya draf yang bisa dihapus. Versi terbit tetap tersimpan.');
        }

        $this->audit->Catat('komunikasi.templat.hapus-draf', $draf, nilaiLama: ['KodePeristiwa' => $draf->KodePeristiwa, 'Kanal' => $draf->Kanal->value, 'Bahasa' => $draf->Bahasa->value, 'Versi' => $draf->Versi], idPelaku: $pelaku->Id);
        $draf->delete();
    }
}
