<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Dukungan\Model\LogPerangkatDiunggah;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Alat bantu (baca): log terakhir yang diunggah satu perangkat atas permintaan dukungan (PGL-16 bagian 2). Teks sudah
 * disaring dari data pribadi saat diunggah; membacanya tetap hanya selama izin akses dukungan berlaku.
 */
final class LihatLogPerangkatDukungan
{
    public function __construct(private readonly PelaksanaAlatDukungan $pelaksana) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, string $uuidPerangkat): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::LihatLogPerangkat, ['UuidPerangkat' => $uuidPerangkat], function () use ($uuidPerangkat): HasilKerjaAlat {
            $perangkat = Perangkat::query()->where('Uuid', $uuidPerangkat)->first();

            if (! $perangkat instanceof Perangkat) {
                throw new PelanggaranAturanBisnis('PerangkatTidakDitemukan', 'Perangkat tidak ditemukan di tenant ini.');
            }

            $log = LogPerangkatDiunggah::query()->where('IdPerangkat', $perangkat->Id)->orderByDesc('Id')->first();

            if (! $log instanceof LogPerangkatDiunggah) {
                throw new PelanggaranAturanBisnis('LogBelumAda', 'Perangkat ini belum mengunggah log. Minta perangkat mengunggah log terlebih dahulu.');
            }

            return new HasilKerjaAlat(StatusHasilAlatDukungan::Berhasil, "Log {$perangkat->Kode} dibaca.", data: [
                'Log' => [
                    'Kode' => $perangkat->Kode,
                    'DiunggahPada' => $log->DiunggahPada->toIso8601String(),
                    'VersiAplikasi' => $log->VersiAplikasi,
                    'JumlahBaris' => $log->JumlahBaris,
                    'Isi' => $log->Isi,
                ],
            ]);
        });
    }
}
