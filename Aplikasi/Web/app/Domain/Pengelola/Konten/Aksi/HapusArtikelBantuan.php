<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Situs\Model\ArtikelBantuan;

/**
 * PLT-12: menghapus artikel bantuan. Artikel terbit harus ditarik ke draf dulu agar tautan bantuan yang dibagikan tidak
 * mati tanpa disadari.
 */
final class HapusArtikelBantuan
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, ArtikelBantuan $artikel): void
    {
        if ($artikel->CekTerbit()) {
            throw new PelanggaranAturanBisnis('ArtikelMasihTerbit', 'Tarik artikel ke draf dulu sebelum menghapusnya.');
        }

        $this->audit->Catat('situs.bantuan.hapus', $artikel, nilaiLama: ['Slug' => $artikel->Slug, 'Judul' => $artikel->Judul], idPelaku: $pelaku->Id);
        $artikel->delete();
    }
}
