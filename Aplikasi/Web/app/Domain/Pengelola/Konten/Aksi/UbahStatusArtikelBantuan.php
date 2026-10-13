<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;

/**
 * PLT-12: menerbitkan artikel bantuan (tampil di Pusat Bantuan dan bantuan kontekstual) atau menariknya ke draf. Tanggal
 * terbit diisi saat pertama terbit dan tidak berubah saat diterbitkan ulang.
 */
final class UbahStatusArtikelBantuan
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, ArtikelBantuan $artikel, StatusArtikel $status): ArtikelBantuan
    {
        $lama = $artikel->Status;
        $artikel->Status = $status;
        $artikel->IdPenggunaPengelolaPengubah = $pelaku->Id;

        if ($status === StatusArtikel::Terbit && $artikel->DiterbitkanPada === null) {
            $artikel->DiterbitkanPada = now();
        }

        $artikel->save();
        $this->audit->Catat($status === StatusArtikel::Terbit ? 'situs.bantuan.terbitkan' : 'situs.bantuan.tarik', $artikel, ['Status' => $lama->value], ['Status' => $status->value]);

        return $artikel;
    }
}
