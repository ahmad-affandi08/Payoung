<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Data;

use App\Domain\Akuntansi\Data\DataGiroMasukan;
use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;

/**
 * Isian pelunasan piutang (F-12): akun kas/bank, tanggal, dan alokasi per Uuid piutang (boleh sebagian). v3.42: `giro`
 * terisi = dibayar dengan giro/cek mundur (akun kas/bank diabaikan; nilainya ke Giro Mundur Diterima sampai cair).
 * F-21 (D-166): `potonganPph` per Uuid piutang = bagian alokasi yang **tidak diterima sebagai uang** karena pelanggan memotong
 * PPh (mis. PPh 23) dan memberi bukti potong; piutangnya tetap lunas sebesar alokasi, selisihnya menjadi PPh dibayar dimuka.
 */
final readonly class DataPembayaranPiutang
{
    /**
     * @param  array<string, Uang>  $alokasi  kunci = Uuid piutang
     * @param  array<string, Uang>  $potonganPph  kunci = Uuid piutang (hanya yang dipotong), nilainya ≤ alokasinya
     */
    public function __construct(
        public string $uuidPelanggan,
        public string $uuidAkun,
        public CarbonImmutable $tanggal,
        public array $alokasi,
        public ?string $catatan,
        public int $idPengguna,
        public ?DataGiroMasukan $giro = null,
        public array $potonganPph = [],
        public ?string $nomorBuktiPotong = null,
    ) {}
}
