<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\StatusPermintaanKoreksi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanKoreksiAbsensi;
use Illuminate\Support\Facades\DB;

/**
 * "Minta koreksi ke manajer" (F-18, D-180): karyawan yang terus gagal absen mengirim alasannya. Hanya untuk karyawan aktif
 * yang ikut absensi; satu permintaan Menunggu per jenis (kirim ulang mengembalikan yang sudah ada, tidak menumpuk). Tidak
 * mencatat absensi apa pun: pengelola yang memeriksa lalu mencatat jamnya (`SimpanAbsensiManual`).
 */
final class AjukanKoreksiAbsensi
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(Karyawan $karyawan, string $jenis, string $alasan): PermintaanKoreksiAbsensi
    {
        $alasan = trim($alasan);

        if (! in_array($jenis, [PermintaanKoreksiAbsensi::JENIS_MASUK, PermintaanKoreksiAbsensi::JENIS_KELUAR], true)) {
            throw new PelanggaranAturanBisnis('JenisKoreksiTidakValid', 'Pilih absen masuk atau absen pulang yang bermasalah.', 'Jenis');
        }

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 200) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Tulis alasannya 5 sampai 200 huruf, misal "kamera depan rusak".', 'Alasan');
        }

        if (! $karyawan->IkutAbsensi || $karyawan->Status->value !== 'Aktif') {
            throw new PelanggaranAturanBisnis('TidakIkutAbsensi', "{$karyawan->Nama} tidak diwajibkan absen.", 'Karyawan');
        }

        return DB::transaction(function () use ($karyawan, $jenis, $alasan): PermintaanKoreksiAbsensi {
            // Dua ketukan bersamaan diserialkan lewat baris karyawan.
            Karyawan::query()->whereKey($karyawan->Id)->lockForUpdate()->first();
            $ada = PermintaanKoreksiAbsensi::query()
                ->where('IdKaryawan', $karyawan->Id)
                ->where('Jenis', $jenis)
                ->where('Status', StatusPermintaanKoreksi::Menunggu->value)
                ->first();

            if ($ada !== null) {
                return $ada;
            }

            $baru = PermintaanKoreksiAbsensi::query()->create([
                'IdKaryawan' => $karyawan->Id,
                'IdOutlet' => $karyawan->IdOutlet,
                'Jenis' => $jenis,
                'Alasan' => $alasan,
            ]);
            $this->audit->Catat('absensi.koreksi.diminta', $baru, null, ['Karyawan' => $karyawan->Nama, 'Jenis' => $jenis, 'Alasan' => $alasan], idPengguna: $karyawan->IdPengguna);

            return $baru;
        });
    }
}
