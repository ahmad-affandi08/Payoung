<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\Tenant;
use App\Domain\Tenant\Surel\PenghapusanDataDitolak;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Super Admin menolak permintaan penghapusan data (UU PDP, P-07), misalnya verifikasi identitas gagal atau ada sengketa
 * atau perkara hukum yang mewajibkan data dipertahankan. Alasan wajib dan dikirim ke Owner supaya jelas langkah
 * berikutnya. Akun tetap berjalan.
 */
final class TolakPenghapusanDataTenant
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, PermintaanPenghapusanData $permintaan, string $alasan): PermintaanPenghapusanData
    {
        $idTenant = $permintaan->IdTenant;

        $ditolak = $this->konteks->JalankanLintasTenant(
            'Menolak permintaan penghapusan data (UU PDP)',
            fn (): PermintaanPenghapusanData => DB::transaction(function () use ($pelaku, $permintaan, $alasan, $idTenant): PermintaanPenghapusanData {
                $terkini = PermintaanPenghapusanData::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

                if ($terkini->Status !== StatusPermintaanPenghapusanData::Menunggu) {
                    throw new PelanggaranAturanBisnis('PermintaanTidakMenunggu', "Permintaan ini sudah {$terkini->Status->AmbilLabel()}.");
                }

                $terkini->update([
                    'Status' => StatusPermintaanPenghapusanData::Ditolak,
                    'IdPenggunaPengelolaPemutus' => $pelaku->Id,
                    'DiputuskanPada' => now(),
                    'CatatanPemutus' => $alasan,
                ]);
                $this->audit->Catat('tenant.penghapusan-data-tolak', $terkini, alasan: $alasan, idPelaku: $pelaku->Id, idTenant: $idTenant);

                return $terkini;
            }),
            $idTenant,
        );

        $namaUsaha = (string) Tenant::query()->whereKey($idTenant)->value('Nama');

        foreach ($this->pemilik->AmbilKontak($idTenant) as $kontak) {
            if ($kontak['Email'] === null) {
                continue;
            }

            try {
                Mail::to($kontak['Email'])->queue(new PenghapusanDataDitolak($kontak['Nama'], $namaUsaha, $alasan));
            } catch (Throwable $galat) {
                Log::warning('Email penolakan penghapusan data gagal dikirim.', ['IdTenant' => $idTenant, 'Galat' => $galat->getMessage()]);
            }
        }

        return $ditolak;
    }
}
