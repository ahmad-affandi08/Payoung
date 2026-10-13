<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Outlet;

/**
 * Tenant yang terdaftar sebelum v6.13 mendapat gudang bernama "Gudang Outlet Utama" yang sama untuk semua usaha.
 * Aksi ini mengganti **hanya** nama persis itu menjadi "Gudang {nama outlet}"; nama yang sudah diubah pemilik tidak
 * disentuh. Kode gudang tidak berubah (dipakai nomor dokumen). Aman diulang.
 */
final class RapikanNamaGudangBawaan
{
    public const NAMA_LAMA = 'Gudang Outlet Utama';

    public function __construct(private readonly KonteksTenant $konteks) {}

    /** @return int jumlah gudang yang diganti namanya */
    public function Jalankan(int $idTenant): int
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($idTenant);

        try {
            $jumlah = 0;

            foreach (Gudang::query()->where('Nama', self::NAMA_LAMA)->get() as $gudang) {
                $namaOutlet = Outlet::query()->whereKey($gudang->IdOutlet)->value('Nama');

                if (! is_string($namaOutlet) || $namaOutlet === '') {
                    continue;
                }

                $gudang->forceFill(['Nama' => mb_substr("Gudang {$namaOutlet}", 0, 100)])->save();
                $jumlah++;
            }

            return $jumlah;
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }
}
