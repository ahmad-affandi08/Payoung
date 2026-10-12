<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Aksi\CabutAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Super Admin menutup akses darurat lebih awal (PGL-16, P-09 langkah 5). Izin yang diberi Owner tidak bisa dicabut dari
 * konsol: itu keputusan Owner. Sesi yang sedang berjalan berakhir seketika.
 */
final class CabutAksesDaruratPengelola
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly CabutAksesDukungan $cabut,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis TanpaIzin, AksesTidakAda, BukanAksesDarurat, AksesTidakBerlaku
     */
    public function Jalankan(PenggunaPengelola $pelaku, int $idTenant, string $uuidAkses): AksesDukungan
    {
        if (! $pelaku->Aktif || ! $pelaku->PunyaIzin(IzinPengelola::DukunganAksesDarurat)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Hanya Super Admin yang boleh menutup akses darurat.');
        }

        return $this->konteks->JalankanLintasTenant('Menutup akses darurat tenant', function () use ($pelaku, $uuidAkses): AksesDukungan {
            $akses = AksesDukungan::query()->where('Uuid', $uuidAkses)->first();

            if (! $akses instanceof AksesDukungan) {
                throw new PelanggaranAturanBisnis('AksesTidakAda', 'Akses tidak ditemukan di tenant ini.');
            }

            if ($akses->Jenis !== JenisAksesDukungan::Darurat) {
                throw new PelanggaranAturanBisnis('BukanAksesDarurat', 'Izin yang diberi Owner hanya bisa dicabut oleh Owner.');
            }

            return $this->cabut->Jalankan($akses, null, $pelaku->Id);
        }, $idTenant);
    }
}
