<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Data;

use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;

/**
 * Pengaturan pembelian tingkat tenant dari `Tenant.Pengaturan` (F-04 fase 1, PRD v1.51):
 * - `batasPersetujuanPo`: total PO di atas nilai ini butuh persetujuan `pembelian.po.setujui` oleh orang lain
 *   (bawaan Rp 5.000.000, §19.2).
 * - `toleransiPenerimaanPersen`: penerimaan barang boleh melebihi jumlah PO sampai persen ini (BR-04.1, bawaan 0).
 *   Persen = BigDecimal skala 2 (tidak pernah float).
 * - `drafPoOtomatis` (D-23 D): tiap pagi sistem menyiapkan draf PO (bawaan aktif).
 * - `sumberDrafPo` (F24, INV-13): `Minimum` = barang di bawah stok minimum (bawaan); `Perkiraan` = kebutuhan dari laju
 *   pemakaian 28 hari terakhir untuk `hariCakupanDrafPo` hari (7/14/30, bawaan 14).
 */
final readonly class DataPengaturanPembelian
{
    public const BATAS_PERSETUJUAN_PO_BAWAAN = '5000000.00';

    public const TOLERANSI_PENERIMAAN_BAWAAN = '0.00';

    public const SUMBER_DRAF_MINIMUM = 'Minimum';

    public const SUMBER_DRAF_PERKIRAAN = 'Perkiraan';

    public const HARI_CAKUPAN_DRAF_OPSI = [7, 14, 30];

    public const HARI_CAKUPAN_DRAF_BAWAAN = 14;

    /** Batas atas wajar toleransi penerimaan (persen). */
    public const TOLERANSI_PENERIMAAN_MAKSIMAL = '100.00';

    public BigDecimal $toleransiPenerimaanPersen;

    public function __construct(
        public Uang $batasPersetujuanPo,
        BigDecimal|string|null $toleransiPenerimaanPersen = null,
        public bool $drafPoOtomatis = true,
        public string $sumberDrafPo = self::SUMBER_DRAF_MINIMUM,
        public int $hariCakupanDrafPo = self::HARI_CAKUPAN_DRAF_BAWAAN,
    ) {
        $this->toleransiPenerimaanPersen = BigDecimal::of($toleransiPenerimaanPersen ?? self::TOLERANSI_PENERIMAAN_BAWAAN)->toScale(2);
    }
}
