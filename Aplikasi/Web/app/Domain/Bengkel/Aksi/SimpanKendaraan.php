<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Aksi;

use App\Domain\Bengkel\Data\DataKendaraan;
use App\Domain\Bengkel\Enum\JenisUnitServis;
use App\Domain\Bengkel\Layanan\NomorPolisi;
use App\Domain\Bengkel\Layanan\NomorSeriUnit;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tambah atau ubah kendaraan pelanggan (§9.10). Nomor polisi dinormalisasi (`NomorPolisi`) dan unik per tenant di
 * antara kendaraan aktif: pemeriksaan di sini memberi pesan yang jelas, indeks unik `KunciNomorPolisiAktif` menjaga
 * balapan dua penyimpanan bersamaan. Mengarsipkan (`Aktif` = false) melepas nomornya untuk kendaraan baru, tanpa
 * menghapus riwayat servisnya.
 */
final class SimpanKendaraan
{
    public function __construct(
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataKendaraan $data, int $idPengguna, ?string $uuidKendaraan = null): Kendaraan
    {
        $perangkat = $data->jenis === JenisUnitServis::Perangkat;
        $nomor = $perangkat ? NomorSeriUnit::Normalisasi($data->nomorPolisi) : NomorPolisi::Normalisasi($data->nomorPolisi);

        if ($nomor === false || (! $perangkat && $nomor === null)) {
            throw new PelanggaranAturanBisnis(
                $perangkat ? 'NomorSeriTidakValid' : 'NomorPolisiTidakValid',
                $perangkat ? 'IMEI / nomor seri tidak valid. Gunakan huruf, angka, spasi, titik, atau tanda hubung (3-40 karakter), atau kosongkan.' : 'Nomor polisi tidak valid. Contoh: AD 1234 XY.',
                'NomorPolisi',
            );
        }

        $merek = trim($data->merek);

        if ($merek === '') {
            throw new PelanggaranAturanBisnis('MerekWajib', $perangkat ? 'Merek perangkat wajib diisi.' : 'Merek kendaraan wajib diisi.', 'Merek');
        }

        $idPelanggan = $this->pelanggan->CariId($data->uuidPelanggan);

        if ($idPelanggan === null || ! $this->pelanggan->CekAktif($idPelanggan)) {
            throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan atau sudah diarsipkan.', 'UuidPelanggan');
        }

        if ($data->tahun !== null && ($data->tahun < 1950 || $data->tahun > now()->year + 1)) {
            throw new PelanggaranAturanBisnis('TahunTidakValid', 'Tahun unit tidak masuk akal.', 'Tahun');
        }

        try {
            return DB::transaction(function () use ($data, $idPengguna, $uuidKendaraan, $nomor, $merek, $idPelanggan, $perangkat): Kendaraan {
                $kendaraan = $uuidKendaraan === null
                    ? new Kendaraan
                    : (Kendaraan::query()->where('Uuid', $uuidKendaraan)->lockForUpdate()->first()
                        ?? throw new PelanggaranAturanBisnis('KendaraanTidakDikenal', 'Kendaraan tidak ditemukan.', 'Umum', 404));
                $lama = $kendaraan->exists ? $kendaraan->only(['Jenis', 'NomorPolisi', 'Merek', 'Tipe', 'Tahun', 'IdPelanggan', 'KmTerakhir', 'Aktif']) : null;

                if ($nomor !== null && $data->aktif && Kendaraan::query()->where('NomorPolisi', $nomor)->where('Aktif', true)->when($kendaraan->exists, fn ($k) => $k->whereKeyNot($kendaraan->Id))->exists()) {
                    throw new PelanggaranAturanBisnis('NomorPolisiSudahAda', ($perangkat ? 'Perangkat' : 'Kendaraan')." {$nomor} sudah terdaftar. Cari unit itu, atau arsipkan dulu bila sudah berganti pemilik.", 'NomorPolisi');
                }

                if ($kendaraan->exists && $kendaraan->Jenis !== $data->jenis) {
                    throw new PelanggaranAturanBisnis('JenisUnitTidakBisaBerubah', 'Jenis unit tidak bisa diubah. Buat unit baru dengan jenis yang benar.', 'Jenis');
                }

                $kendaraan->fill([
                    'IdPelanggan' => $idPelanggan,
                    'Jenis' => $data->jenis,
                    'NomorPolisi' => $nomor,
                    'Merek' => mb_substr($merek, 0, 50),
                    'Tipe' => self::Teks($data->tipe, 80),
                    'Tahun' => $data->tahun,
                    'Warna' => self::Teks($data->warna, 30),
                    'NomorRangka' => $perangkat ? null : self::Teks($data->nomorRangka === null ? null : mb_strtoupper($data->nomorRangka), 40),
                    'NomorMesin' => $perangkat ? null : self::Teks($data->nomorMesin === null ? null : mb_strtoupper($data->nomorMesin), 40),
                    'KmTerakhir' => $perangkat ? null : $data->kmTerakhir,
                    'Catatan' => self::Teks($data->catatan, 255),
                    'Aktif' => $data->aktif,
                ]);

                if (! $kendaraan->exists) {
                    $kendaraan->DibuatOleh = $idPengguna;
                }

                $kendaraan->save();
                $this->audit->Catat($lama === null ? 'bengkel.kendaraan-buat' : 'bengkel.kendaraan-ubah', $kendaraan, $lama, $kendaraan->only(['Jenis', 'NomorPolisi', 'Merek', 'Tipe', 'Tahun', 'IdPelanggan', 'KmTerakhir', 'Aktif']), idPengguna: $idPengguna);

                return $kendaraan;
            });
        } catch (QueryException $galat) {
            if (($galat->errorInfo[1] ?? null) === 1062) {
                throw new PelanggaranAturanBisnis('NomorPolisiSudahAda', ($perangkat ? 'Perangkat' : 'Kendaraan')." {$nomor} sudah terdaftar.", 'NomorPolisi');
            }

            throw $galat;
        }
    }

    private static function Teks(?string $nilai, int $maks): ?string
    {
        $teks = trim((string) $nilai);

        return $teks === '' ? null : mb_substr($teks, 0, $maks);
    }
}
