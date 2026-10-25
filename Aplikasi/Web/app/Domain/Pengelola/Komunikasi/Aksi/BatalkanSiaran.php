<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Komunikasi\Enum\StatusSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PesanSiaran;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan siaran yang belum mulai dikirim (D-210). Siaran yang sudah mulai dikirim tidak bisa ditarik kembali
 * karena pesan yang terkirim tidak bisa dibatalkan.
 */
final class BatalkanSiaran
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, PesanSiaran $siaran): void
    {
        DB::transaction(function () use ($pelaku, $siaran): void {
            $terkini = PesanSiaran::query()->lockForUpdate()->findOrFail($siaran->Id);

            if ($terkini->Status !== StatusSiaran::Terjadwal) {
                throw new PelanggaranAturanBisnis('P-12', 'Siaran ini sudah mulai dikirim atau sudah selesai, jadi tidak bisa dibatalkan.');
            }

            $terkini->update(['Status' => StatusSiaran::Dibatalkan, 'SelesaiPada' => now()]);

            $this->audit->Catat('komunikasi.siaran.batal', $terkini, nilaiLama: ['Status' => StatusSiaran::Terjadwal->value], nilaiBaru: ['Status' => StatusSiaran::Dibatalkan->value], idPelaku: $pelaku->Id);
        });
    }
}
