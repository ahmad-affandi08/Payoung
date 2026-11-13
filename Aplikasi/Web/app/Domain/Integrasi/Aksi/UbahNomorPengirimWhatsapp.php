<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use Illuminate\Support\Facades\DB;

/** D-299: mengubah label, fungsi, dan sakelar ON/OFF sebuah nomor. Menyalakan hanya bila nomor terhubung. */
final class UbahNomorPengirimWhatsapp
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(NomorPengirimWhatsapp $nomor, string $label, FungsiNomorWhatsapp $fungsi, bool $aktif): NomorPengirimWhatsapp
    {
        return DB::transaction(function () use ($nomor, $label, $fungsi, $aktif): NomorPengirimWhatsapp {
            $terkini = NomorPengirimWhatsapp::query()->lockForUpdate()->findOrFail($nomor->Id);

            if ($aktif && $terkini->Status !== StatusNomorWhatsapp::Terhubung) {
                throw new PelanggaranAturanBisnis('NomorBelumTerhubung', 'Pindai QR sampai terhubung sebelum menyalakan nomor ini.', 'Aktif', 409);
            }

            $lama = ['Label' => $terkini->Label, 'Fungsi' => $terkini->Fungsi->value, 'Aktif' => $terkini->Aktif];
            $terkini->update(['Label' => trim($label), 'Fungsi' => $fungsi, 'Aktif' => $aktif]);
            $this->audit->Catat('nomor-whatsapp.ubah', $terkini, $lama, ['Label' => $terkini->Label, 'Fungsi' => $fungsi->value, 'Aktif' => $aktif]);

            return $terkini;
        });
    }
}
