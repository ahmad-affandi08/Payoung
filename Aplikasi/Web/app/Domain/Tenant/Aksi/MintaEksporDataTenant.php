<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use App\Domain\Tenant\Tugas\EksporDataTenantTugas;
use Illuminate\Support\Facades\DB;

/**
 * Pemilik meminta ekspor data lengkap usaha (audit E5, UU PDP). Satu ekspor berjalan pada satu waktu, dan paling banyak
 * satu permintaan berhasil per `tenant.JamJedaEkspor` jam (ekspor berat; berkas yang masih berlaku cukup diunduh lagi).
 * Ekspor yang gagal tidak menahan permintaan berikutnya. Penyusunan berjalan di antrean.
 */
final class MintaEksporDataTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idPengguna): EksporDataTenant
    {
        $idTenant = $this->konteks->Wajib();

        $ekspor = DB::transaction(function () use ($idTenant, $idPengguna): EksporDataTenant {
            // Serial per tenant: baris tenant dikunci supaya dua permintaan bersamaan tidak sama-sama lolos.
            DB::table('Tenant')->where('Id', $idTenant)->lockForUpdate()->first();

            $aktif = EksporDataTenant::query()->whereIn('Status', [StatusEksporDataTenant::Antre->value, StatusEksporDataTenant::Berjalan->value])->exists();

            if ($aktif) {
                throw new PelanggaranAturanBisnis('EksporSedangBerjalan', 'Ekspor data Anda sedang disiapkan. Tunggu sampai selesai, lalu unduh.');
            }

            $jeda = (int) config('tenant.JamJedaEkspor');
            $terakhir = EksporDataTenant::query()
                ->whereIn('Status', [StatusEksporDataTenant::Siap->value, StatusEksporDataTenant::Kedaluwarsa->value])
                ->where('DibuatPada', '>', now()->subHours($jeda))
                ->orderByDesc('Id')
                ->first();

            if ($terakhir !== null) {
                throw new PelanggaranAturanBisnis('EksporTerlaluSering', "Ekspor data hanya bisa diminta sekali per {$jeda} jam. Unduh ekspor yang sudah siap, atau coba lagi nanti.");
            }

            $baru = EksporDataTenant::query()->create([
                'IdTenant' => $idTenant,
                'Status' => StatusEksporDataTenant::Antre,
                'IdPenggunaPeminta' => $idPengguna,
            ]);
            $this->audit->Catat('data.ekspor-minta', $baru, idTenant: $idTenant, idPengguna: $idPengguna);

            return $baru;
        });

        EksporDataTenantTugas::dispatch($idTenant, $ekspor->Id)->afterCommit();

        return $ekspor;
    }
}
