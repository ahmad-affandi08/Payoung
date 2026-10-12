<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use Illuminate\Support\Facades\DB;

/** Owner membatalkan permintaan penghapusan data selama belum dilaksanakan (UU PDP, P-07: "masa tunggu bisa dibatalkan"). */
final class BatalkanPenghapusanData
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idPengguna): PermintaanPenghapusanData
    {
        $idTenant = $this->konteks->Wajib();

        if (! $this->pemilik->CekPemilik($idPengguna, $idTenant)) {
            throw new PelanggaranAturanBisnis('HanyaPemilik', 'Hanya Pemilik usaha yang bisa membatalkan permintaan penghapusan data.');
        }

        return DB::transaction(function () use ($idTenant, $idPengguna): PermintaanPenghapusanData {
            $permintaan = PermintaanPenghapusanData::query()
                ->where('Status', StatusPermintaanPenghapusanData::Menunggu->value)
                ->lockForUpdate()
                ->first()
                ?? throw new PelanggaranAturanBisnis('PermintaanTidakAda', 'Tidak ada permintaan penghapusan data yang menunggu.');

            $permintaan->update(['Status' => StatusPermintaanPenghapusanData::Dibatalkan, 'DibatalkanPada' => now()]);
            $this->audit->Catat('data.penghapusan-batal', $permintaan, idTenant: $idTenant, idPengguna: $idPengguna);

            return $permintaan;
        });
    }
}
