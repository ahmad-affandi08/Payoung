<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Data\HasilPenerapanTemplate;
use App\Domain\PanduanAwal\Kueri\PembaruanTemplateSektor;

/**
 * P-03 langkah 6 / BR-P03.1: Owner memilih "Terapkan" atas tawaran pembaruan template sektor satu outlet. Penerapan
 * memakai `TerapkanTemplateSektor` (aditif & idempoten, BR-01.1), jadi tidak ada data tenant yang ditimpa atau dihapus.
 * Ditolak bila outlet tidak punya template atau tidak ada versi terbit yang lebih baru dari yang dipakai.
 */
final class TerapkanPembaruanTemplate
{
    public function __construct(
        private readonly PembaruanTemplateSektor $tawaran,
        private readonly TerapkanTemplateSektor $terapkan,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(string $uuidOutlet): HasilPenerapanTemplate
    {
        $outlet = Outlet::query()->where('Uuid', $uuidOutlet)->first()
            ?? throw new PelanggaranAturanBisnis('OutletTidakDitemukan', 'Outlet tidak ditemukan.', 'UuidOutlet', 404);
        $tawaran = $this->tawaran->BuatTawaran($outlet)
            ?? throw new PelanggaranAturanBisnis('TidakAdaPembaruan', 'Template outlet ini sudah versi terbaru.', 'UuidOutlet');

        $hasil = $this->terapkan->Jalankan($outlet, $tawaran['KodeTemplate']);
        $this->audit->Catat('template-sektor.pembaruan.terapkan', $outlet, nilaiBaru: [
            'KodeTemplate' => $tawaran['KodeTemplate'],
            'DariVersi' => $tawaran['VersiSaatIni'],
            'KeVersi' => $tawaran['VersiBaru'],
        ]);

        return $hasil;
    }
}
