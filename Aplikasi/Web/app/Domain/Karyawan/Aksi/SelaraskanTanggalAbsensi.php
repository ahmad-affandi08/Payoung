<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Organisasi\Model\Outlet;
use Carbon\CarbonImmutable;

/**
 * D-230: absensi memakai tanggal kalender waktu outlet, bukan hari bisnis yang bergeser pada `JamTutupBuku`. Absensi
 * lama (sumber kasir dan web) yang tanggalnya terlanjur mengikuti jam tutup buku disesuaikan ke tanggal kalender
 * `MasukPada`. Absensi manual tidak disentuh (tanggalnya dipilih pengelola). Aman diulang.
 */
final class SelaraskanTanggalAbsensi
{
    public function __construct(private readonly KonteksTenant $konteks) {}

    /** @return int jumlah baris absensi yang tanggalnya diperbaiki */
    public function Jalankan(int $idTenant): int
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($idTenant);

        try {
            $zona = Outlet::query()->pluck('ZonaWaktu', 'Id')->all();
            $jumlah = 0;

            Absensi::query()
                ->where('Sumber', '!=', Absensi::SUMBER_MANUAL)
                ->orderBy('Id')
                ->chunkById(500, function ($baris) use ($zona, &$jumlah): void {
                    foreach ($baris as $absensi) {
                        $kalender = CarbonImmutable::instance($absensi->MasukPada)
                            ->setTimezone((string) ($zona[$absensi->IdOutlet] ?? 'Asia/Jakarta'))
                            ->toDateString();

                        if ($absensi->TanggalBisnis->toDateString() !== $kalender) {
                            $absensi->forceFill(['TanggalBisnis' => $kalender])->save();
                            $jumlah++;
                        }
                    }
                }, 'Id');

            return $jumlah;
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }
}
