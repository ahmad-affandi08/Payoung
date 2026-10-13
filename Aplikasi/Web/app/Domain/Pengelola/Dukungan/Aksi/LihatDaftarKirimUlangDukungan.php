<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Dukungan\Layanan\PenyamarDataPribadi;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Model\PesanKeluar;

/**
 * Alat bantu (baca): pesan keluar yang gagal terkirim dan anggota tenant yang emailnya belum terverifikasi, sebagai bahan
 * pilihan alat kirim ulang (PGL-16 bagian 2). Nomor dan email selalu tersamar, apa pun cakupan izin akses dukungan.
 */
final class LihatDaftarKirimUlangDukungan
{
    public const HARI_RIWAYAT = 14;

    public const BATAS = 50;

    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly PenyamarDataPribadi $penyamar,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::DaftarKirimUlang, [], function () use ($idTenant): HasilKerjaAlat {
            $pesan = [];

            foreach (PesanKeluar::query()
                ->where('Status', StatusPesanKeluar::Gagal->value)
                ->where('DibuatPada', '>=', now()->subDays(self::HARI_RIWAYAT))
                ->orderByDesc('Id')
                ->limit(self::BATAS)
                ->get() as $p) {
                $sudahUlang = PesanKeluar::query()
                    ->where('Jenis', $p->Jenis->value)
                    ->where('IdReferensi', $p->IdReferensi)
                    ->where('Kanal', $p->Kanal->value)
                    ->where('Id', '>', $p->Id)
                    ->whereIn('Status', [StatusPesanKeluar::Diantrekan->value, StatusPesanKeluar::Terkirim->value])
                    ->get()
                    ->contains(fn (PesanKeluar $lain): bool => $lain->Tujuan === $p->Tujuan);

                $pesan[] = [
                    'Uuid' => $p->Uuid,
                    'Kanal' => $p->Kanal->value,
                    'Jenis' => $p->Jenis->value,
                    'TujuanTersamar' => $this->penyamar->SamarkanNilai($p->Tujuan, $p->Kanal->value === 'Email' ? 'email' : 'telepon'),
                    'Percobaan' => $p->Percobaan,
                    'PesanGalat' => $p->PesanGalat,
                    'DibuatPada' => $p->DibuatPada?->toIso8601String(),
                    'SudahDikirimUlang' => $sudahUlang,
                ];
            }

            $belumVerifikasi = [];

            foreach (TenantPengguna::query()->with('Pengguna')->where('IdTenant', $idTenant)->orderBy('Id')->get() as $anggota) {
                $pengguna = $anggota->Pengguna;

                if ($pengguna instanceof Pengguna && $pengguna->Email !== null && $pengguna->EmailDiverifikasiPada === null) {
                    $belumVerifikasi[] = [
                        'Uuid' => $pengguna->Uuid,
                        'Nama' => $pengguna->Nama,
                        'EmailTersamar' => $this->penyamar->SamarkanNilai($pengguna->Email, 'email'),
                        'Pemilik' => $anggota->Pemilik,
                    ];
                }
            }

            return new HasilKerjaAlat(
                StatusHasilAlatDukungan::Berhasil,
                count($pesan).' pesan gagal dan '.count($belumVerifikasi).' email belum terverifikasi.',
                data: ['PesanGagal' => $pesan, 'AnggotaBelumVerifikasi' => $belumVerifikasi],
            );
        });
    }
}
