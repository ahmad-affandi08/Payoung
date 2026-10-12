<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Data;

use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use Carbon\CarbonImmutable;

/**
 * Sesi akses dukungan yang sedang sah pada satu permintaan (PGL-16): hasil `PenjagaSesiAksesDukungan`, dipegang
 * `KonteksAksesDukungan` selama permintaan itu. `izin` = kunci izin tenant yang dipegang sesi (semua izin kecuali yang
 * dilarang saat dukungan); `idPengguna` = identitas teknis petugas di sisi tenant.
 */
final readonly class DataSesiDukunganAktif
{
    /**
     * @param  list<string>  $izin
     */
    public function __construct(
        public int $idSesi,
        public string $uuidSesi,
        public int $idAkses,
        public int $idTenant,
        public int $idPengguna,
        public int $idPenggunaPengelola,
        public string $namaPetugas,
        public JenisAksesDukungan $jenis,
        public CakupanAksesDukungan $cakupan,
        public bool $dataPribadiTerbuka,
        public CarbonImmutable $berakhirPada,
        public array $izin,
    ) {}
}
