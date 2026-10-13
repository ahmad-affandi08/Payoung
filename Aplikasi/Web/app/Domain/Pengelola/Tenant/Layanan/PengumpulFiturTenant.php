<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Layanan;

use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Promo\Model\Promo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fitur utama yang dipakai tenant (P-07 PGL-09 skor kesehatan, PGL-24 adopsi fitur). Tiap fitur ditandai bila ada
 * sedikitnya satu baris pada kelompok tenant: opname dan pesanan pembelian dibuat, serta pelanggan baru, dalam
 * `HARI_TERBARU` hari terakhir; promo dan karyawan cukup ada. Satu kueri `DISTINCT IdTenant` per fitur untuk seluruh
 * kelompok tenant, bukan kueri per tenant. Dipanggil di dalam `KonteksPengelola::JalankanLintasTenant`; hanya
 * pengenal fitur yang keluar, bukan isi data tenant.
 */
final class PengumpulFiturTenant
{
    public const HARI_TERBARU = 30;

    /** Kunci fitur => label untuk laporan adopsi. */
    public const FITUR = [
        'Opname' => 'Stok opname',
        'PesananPembelian' => 'Pesanan pembelian',
        'Pelanggan' => 'Data pelanggan',
        'Promo' => 'Promo',
        'Karyawan' => 'Karyawan',
    ];

    /**
     * @param  list<int>  $idTenant
     * @return array<int, list<string>> id tenant → kunci fitur yang dipakai (urut seperti `FITUR`)
     */
    public function Kumpulkan(KonteksPengelola $k, array $idTenant, CarbonImmutable $sekarang): array
    {
        $batas = $sekarang->subDays(self::HARI_TERBARU);
        $sumber = [
            'Opname' => $k->KueriLintas(StokOpname::class)->where('DibuatPada', '>=', $batas),
            'PesananPembelian' => $k->KueriLintas(PesananPembelian::class)->where('DibuatPada', '>=', $batas),
            'Pelanggan' => $k->KueriLintas(Pelanggan::class)->where('DibuatPada', '>=', $batas),
            'Promo' => $k->KueriLintas(Promo::class),
            'Karyawan' => $k->KueriLintas(Karyawan::class),
        ];
        $hasil = [];

        foreach ($sumber as $kunci => $kueri) {
            foreach ($this->Himpun($kueri->whereIn('IdTenant', $idTenant)) as $id) {
                $hasil[$id][] = $kunci;
            }
        }

        return $hasil;
    }

    /**
     * @param  Builder<*>  $kueri
     * @return list<int>
     */
    private function Himpun(Builder $kueri): array
    {
        return array_values(array_map('intval', $kueri->distinct()->pluck('IdTenant')->all()));
    }
}
