<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Penjualan\Enum\JenisInvoice;
use Carbon\CarbonImmutable;

/**
 * Isian draf invoice (F-21, D-166). `terminHari` null = ikut `Pelanggan.TerminHari`. PPh (`kodePph` + `tarifPph` persen)
 * hanya harapan potongan pelanggan; tarifnya diisi pengguna sesuai ketentuan/bukti potong, tidak pernah ditebak sistem.
 * `uuidUangMuka` = invoice DP terbit milik pelanggan yang sama yang dipotong dari invoice akhir ini.
 */
final readonly class DataInvoice
{
    /**
     * @param  list<DataBarisInvoice>  $baris
     * @param  list<string>  $uuidUangMuka
     */
    public function __construct(
        public JenisInvoice $jenis,
        public string $uuidPelanggan,
        public int $idOutlet,
        public CarbonImmutable $tanggal,
        public ?int $terminHari,
        public array $baris,
        public bool $hargaTermasukPajak = false,
        public ?string $perihal = null,
        public ?string $nomorReferensi = null,
        public ?string $kodePph = null,
        public ?string $tarifPph = null,
        public ?string $catatan = null,
        public ?string $syarat = null,
        public ?string $infoPembayaran = null,
        public array $uuidUangMuka = [],
    ) {}
}
