<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat permintaan ekspor laporan besar di Pusat Unduhan (D-124). Satu pengguna paling banyak
 * `tenant.MaksUnduhanAktif` berkas yang sedang disiapkan sekaligus; yang macet lebih lama dari
 * `tenant.MenitBatasProsesUnduhan` tidak dihitung (dibersihkan `unduhan:bersihkan`).
 */
final class AntrekanBerkasUnduhan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idPengguna, string $format, string $judul): BerkasUnduhan
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idPengguna, $format, $judul): BerkasUnduhan {
            // Serial per tenant: baris tenant dikunci supaya permintaan bersamaan tidak sama-sama lolos batas.
            DB::table('Tenant')->where('Id', $idTenant)->lockForUpdate()->first();

            $aktif = BerkasUnduhan::query()
                ->where('IdPengguna', $idPengguna)
                ->whereIn('Status', [StatusBerkasUnduhan::Menunggu->value, StatusBerkasUnduhan::Diproses->value])
                ->where('DibuatPada', '>', now()->subMinutes((int) config('tenant.MenitBatasProsesUnduhan')))
                ->count();
            $maks = (int) config('tenant.MaksUnduhanAktif');

            if ($aktif >= $maks) {
                throw new PelanggaranAturanBisnis('UnduhanTerlaluBanyak', "Masih ada {$aktif} berkas yang sedang disiapkan. Tunggu sampai selesai di Pusat Unduhan, lalu coba lagi.");
            }

            $baru = BerkasUnduhan::query()->create([
                'IdTenant' => $idTenant,
                'IdPengguna' => $idPengguna,
                'Judul' => mb_substr($judul, 0, 150),
                'Format' => $format,
                'Status' => StatusBerkasUnduhan::Menunggu,
            ]);
            $this->audit->Catat('unduhan.minta', $baru, nilaiBaru: ['Judul' => $baru->Judul, 'Format' => $format], idTenant: $idTenant, idPengguna: $idPengguna);

            return $baru;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
