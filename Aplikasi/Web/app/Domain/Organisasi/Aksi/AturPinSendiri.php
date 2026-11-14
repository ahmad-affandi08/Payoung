<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Layanan\PenjagaPin;
use App\Domain\Organisasi\Layanan\VerifierPinOffline;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * F-02 langkah 4: anggota mengatur PIN kasir 6 digit miliknya di tenant aktif (`TenantPengguna.HashPin`). PIN
 * berlaku per usaha; disimpan sebagai hash dan tidak pernah ditampilkan lagi.
 *
 * TODO F-06: hash PIN ikut data-awal/perubahan staf ke perangkat untuk verifikasi offline.
 */
final class AturPinSendiri
{
    public function __construct(
        private readonly PenjagaPin $penjaga,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idTenant, int $idPengguna, string $pin): void
    {
        $this->penjaga->PastikanKuat($pin);

        DB::transaction(function () use ($idTenant, $idPengguna, $pin): void {
            $anggota = TenantPengguna::query()
                ->where('IdTenant', $idTenant)
                ->where('IdPengguna', $idPengguna)
                ->where('Status', StatusKeanggotaan::Aktif->value)
                ->lockForUpdate()
                ->firstOrFail();
            $sudahAda = $anggota->HashPin !== null;
            $anggota->HashPin = Hash::make($pin);
            $anggota->VerifierPinOffline = VerifierPinOffline::Buat($pin);
            $anggota->save();

            $this->audit->Catat('pengguna.pin.atur', $anggota, nilaiBaru: ['IdPengguna' => $idPengguna, 'Mengganti' => $sudahAda], idPengguna: $idPengguna);
        });
    }
}
