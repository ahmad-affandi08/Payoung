<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;
use Illuminate\Support\Facades\DB;

/** D-299: menghapus nomor pengirim dan memutus sesinya di penyedia (upaya terbaik, kegagalan tidak menahan penghapusan). */
final class HapusNomorPengirimWhatsapp
{
    public function __construct(
        private readonly PembuatGerbangSesiWhatsapp $pembuatGerbang,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(NomorPengirimWhatsapp $nomor): void
    {
        if ($nomor->TokenPerangkat !== null) {
            $this->pembuatGerbang->Ambil()?->Putuskan($nomor->TokenPerangkat);
        }

        DB::transaction(function () use ($nomor): void {
            $this->audit->Catat('nomor-whatsapp.hapus', $nomor, ['Label' => $nomor->Label, 'IdOutlet' => $nomor->IdOutlet], null);
            $nomor->delete();
        });
    }
}
