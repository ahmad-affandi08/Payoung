<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Data;

use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Masukan `SimpanBelanjaStok` (F-04 fase 1, mode UMKM): pemasok opsional, lokasi stok (sudah diperiksa batas outlet),
 * baris tanpa PO, ongkir, akun kas/bank pembayar, dan nomor nota pemasok opsional. `dariUangLaci` (D-256): dibayar dari uang
 * laci kasir yang sudah dicatat keluar di POS (melunasi akun penampung), jadi `uuidAkun` kosong.
 */
final readonly class DataBelanjaStok
{
    /**
     * @param  list<DataBarisPenerimaanBarang>  $baris
     */
    public function __construct(
        public ?string $uuidPemasok,
        public int $idGudang,
        public CarbonImmutable $tanggal,
        public string $uuidAkun,
        public ?string $nomorNota,
        public Uang $ongkir,
        public ?string $catatan,
        public array $baris,
        public ?UploadedFile $lampiran,
        public int $idPengguna,
        public bool $dariUangLaci = false,
    ) {}
}
