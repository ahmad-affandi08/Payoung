<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Menghapus satu kontak prospek beserta riwayat kirimnya (D-216), misalnya atas permintaan penghapusan data (UU PDP).
 * Log audit hanya mencatat sektor dan sumbernya, bukan alamat emailnya. Menghapus kontak yang berhenti berlangganan
 * membuat alamat itu bisa masuk lagi lewat impor, jadi hapus hanya bila orangnya sendiri meminta data dihapus.
 */
final class HapusKontakProspek
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, KontakProspek $kontak): void
    {
        $this->audit->Catat('prospek.kontak.hapus', $kontak, nilaiLama: ['Sektor' => $kontak->Sektor->value, 'Sumber' => $kontak->Sumber, 'Status' => $kontak->Status->value], idPelaku: $pelaku->Id);

        $kontak->delete();
    }
}
