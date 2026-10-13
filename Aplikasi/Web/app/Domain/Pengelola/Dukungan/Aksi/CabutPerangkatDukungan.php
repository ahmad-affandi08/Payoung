<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Organisasi\Aksi\CabutPerangkat;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Alat bantu (ubah): cabut perangkat tenant yang hilang, dijual, atau perlu diaktifkan ulang (PGL-16 bagian 2, BR-02.3).
 * Memakai `CabutPerangkat` yang sama dengan back-office; perangkat yang sudah dicabut tidak dicabut lagi. Setelah dicabut
 * Owner mengaktifkan perangkat baru dengan kode aktivasi baru ("reset"); kode perangkat lama tidak dipakai ulang.
 */
final class CabutPerangkatDukungan
{
    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly CabutPerangkat $cabut,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, string $uuidPerangkat): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::CabutPerangkat, ['UuidPerangkat' => $uuidPerangkat], function () use ($uuidPerangkat): HasilKerjaAlat {
            $perangkat = Perangkat::query()->where('Uuid', $uuidPerangkat)->first();

            if (! $perangkat instanceof Perangkat) {
                throw new PelanggaranAturanBisnis('PerangkatTidakDitemukan', 'Perangkat tidak ditemukan di tenant ini.');
            }

            $sebelum = $this->Keadaan($perangkat);

            if ($perangkat->CekDicabut()) {
                return new HasilKerjaAlat(StatusHasilAlatDukungan::TanpaPerubahan, "Perangkat {$perangkat->Kode} sudah dicabut sebelumnya.", $sebelum, $sebelum);
            }

            $dicabut = $this->cabut->Jalankan($perangkat);

            return new HasilKerjaAlat(StatusHasilAlatDukungan::Berhasil, "Perangkat {$dicabut->Kode} dicabut. Owner perlu membuat kode aktivasi baru bila perangkat dipakai lagi.", $sebelum, $this->Keadaan($dicabut));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function Keadaan(Perangkat $perangkat): array
    {
        return [
            'Kode' => $perangkat->Kode,
            'Status' => $perangkat->AmbilStatus(),
            'JumlahOutboxTertunda' => $perangkat->JumlahOutboxTertunda,
            'TerakhirAktifPada' => $perangkat->TerakhirAktifPada?->toIso8601String(),
        ];
    }
}
