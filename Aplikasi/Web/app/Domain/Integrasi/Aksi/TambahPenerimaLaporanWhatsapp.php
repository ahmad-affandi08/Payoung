<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use Illuminate\Support\Facades\DB;

/** D-299 tahap 3: mendaftarkan penerima laporan otomatis WhatsApp untuk satu outlet, maksimal 6, tanpa nomor ganda. */
final class TambahPenerimaLaporanWhatsapp
{
    public function __construct(private readonly KonteksTenant $konteks, private readonly PencatatAudit $audit) {}

    public function Jalankan(int $idOutlet, string $nama, string $nomor): PenerimaLaporanWhatsapp
    {
        $nomorSah = PesanWhatsapp::AmbilNomorSah($nomor)
            ?? throw new PelanggaranAturanBisnis('NomorTidakSah', 'Nomor WhatsApp tidak sah. Contoh: 0812 3456 7890.', 'NomorTelepon');
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idOutlet, $nama, $nomorSah): PenerimaLaporanWhatsapp {
            $daftar = PenerimaLaporanWhatsapp::query()->where('IdOutlet', $idOutlet)->lockForUpdate()->get(['Id', 'NomorTelepon']);

            if ($daftar->contains('NomorTelepon', $nomorSah)) {
                throw new PelanggaranAturanBisnis('NomorSudahAda', 'Nomor ini sudah menjadi penerima laporan outlet ini.', 'NomorTelepon');
            }

            if ($daftar->count() >= PenerimaLaporanWhatsapp::MAKS_PER_OUTLET) {
                throw new PelanggaranAturanBisnis('BatasPenerima', 'Satu outlet paling banyak '.PenerimaLaporanWhatsapp::MAKS_PER_OUTLET.' penerima laporan.', 'NomorTelepon');
            }

            $baris = PenerimaLaporanWhatsapp::query()->create(['IdTenant' => $idTenant, 'IdOutlet' => $idOutlet, 'Nama' => trim($nama), 'NomorTelepon' => $nomorSah]);
            $this->audit->Catat('penerima-laporan-whatsapp.tambah', $baris, nilaiBaru: ['Nama' => $baris->Nama, 'IdOutlet' => $idOutlet]);

            return $baris;
        });
    }
}
