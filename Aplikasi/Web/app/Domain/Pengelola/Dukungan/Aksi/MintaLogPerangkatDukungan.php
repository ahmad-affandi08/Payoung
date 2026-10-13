<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Alat bantu (ubah): minta perangkat mengunggah log lokalnya (PGL-16 bagian 2). Hanya menandai `Perangkat.MintaLogPada`;
 * aplikasi kasir membacanya dari `konfigurasi-aplikasi` (tiap ±15 menit atau saat dibuka) lalu mengunggah log yang sudah
 * tersaring ke `POST /api/pos/v1/perangkat/log` (`TerimaLogPerangkat`). Permintaan yang belum dipenuhi tidak ditumpuk, dan
 * kedaluwarsa setelah `Perangkat::HARI_PERMINTAAN_LOG` hari.
 */
final class MintaLogPerangkatDukungan
{
    public function __construct(private readonly PelaksanaAlatDukungan $pelaksana) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, string $uuidPerangkat): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::MintaLogPerangkat, ['UuidPerangkat' => $uuidPerangkat], function () use ($uuidPerangkat): HasilKerjaAlat {
            $perangkat = Perangkat::query()->where('Uuid', $uuidPerangkat)->first();

            if (! $perangkat instanceof Perangkat) {
                throw new PelanggaranAturanBisnis('PerangkatTidakDitemukan', 'Perangkat tidak ditemukan di tenant ini.');
            }

            if ($perangkat->AmbilStatus() !== 'Aktif') {
                throw new PelanggaranAturanBisnis('PerangkatTidakAktif', 'Hanya perangkat yang aktif yang bisa diminta mengunggah log.');
            }

            $sebelum = ['MintaLogPada' => $perangkat->MintaLogPada?->toIso8601String(), 'LogDiunggahPada' => $perangkat->LogDiunggahPada?->toIso8601String()];

            if ($perangkat->CekPermintaanLogBerjalan()) {
                return new HasilKerjaAlat(StatusHasilAlatDukungan::TanpaPerubahan, "Perangkat {$perangkat->Kode} sudah diminta mengunggah log dan belum menjawab.", $sebelum, $sebelum);
            }

            $sekarang = now();
            $perangkat->MintaLogPada = $sekarang;
            $perangkat->save();

            return new HasilKerjaAlat(
                StatusHasilAlatDukungan::Berhasil,
                "Perangkat {$perangkat->Kode} diminta mengunggah log. Log muncul begitu perangkat terhubung ke server.",
                $sebelum,
                ['MintaLogPada' => $sekarang->toIso8601String(), 'LogDiunggahPada' => $perangkat->LogDiunggahPada?->toIso8601String()],
            );
        });
    }
}
