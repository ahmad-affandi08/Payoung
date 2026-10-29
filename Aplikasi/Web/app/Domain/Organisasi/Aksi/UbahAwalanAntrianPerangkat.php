<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Support\Facades\DB;

/**
 * K26: atur awalan huruf nomor antrian sebuah perangkat kasir (1–2 huruf, disimpan huruf besar, kosong = nomor polos).
 * Awalan unik per outlet di antara perangkat yang belum dicabut, karena itulah gunanya: dua kasir di satu outlet tidak
 * boleh memanggil nomor yang sama. Berlaku di aplikasi kasir pada sinkron data awal berikutnya.
 */
final class UbahAwalanAntrianPerangkat
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(Perangkat $perangkat, ?string $awalan): Perangkat
    {
        $baru = $awalan === null || trim($awalan) === '' ? null : mb_strtoupper(trim($awalan));

        if ($baru !== null && preg_match('/^[A-Z]{1,2}$/', $baru) !== 1) {
            throw new PelanggaranAturanBisnis('AwalanAntrianTidakValid', 'Awalan antrian hanya 1 atau 2 huruf, misal A.', 'AwalanAntrian');
        }

        return DB::transaction(function () use ($perangkat, $baru): Perangkat {
            $perangkat = Perangkat::query()->lockForUpdate()->findOrFail($perangkat->Id);

            if ($perangkat->CekDicabut()) {
                throw new PelanggaranAturanBisnis('PerangkatDicabut', 'Perangkat yang sudah dicabut tidak bisa diubah.', 'AwalanAntrian');
            }

            if ($baru !== null) {
                $dipakai = Perangkat::query()
                    ->where('IdOutlet', $perangkat->IdOutlet)
                    ->whereKeyNot($perangkat->Id)
                    ->whereNull('DicabutPada')
                    ->where('AwalanAntrian', $baru)
                    ->first(['Kode', 'Nama']);

                if ($dipakai !== null) {
                    throw new PelanggaranAturanBisnis('AwalanAntrianSudahDipakai', "Awalan {$baru} sudah dipakai {$dipakai->Nama} ({$dipakai->Kode}) di outlet ini. Pilih huruf lain.", 'AwalanAntrian');
                }
            }

            $lama = $perangkat->AwalanAntrian;
            $perangkat->AwalanAntrian = $baru;

            if ($perangkat->isDirty('AwalanAntrian')) {
                $perangkat->save();
                $this->audit->Catat('perangkat.ubah', $perangkat, nilaiLama: ['AwalanAntrian' => $lama], nilaiBaru: ['AwalanAntrian' => $baru]);
            }

            return $perangkat;
        });
    }
}
