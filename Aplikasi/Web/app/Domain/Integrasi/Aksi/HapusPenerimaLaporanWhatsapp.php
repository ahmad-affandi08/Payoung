<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use Illuminate\Support\Facades\DB;

/** D-299 tahap 3: menghapus penerima laporan otomatis WhatsApp. */
final class HapusPenerimaLaporanWhatsapp
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(PenerimaLaporanWhatsapp $penerima): void
    {
        DB::transaction(function () use ($penerima): void {
            $this->audit->Catat('penerima-laporan-whatsapp.hapus', $penerima, ['Nama' => $penerima->Nama, 'IdOutlet' => $penerima->IdOutlet], null);
            $penerima->delete();
        });
    }
}
