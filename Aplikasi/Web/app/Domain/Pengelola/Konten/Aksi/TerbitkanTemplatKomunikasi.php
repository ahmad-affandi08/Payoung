<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\StatusBspTemplat;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;
use Illuminate\Support\Facades\DB;

/**
 * PGL-07 / P-06: menerbitkan draf templat komunikasi. Berlaku langsung untuk pesan berikutnya (versi terbit tertinggi
 * kombinasi itu); versi lama tetap tersimpan. Templat WhatsApp yang ditandai `Ditolak` oleh BSP tidak bisa diterbitkan.
 */
final class TerbitkanTemplatKomunikasi
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, TemplateKomunikasi $draf): TemplateKomunikasi
    {
        return DB::transaction(function () use ($pelaku, $draf): TemplateKomunikasi {
            $draf = TemplateKomunikasi::query()->whereKey($draf->Id)->lockForUpdate()->firstOrFail();

            if ($draf->Status !== StatusTemplateKomunikasi::Draf) {
                throw new PelanggaranAturanBisnis('BR-P06.1', 'Versi ini sudah terbit.');
            }

            if ($draf->Kanal === KanalKomunikasi::WhatsApp && $draf->StatusBsp === StatusBspTemplat::Ditolak) {
                throw new PelanggaranAturanBisnis('TemplatDitolakBsp', 'Templat ini ditolak oleh penyedia WhatsApp. Perbaiki isinya dan ajukan ulang sebelum menerbitkan.');
            }

            $draf->update([
                'Status' => StatusTemplateKomunikasi::Terbit,
                'IdPenggunaPengelolaPenerbit' => $pelaku->Id,
                'DiterbitkanPada' => now(),
            ]);
            $this->audit->Catat('komunikasi.templat.terbitkan', $draf, nilaiBaru: [
                'KodePeristiwa' => $draf->KodePeristiwa,
                'Kanal' => $draf->Kanal->value,
                'Bahasa' => $draf->Bahasa->value,
                'Versi' => $draf->Versi,
            ]);

            return $draf;
        });
    }
}
