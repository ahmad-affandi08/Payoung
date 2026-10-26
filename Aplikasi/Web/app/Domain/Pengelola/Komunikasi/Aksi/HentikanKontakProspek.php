<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;

/**
 * Menghentikan pengiriman ke satu kontak prospek (D-216): berhenti berlangganan (dari tautan di email, atau permintaan
 * yang dicatat petugas) atau pentalan (alamat tidak aktif). Idempoten, dan yang sudah berhenti tidak berubah lagi.
 * Kontak tidak dihapus supaya alamat itu tidak masuk lagi lewat impor berikutnya.
 */
final class HentikanKontakProspek
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(KontakProspek $kontak, StatusKontakProspek $status = StatusKontakProspek::Berhenti, ?PenggunaPengelola $pelaku = null): bool
    {
        if ($kontak->Status !== StatusKontakProspek::Aktif || $status === StatusKontakProspek::Aktif) {
            return false;
        }

        $kontak->update(['Status' => $status, 'BerhentiPada' => Carbon::now()]);

        if ($pelaku !== null) {
            $this->audit->Catat('prospek.kontak.henti', $kontak, nilaiLama: ['Status' => StatusKontakProspek::Aktif->value], nilaiBaru: ['Status' => $status->value], idPelaku: $pelaku->Id);
        }

        return true;
    }
}
