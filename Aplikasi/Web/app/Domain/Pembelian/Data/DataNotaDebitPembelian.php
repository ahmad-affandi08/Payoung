<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Data;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Enum\JenisNotaDebit;
use Carbon\CarbonImmutable;

/**
 * Masukan `SimpanNotaDebitPembelian` (v6.07). `idFakturPembelianDetail` kosong = semua baris faktur yang masih punya
 * sisa nilai; `bolehMelebihiBatas` = pelaku memegang izin `pembelian.po.setujui` (nota di atas `BatasPersetujuanPo`).
 */
final readonly class DataNotaDebitPembelian
{
    /**
     * @param  list<int>  $idFakturPembelianDetail
     */
    public function __construct(
        public int $idFakturPembelian,
        public JenisNotaDebit $jenis,
        public CarbonImmutable $tanggal,
        public Uang $nilaiKlaim,
        public array $idFakturPembelianDetail,
        public string $alasan,
        public ?string $nomorNotaPemasok,
        public ?string $uuidAkunRefund,
        public bool $bolehMelebihiBatas,
        public int $idPengguna,
    ) {}
}
