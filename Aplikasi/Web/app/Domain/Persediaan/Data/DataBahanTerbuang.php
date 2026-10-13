<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use Carbon\CarbonImmutable;

/**
 * Catatan bahan terbuang yang sudah divalidasi bentuknya (F-05f). `jumlah` dalam satuan dasar produk yang dicatat
 * (menu resep diuraikan ke bahannya oleh Aksi). `tinjauan` = alasan tinjauan dari penerima POS (izin berubah, dsb.).
 * `abaikanBatasMinus` = kejadian sudah terjadi di kasir, stok kurang dicatat + tinjauan (bukan ditolak).
 */
final readonly class DataBahanTerbuang
{
    /**
     * @param  list<string>  $tinjauan
     */
    public function __construct(
        public string $uuid,
        public ?int $idOutlet,
        public int $idGudang,
        public ?int $idPerangkat,
        public int $idProduk,
        public Kuantitas $jumlah,
        public AlasanBahanTerbuang $alasan,
        public ?string $catatan,
        public int $idPengguna,
        public string $sumber,
        public ?CarbonImmutable $dibuatOfflinePada,
        public CarbonImmutable $tanggalBisnis,
        public array $tinjauan = [],
        public bool $abaikanBatasMinus = false,
        // BR-05.4: dari POS, bahan resep diambil dari lokasi produksinya (Dapur/Bar) bila outlet punya; back-office memakai `idGudang`.
        public bool $pakaiLokasiProduksi = false,
    ) {}
}
