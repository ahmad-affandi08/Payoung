<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Karyawan\Data\DataAbsensiPos;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Layanan\PenegakJadwalAbsen;
use App\Domain\Karyawan\Layanan\PenentuKaryawanPengguna;
use App\Domain\Karyawan\Layanan\PenyimpanSwafoto;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Absen masuk dari aplikasi kasir (item outbox `Absensi.Masuk`, F-18), berlaku offline. Idempoten per Uuid (sudah ada =
 * `Duplikat`). Pelaku = pengguna yang lolos PIN di perangkat dan anggota outlet perangkat; karyawannya dibuat otomatis
 * bila belum ada; karyawan nonaktif ditolak. Tanggal bisnis dari waktu masuk menurut jam tutup buku outlet. Swafoto
 * disimpan di disk privat (dibersihkan bila gagal/duplikat). Dengan aturan `WajibJadwal` (D-44) absen yang tidak sesuai
 * jadwal tetap diterima tetapi ditandai `DiluarJadwal` (bukti kehadiran fisik; perangkat bisa offline).
 */
final class CatatAbsensiMasukPos
{
    public function __construct(
        private readonly AnggotaOutlet $anggota,
        private readonly PenentuKaryawanPengguna $penentu,
        private readonly PenyimpanSwafoto $swafoto,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenegakJadwalAbsen $jadwal,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis KasirTidakDitemukan, KaryawanNonaktif, SwafotoTidakValid, UuidSudahDipakai
     */
    public function Jalankan(DataAbsensiPos $data): StatusItemSinkron
    {
        $lama = Absensi::query()->where('Uuid', $data->uuid)->first();

        // Diperiksa sebelum akses pengguna: kiriman ulang setelah akses dicabut tetap `Duplikat`, bukan macet di outbox.
        if ($lama !== null) {
            if ($lama->IdPerangkat !== $data->idPerangkat) {
                throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Uuid absensi sudah dipakai. Absen ulang di perangkat.', 'Uuid');
            }

            return StatusItemSinkron::Duplikat;
        }

        $pelaku = $this->anggota->Cari($data->idTenant, $data->uuidPengguna, $data->idOutlet);

        if ($pelaku === null) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet perangkat.', 'UuidPengguna');
        }

        $path = $data->swafoto === null ? null : $this->swafoto->Simpan($data->idTenant, $data->swafoto);

        try {
            return DB::transaction(function () use ($data, $pelaku, $path): StatusItemSinkron {
                $karyawan = $this->penentu->Tentukan($pelaku, $data->idOutlet);

                if ($karyawan->Status !== StatusKaryawan::Aktif) {
                    throw new PelanggaranAturanBisnis('KaryawanNonaktif', "{$karyawan->Nama} sudah nonaktif sebagai karyawan.", 'UuidPengguna');
                }

                // D-44: absen POS bisa dilakukan offline, jadi tidak ditolak di server; yang tidak sesuai jadwal ditandai
                // untuk ditinjau pengelola (rekap absensi + Kotak Tindakan).
                $diluar = $this->jadwal->Periksa($karyawan->Id, $data->idOutlet, $data->waktu) !== null;

                Absensi::query()->create([
                    'Uuid' => $data->uuid,
                    'IdKaryawan' => $karyawan->Id,
                    'IdOutlet' => $data->idOutlet,
                    'IdPerangkat' => $data->idPerangkat,
                    'TanggalBisnis' => $this->tanggalBisnis->HitungKalender($data->idOutlet, $data->waktu)->toDateString(),
                    'MasukPada' => $data->waktu->utc(),
                    'PathSwafotoMasuk' => $path,
                    'DiluarJadwal' => $diluar,
                ]);

                return StatusItemSinkron::Diterima;
            }, 3);
        } catch (QueryException $galat) {
            $this->swafoto->Hapus($path);

            if (str_contains($galat->getMessage(), 'UniqAbsensiUuid')) {
                throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Uuid absensi sudah dipakai. Absen ulang di perangkat.', 'Uuid');
            }

            throw $galat;
        } catch (Throwable $galat) {
            $this->swafoto->Hapus($path);

            throw $galat;
        }
    }
}
