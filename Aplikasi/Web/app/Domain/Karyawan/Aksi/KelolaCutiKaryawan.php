<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\JenisCuti;
use App\Domain\Karyawan\Enum\StatusCuti;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\CutiKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F-18 cuti/izin karyawan (EMP-03), dicatat pengelola (`karyawan.kelola`):
 * - **Catat**: karyawan aktif, rentang tanggal maksimal `HARI_MAKSIMAL` hari (boleh tanggal lampau untuk mencatat yang
 *   terlewat), tidak boleh bertabrakan dengan cuti berlaku lain milik karyawan yang sama. Audit `cuti-karyawan.catat`.
 * - **Batalkan**: alasan wajib; baris tetap tersimpan dengan status `Dibatalkan`. Audit `cuti-karyawan.batal`.
 * Efek: cuti berbayar tidak dihitung "tidak masuk" di rekap gaji; semua cuti berlaku menahan pengingat shift dan
 * peringatan belum masuk (lihat `RekapKehadiranPeriode`, `KirimNotifikasiKehadiran`). Absensi yang tetap tercatat pada
 * hari cuti dinilai seperti biasa.
 */
final class KelolaCutiKaryawan
{
    public const HARI_MAKSIMAL = 90;

    public const PANJANG_ALASAN_BATAL_MINIMAL = 5;

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis KaryawanTidakValid, RentangTidakValid, CutiBertabrakan
     */
    public function Catat(string $uuidKaryawan, JenisCuti $jenis, CarbonImmutable $mulai, CarbonImmutable $selesai, bool $berbayar, ?string $alasan, int $idPengguna): CutiKaryawan
    {
        $karyawan = Karyawan::query()->where('Uuid', $uuidKaryawan)->first();

        if ($karyawan === null || $karyawan->Status !== StatusKaryawan::Aktif) {
            throw new PelanggaranAturanBisnis('KaryawanTidakValid', 'Pilih karyawan yang aktif.', 'Karyawan');
        }

        $mulai = $mulai->startOfDay();
        $selesai = $selesai->startOfDay();

        if ($selesai->lessThan($mulai)) {
            throw new PelanggaranAturanBisnis('RentangTidakValid', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 'TanggalSelesai');
        }

        if ($mulai->diffInDays($selesai) + 1 > self::HARI_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('RentangTidakValid', 'Satu catatan paling lama '.self::HARI_MAKSIMAL.' hari. Pecah menjadi beberapa catatan.', 'TanggalSelesai');
        }

        return DB::transaction(function () use ($karyawan, $jenis, $mulai, $selesai, $berbayar, $alasan, $idPengguna): CutiKaryawan {
            // Kunci baris karyawan supaya dua pencatatan serentak untuk orang yang sama tidak sama-sama lolos cek tabrakan.
            Karyawan::query()->whereKey($karyawan->Id)->lockForUpdate()->first();

            $bentrok = CutiKaryawan::query()
                ->where('IdKaryawan', $karyawan->Id)
                ->where('Status', StatusCuti::Aktif->value)
                ->where('TanggalMulai', '<=', $selesai->toDateString())
                ->where('TanggalSelesai', '>=', $mulai->toDateString())
                ->first();

            if ($bentrok !== null) {
                throw new PelanggaranAturanBisnis(
                    'CutiBertabrakan',
                    "{$karyawan->Nama} sudah punya {$bentrok->Jenis->AmbilLabel()} pada {$bentrok->TanggalMulai->toDateString()} s.d. {$bentrok->TanggalSelesai->toDateString()}. Batalkan catatan itu dulu atau ubah tanggalnya.",
                    'TanggalMulai',
                );
            }

            $cuti = CutiKaryawan::query()->create([
                'IdKaryawan' => $karyawan->Id,
                'Jenis' => $jenis,
                'TanggalMulai' => $mulai->toDateString(),
                'TanggalSelesai' => $selesai->toDateString(),
                'Berbayar' => $berbayar,
                'Alasan' => $alasan,
                'Status' => StatusCuti::Aktif,
                'DibuatOleh' => $idPengguna,
            ]);
            $this->audit->Catat('cuti-karyawan.catat', $cuti, nilaiBaru: [
                'Karyawan' => $karyawan->Nama,
                'Jenis' => $jenis->value,
                'TanggalMulai' => $mulai->toDateString(),
                'TanggalSelesai' => $selesai->toDateString(),
                'Berbayar' => $berbayar,
            ], idPengguna: $idPengguna);

            return $cuti;
        });
    }

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalTerlaluPendek, CutiSudahDibatalkan
     */
    public function Batalkan(CutiKaryawan $cuti, string $alasan, int $idPengguna): CutiKaryawan
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_BATAL_MINIMAL) {
            throw new PelanggaranAturanBisnis('AlasanBatalTerlaluPendek', 'Tulis alasan pembatalan, minimal '.self::PANJANG_ALASAN_BATAL_MINIMAL.' karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($cuti, $alasan, $idPengguna): CutiKaryawan {
            $kini = CutiKaryawan::query()->whereKey($cuti->Id)->lockForUpdate()->firstOrFail();

            if ($kini->Status === StatusCuti::Dibatalkan) {
                throw new PelanggaranAturanBisnis('CutiSudahDibatalkan', 'Catatan ini sudah dibatalkan.', 'Umum');
            }

            $kini->forceFill([
                'Status' => StatusCuti::Dibatalkan,
                'DibatalkanPada' => now(),
                'AlasanBatal' => mb_substr($alasan, 0, 500),
            ])->save();
            $this->audit->Catat('cuti-karyawan.batal', $kini, nilaiLama: ['Status' => StatusCuti::Aktif->value], nilaiBaru: ['Status' => StatusCuti::Dibatalkan->value], idPengguna: $idPengguna);

            return $kini;
        });
    }
}
