<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use Carbon\CarbonImmutable;

/**
 * Item outbox `PesananTerbuka.*` (F-07 mode meja fase 1) yang sudah divalidasi. Bidang yang tidak dipakai jenis
 * item tertentu dibiarkan kosong; `ubah*` menandai bidang header yang dikirim pada `PesananTerbuka.Ubah`;
 * `uuidTujuan` & `tutupAsal` untuk `PesananTerbuka.PindahBaris` (v1.99); `mintaBill` null = tidak dikirim (K-12, v3.63); `catatTerbuang` = void item juga dicatat sebagai bahan terbuang (BR-07.5, v5.78).
 */
final readonly class DataPesananTerbukaPos
{
    /**
     * @param  list<DataBarisPesananTerbuka>  $baris
     * @param  list<string>  $uuidBaris
     */
    public function __construct(
        public int $idPerangkat,
        public int $idOutlet,
        public string $uuidPesanan,
        public string $uuidPengguna,
        public CarbonImmutable $waktu,
        public ?string $nomor = null,
        public ?string $uuidMeja = null,
        public ?string $label = null,
        public ?int $jumlahTamu = null,
        public bool $ubahMeja = false,
        public bool $ubahLabel = false,
        public int $ronde = 1,
        public bool $kirimDapur = false,
        public array $baris = [],
        public array $uuidBaris = [],
        public ?string $alasan = null,
        public ?string $uuidPenyetuju = null,
        public ?string $uuidTujuan = null,
        public bool $tutupAsal = false,
        public ?bool $mintaBill = null,
        public bool $catatTerbuang = false,
    ) {}
}
