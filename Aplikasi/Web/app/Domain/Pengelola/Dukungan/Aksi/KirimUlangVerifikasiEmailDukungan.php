<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Organisasi\Aksi\KirimVerifikasiEmail;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\Cache;

/**
 * Alat bantu (ubah): kirim ulang email verifikasi ke anggota tenant yang emailnya belum terverifikasi (PGL-16 bagian 2).
 * Email dikirim ke alamat yang tersimpan; petugas tidak melihat atau mengubah alamatnya. Satu kiriman per pengguna dalam
 * `JEDA_MENIT` menit supaya kotak masuk pengguna tidak dibanjiri.
 */
final class KirimUlangVerifikasiEmailDukungan
{
    public const JEDA_MENIT = 10;

    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly KirimVerifikasiEmail $kirim,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, string $uuidPengguna): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::KirimUlangVerifikasiEmail, ['UuidPengguna' => $uuidPengguna], function () use ($idTenant, $uuidPengguna): HasilKerjaAlat {
            $pengguna = Pengguna::query()->where('Uuid', $uuidPengguna)->first();
            $anggota = $pengguna instanceof Pengguna
                ? TenantPengguna::query()->where('IdTenant', $idTenant)->where('IdPengguna', $pengguna->Id)->exists()
                : false;

            if (! $pengguna instanceof Pengguna || ! $anggota || $pengguna->Email === null) {
                throw new PelanggaranAturanBisnis('PenggunaTidakDitemukan', 'Pengguna tidak ditemukan di tenant ini atau tidak punya email.');
            }

            if ($pengguna->EmailDiverifikasiPada !== null) {
                $keadaan = ['EmailTerverifikasi' => true];

                return new HasilKerjaAlat(StatusHasilAlatDukungan::TanpaPerubahan, 'Email pengguna sudah terverifikasi; tidak ada yang dikirim.', $keadaan, $keadaan);
            }

            if (! Cache::add("dukungan:verifikasi-email:{$pengguna->Id}", 1, now()->addMinutes(self::JEDA_MENIT))) {
                throw new PelanggaranAturanBisnis('TerlaluSering', 'Email verifikasi baru saja dikirim. Tunggu '.self::JEDA_MENIT.' menit sebelum mengirim lagi.');
            }

            $this->kirim->Jalankan($pengguna);

            return new HasilKerjaAlat(StatusHasilAlatDukungan::Berhasil, 'Email verifikasi dikirim ulang ke alamat yang tersimpan.', ['EmailTerverifikasi' => false], ['EmailTerverifikasi' => false, 'Dikirim' => true]);
        });
    }
}
