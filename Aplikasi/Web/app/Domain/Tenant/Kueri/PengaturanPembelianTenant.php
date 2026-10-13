<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Pengaturan pembelian tenant aktif dari `Tenant.Pengaturan` (F-04 fase 1): `BatasPersetujuanPo` (string desimal,
 * bawaan Rp 5.000.000) dan `ToleransiPenerimaanPersen` (persen 0–100, bawaan 0). Nilai rusak kembali ke bawaan.
 */
final class PengaturanPembelianTenant
{
    public function __construct(private readonly KonteksTenant $konteks) {}

    public function Ambil(): DataPengaturanPembelian
    {
        $pengaturan = Tenant::query()->whereKey($this->konteks->Wajib())->firstOrFail()->Pengaturan ?? [];

        return new DataPengaturanPembelian(
            self::AmbilUang($pengaturan['BatasPersetujuanPo'] ?? null, DataPengaturanPembelian::BATAS_PERSETUJUAN_PO_BAWAAN),
            self::AmbilPersen($pengaturan['ToleransiPenerimaanPersen'] ?? null),
            ($pengaturan['DrafPoOtomatis'] ?? true) !== false,
            ($pengaturan['SumberDrafPo'] ?? null) === DataPengaturanPembelian::SUMBER_DRAF_PERKIRAAN ? DataPengaturanPembelian::SUMBER_DRAF_PERKIRAAN : DataPengaturanPembelian::SUMBER_DRAF_MINIMUM,
            is_int($pengaturan['HariCakupanDrafPo'] ?? null) && in_array($pengaturan['HariCakupanDrafPo'], DataPengaturanPembelian::HARI_CAKUPAN_DRAF_OPSI, true) ? $pengaturan['HariCakupanDrafPo'] : DataPengaturanPembelian::HARI_CAKUPAN_DRAF_BAWAAN,
        );
    }

    private static function AmbilUang(mixed $nilai, string $bawaan): Uang
    {
        if (is_string($nilai)) {
            try {
                $uang = Uang::Dari($nilai);

                if (! $uang->BernilaiNegatif()) {
                    return $uang;
                }
            } catch (MathException) {
                // Nilai tersimpan rusak: pakai bawaan.
            }
        }

        return Uang::Dari($bawaan);
    }

    private static function AmbilPersen(mixed $nilai): BigDecimal
    {
        if (is_string($nilai) || is_int($nilai)) {
            try {
                $persen = BigDecimal::of($nilai);

                if (! $persen->isNegative() && $persen->isLessThanOrEqualTo(DataPengaturanPembelian::TOLERANSI_PENERIMAAN_MAKSIMAL) && $persen->getScale() <= 2) {
                    return $persen->toScale(2);
                }
            } catch (MathException) {
                // Nilai tersimpan rusak: pakai bawaan.
            }
        }

        return BigDecimal::of(DataPengaturanPembelian::TOLERANSI_PENERIMAAN_BAWAAN)->toScale(2);
    }
}
