<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

/**
 * Menyalakan atau mematikan harga otomatis dan mengatur batas amannya (D-266). Batas aman = perubahan harga terbesar
 * (persen) yang boleh diterapkan sendiri, 1 sampai 100. Di atasnya perubahan jadi usulan yang menunggu keputusan pemilik.
 * Mematikan tidak mengembalikan harga yang sudah berubah. Audit `harga-otomatis.pengaturan.ubah`.
 */
final class UbahPengaturanHargaOtomatis
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis BatasTidakValid
     */
    public function Jalankan(bool $aktif, string $batasPersen, int $idPengguna): PengaturanHargaOtomatis
    {
        $batas = $this->AmbilBatas($batasPersen);

        return DB::transaction(function () use ($aktif, $batas, $idPengguna): PengaturanHargaOtomatis {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $pengaturan = PengaturanHargaOtomatis::query()->first() ?? new PengaturanHargaOtomatis;
            $lama = ['Aktif' => $pengaturan->Aktif, 'BatasPerubahanPersen' => (string) $pengaturan->BatasPerubahanPersen];

            $pengaturan->Aktif = $aktif;
            $pengaturan->BatasPerubahanPersen = (string) $batas;
            $pengaturan->DiubahOleh = $idPengguna;

            if ($pengaturan->exists && ! $pengaturan->isDirty(['Aktif', 'BatasPerubahanPersen'])) {
                return $pengaturan;
            }

            $pengaturan->save();
            $this->audit->Catat('harga-otomatis.pengaturan.ubah', $pengaturan, $lama, ['Aktif' => $aktif, 'BatasPerubahanPersen' => (string) $batas], idPengguna: $idPengguna);

            return $pengaturan;
        });
    }

    private function AmbilBatas(string $nilai): BigDecimal
    {
        $teks = str_replace(',', '.', trim($nilai));

        try {
            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $teks) === 1) {
                $batas = BigDecimal::of($teks);

                if ($batas->isGreaterThanOrEqualTo(1) && $batas->isLessThanOrEqualTo(100)) {
                    return $batas->toScale(2);
                }
            }
        } catch (MathException) {
            // jatuh ke galat di bawah
        }

        throw new PelanggaranAturanBisnis('BatasTidakValid', 'Isi batas aman antara 1 dan 100 persen, misal 20.', 'BatasPerubahanPersen');
    }
}
