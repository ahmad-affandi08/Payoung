<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Aksi;

use App\Domain\Bengkel\Enum\JenisUnitServis;
use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pengingat servis berkala (§9.10, km/waktu): tanggal dan/atau KM servis berikutnya per perintah kerja. Boleh diatur
 * kapan saja selama perintah kerja tidak dibatalkan (termasuk setelah ditagih — bukan angka keuangan). Mengganti
 * tanggal membuka lagi jatah satu pengingat WhatsApp untuk tanggal baru itu.
 */
final class AturServisBerikutnya
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(string $uuidPerintahKerja, ?CarbonImmutable $tanggal, ?int $km, int $idPengguna, CarbonImmutable $hariIni): PerintahKerja
    {
        return DB::transaction(function () use ($uuidPerintahKerja, $tanggal, $km, $idPengguna, $hariIni): PerintahKerja {
            $pk = PerintahKerja::query()->where('Uuid', $uuidPerintahKerja)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PerintahKerjaTidakDikenal', 'Perintah kerja tidak ditemukan.', 'Umum', 404);

            if ($km !== null && $pk->Kendaraan->Jenis === JenisUnitServis::Perangkat) {
                throw new PelanggaranAturanBisnis('KmTidakBerlaku', 'Pengingat berdasarkan kilometer hanya untuk kendaraan.', 'ServisBerikutnyaKm');
            }

            if ($pk->Status === StatusPerintahKerja::Dibatalkan) {
                throw new PelanggaranAturanBisnis('PerintahKerjaDibatalkan', 'Perintah kerja yang dibatalkan tidak punya jadwal servis berikutnya.', 'Status');
            }

            if ($tanggal !== null && $tanggal->toDateString() <= $hariIni->toDateString()) {
                throw new PelanggaranAturanBisnis('TanggalTidakValid', 'Tanggal servis berikutnya harus setelah hari ini.', 'ServisBerikutnyaPada');
            }

            if ($km !== null && $pk->KmMasuk !== null && $km <= $pk->KmMasuk) {
                throw new PelanggaranAturanBisnis('KmTidakValid', "KM servis berikutnya harus lebih dari KM masuk ({$pk->KmMasuk}).", 'ServisBerikutnyaKm');
            }

            $lama = ['ServisBerikutnyaPada' => $pk->ServisBerikutnyaPada?->toDateString(), 'ServisBerikutnyaKm' => $pk->ServisBerikutnyaKm];

            if ($lama['ServisBerikutnyaPada'] !== $tanggal?->toDateString()) {
                $pk->PengingatServisDiprosesPada = null;
                $pk->PengingatServisTerkirimPada = null;
            }

            $pk->forceFill(['ServisBerikutnyaPada' => $tanggal?->toDateString(), 'ServisBerikutnyaKm' => $km])->save();
            $this->audit->Catat('bengkel.servis-berikutnya', $pk, $lama, ['ServisBerikutnyaPada' => $tanggal?->toDateString(), 'ServisBerikutnyaKm' => $km], idPengguna: $idPengguna);

            return $pk;
        });
    }
}
