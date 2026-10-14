<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Data;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\ArahPembulatan;
use Brick\Math\BigDecimal;

/**
 * Pengaturan kasir tingkat tenant dari `Tenant.Pengaturan`:
 * - F-06 (PRD v1.34): batas kas keluar tanpa persetujuan (BR-06.4, bawaan Rp 200.000 sesuai §19.2) dan mode shift
 *   bersama (BR-06.2, bawaan mati).
 * - F-07b (PRD v1.43): batas diskon manual kasir dalam persen (BR-07.3, bawaan 10), batas diskon yang boleh disetujui
 *   penyetuju (bawaan 30; Pemilik tanpa batas), dan pembulatan tunai (BR-08.6; null = tanpa pembulatan).
 *   Persen = BigDecimal skala 2 (tidak pernah float).
 * - F-11 (PRD v1.45): tutup shift buta (bawaan aktif; kas seharusnya tidak ditampilkan sebelum hitungan disimpan)
 *   dan toleransi selisih kas (bawaan Rp 10.000, §19.2; di atasnya wajib alasan + PIN `shift.selisih.setujui`).
 * - F-09 (PRD v1.45): batas hari retur penjualan sejak tanggal bisnis penjualan (bawaan 7; 0 = hanya hari yang sama).
 * - F-12 (PRD v1.63): penjualan tempo butuh penyetuju bila pelanggan punya piutang lewat jatuh tempo lebih dari N hari
 *   (BR-12.1, bawaan 0 = lewat jatuh tempo sehari pun butuh penyetuju).
 * - Cetak struk bagian 4 (PRD v1.87, §19.2): buka laci manual tanpa transaksi selalu dicatat; PIN penyetuju
 *   `kas.keluar.setujui` opsional (bawaan mati).
 * - OWN-03 (PRD v6.27): void, retur, atau diskon penjualan dengan nilai sama atau di atas batas ini dikabarkan ke
 *   pengguna berizin lewat notifikasi (bawaan Rp 500.000; 0 = setiap kejadian).
 * - K28 (PRD v4.01): batas nilai retur tanpa struk per outlet per hari (bawaan Rp 1.000.000); di atasnya retur tetap
 *   diterima tetapi menjadi tinjauan.
 */
final readonly class DataPengaturanKasir
{
    public const BATAS_KAS_KELUAR_BAWAAN = '200000.00';

    public const BATAS_DISKON_MANUAL_BAWAAN = '10.00';

    public const BATAS_DISKON_PENYETUJU_BAWAAN = '30.00';

    public const TOLERANSI_SELISIH_KAS_BAWAAN = '10000.00';

    public const BATAS_HARI_RETUR_BAWAAN = 7;

    /** Batas atas wajar batas hari retur (satu tahun). */
    public const BATAS_HARI_RETUR_MAKSIMAL = 365;

    public const BATAS_HARI_LEWAT_JATUH_TEMPO_BAWAAN = 0;

    public const BATAS_RETUR_TANPA_STRUK_HARIAN_BAWAAN = '1000000.00';

    public const BATAS_NOTIFIKASI_TRANSAKSI_BAWAAN = '500000.00';

    public BigDecimal $batasDiskonManual;

    public BigDecimal $batasDiskonPenyetuju;

    /** F-11: |selisih| di atas nilai ini wajib alasan + penyetuju. */
    public Uang $toleransiSelisihKas;

    /** K28: Σ retur tanpa struk outlet per tanggal bisnis di atas nilai ini menjadi tinjauan. */
    public Uang $batasReturTanpaStrukHarian;

    /** OWN-03: nilai void/retur/diskon yang memicu notifikasi ke pengguna berizin. */
    public Uang $batasNotifikasiTransaksi;

    /**
     * @param  array{Kelipatan: int, Arah: ArahPembulatan}|null  $pembulatanTunai
     */
    public function __construct(
        public Uang $batasKasKeluar,
        public bool $shiftBersama,
        BigDecimal|string|null $batasDiskonManual = null,
        BigDecimal|string|null $batasDiskonPenyetuju = null,
        public ?array $pembulatanTunai = null,
        public bool $tutupShiftButa = true,
        ?Uang $toleransiSelisihKas = null,
        public int $batasHariRetur = self::BATAS_HARI_RETUR_BAWAAN,
        public int $batasHariLewatJatuhTempo = self::BATAS_HARI_LEWAT_JATUH_TEMPO_BAWAAN,
        public bool $bukaLaciPerluPin = false,
        ?Uang $batasReturTanpaStrukHarian = null,
        ?Uang $batasNotifikasiTransaksi = null,
    ) {
        $this->batasDiskonManual = BigDecimal::of($batasDiskonManual ?? self::BATAS_DISKON_MANUAL_BAWAAN)->toScale(2);
        $this->batasDiskonPenyetuju = BigDecimal::of($batasDiskonPenyetuju ?? self::BATAS_DISKON_PENYETUJU_BAWAAN)->toScale(2);
        $this->toleransiSelisihKas = $toleransiSelisihKas ?? Uang::Dari(self::TOLERANSI_SELISIH_KAS_BAWAAN);
        $this->batasReturTanpaStrukHarian = $batasReturTanpaStrukHarian ?? Uang::Dari(self::BATAS_RETUR_TANPA_STRUK_HARIAN_BAWAAN);
        $this->batasNotifikasiTransaksi = $batasNotifikasiTransaksi ?? Uang::Dari(self::BATAS_NOTIFIKASI_TRANSAKSI_BAWAAN);
    }

    /**
     * Bentuk JSON (`data-awal`, halaman pengaturan): `{Kelipatan, Arah}` atau null.
     *
     * @return array{Kelipatan: int, Arah: string}|null
     */
    public function AmbilPembulatanTunaiLarik(): ?array
    {
        return $this->pembulatanTunai === null ? null : ['Kelipatan' => $this->pembulatanTunai['Kelipatan'], 'Arah' => $this->pembulatanTunai['Arah']->value];
    }
}
