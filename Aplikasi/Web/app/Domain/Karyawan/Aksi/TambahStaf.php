<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Data\DataKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Aksi\TambahPengguna;
use App\Domain\Organisasi\Data\DataAksesAnggota;
use App\Domain\Organisasi\Data\DataPenggunaBaru;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * Audit kemudahan pakai #34 (F-02, F-18): satu "Tambah staf" — akun pengguna (`TambahPengguna`, D-22) dan, bila
 * diminta, data karyawan yang tertaut ke akun itu (`SimpanKaryawan`, untuk jadwal, absensi, komisi, gaji) dalam satu
 * transaksi. Outlet karyawan = outlet pertama yang ditugaskan (kosong bila semua outlet).
 */
final class TambahStaf
{
    public function __construct(
        private readonly TambahPengguna $tambahPengguna,
        private readonly SimpanKaryawan $simpanKaryawan,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  DataKaryawan|null  $rinciKaryawan  D-101: level staf, gaji, tarif lembur & potongan dari formulir Tim; nama, akun, outlet, dan jabatan tetap ditentukan di sini.
     */
    public function Jalankan(Pengguna $pelaku, DataPenggunaBaru $data, DataAksesAnggota $akses, bool $jugaKaryawan, ?string $jabatan, ?string $uuidKaryawanTertaut = null, ?DataKaryawan $rinciKaryawan = null): Pengguna
    {
        return DB::transaction(function () use ($pelaku, $data, $akses, $jugaKaryawan, $jabatan, $uuidKaryawanTertaut, $rinciKaryawan): Pengguna {
            $karyawanAda = $uuidKaryawanTertaut === null ? null : Karyawan::query()->where('Uuid', $uuidKaryawanTertaut)->lockForUpdate()->first();

            if ($uuidKaryawanTertaut !== null && ($karyawanAda === null || $karyawanAda->IdPengguna !== null)) {
                throw new PelanggaranAturanBisnis('KaryawanTidakBisaDitautkan', 'Karyawan ini sudah punya akun atau tidak ditemukan. Muat ulang halaman.', 'UuidKaryawan');
            }

            $pengguna = $this->tambahPengguna->Jalankan($pelaku, $data, $akses);

            // D-46: akun baru untuk karyawan yang sudah ada = menautkannya, bukan membuat karyawan kedua.
            if ($karyawanAda !== null) {
                $karyawanAda->forceFill(['IdPengguna' => $pengguna->Id])->save();
                $this->audit->Catat('karyawan.ubah', $karyawanAda, ['IdPengguna' => null], ['Akun' => $pengguna->Nama, 'Sumber' => 'TambahStaf'], idPengguna: $pelaku->Id);

                return $pengguna;
            }

            if ($jugaKaryawan) {
                $this->simpanKaryawan->Jalankan(new DataKaryawan(
                    nama: $pengguna->Nama,
                    jabatan: $jabatan,
                    levelStaf: $rinciKaryawan?->levelStaf,
                    gajiPokok: $rinciKaryawan?->gajiPokok,
                    uuidPengguna: $pengguna->Uuid,
                    uuidOutlet: $akses->semuaOutlet ? null : ($akses->uuidOutlet[0] ?? null),
                    idPengguna: $pelaku->Id,
                    tarifLemburPerJam: $rinciKaryawan?->tarifLemburPerJam,
                    potonganTerlambatPerMenit: $rinciKaryawan?->potonganTerlambatPerMenit,
                    potonganTidakMasukPerHari: $rinciKaryawan?->potonganTidakMasukPerHari,
                ));
            }

            return $pengguna;
        });
    }
}
