<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Hasil hitungan laporan Pemasukan & Pengeluaran (D-255): periode ini dan pembandingnya beserta rinciannya, sebelum
 * disusun menjadi tampilan halaman atau buku kerja Excel. `saring` sudah dipotong sampai hari ini.
 */
final readonly class HitunganUangKas
{
    /**
     * @param  array<string, string>  $catatan  Hutang, Piutang, MenungguCair (string uang)
     */
    public function __construct(
        public SaringLaporanKeuangan $saring,
        public string $dariSebelumnya,
        public string $sampaiSebelumnya,
        public string $jenisPembanding,
        public AkumulatorUangKas $kini,
        public AkumulatorUangKas $lalu,
        public Uang $kenaikanKas,
        public array $catatan,
        public bool $adaAkunKas,
    ) {}
}
