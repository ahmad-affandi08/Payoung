<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use App\Domain\Tenant\Model\Tenant;

/**
 * Pemakaian penyimpanan berkas seluruh platform dan tenant terbesar (D-209, audit D-116). Angkanya meter pemakaian milik
 * platform (`PemakaianPenyimpanan`), bukan isi berkas tenant.
 */
final class PenyimpananTenantTerbesar
{
    private const BYTE_PER_MB = 1048576;

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    /**
     * @return array{TotalByte: int, JumlahTenant: int, Terbesar: list<array<string, mixed>>}
     */
    public function Ambil(int $batas = 10): array
    {
        $dasar = $this->konteks->KueriDataPlatform(PemakaianPenyimpanan::class);
        $total = (int) (clone $dasar)->sum('Byte');
        $jumlah = (clone $dasar)->where('Byte', '>', 0)->count();
        $baris = (clone $dasar)->where('Byte', '>', 0)->orderByDesc('Byte')->limit($batas)->get(['IdTenant', 'Byte']);
        $tenant = Tenant::query()->whereIn('Id', $baris->pluck('IdTenant')->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');

        return [
            'TotalByte' => $total,
            'JumlahTenant' => $jumlah,
            'Terbesar' => array_values($baris->map(function (PemakaianPenyimpanan $pemakaian) use ($tenant): array {
                $profil = $tenant->get($pemakaian->IdTenant);
                $batasMb = $this->fitur->AmbilBatasEfektif($pemakaian->IdTenant, PenjagaPenyimpanan::KOLOM_BATAS);

                return [
                    'Uuid' => $profil?->Uuid,
                    'Nama' => $profil->Nama ?? "Tenant #{$pemakaian->IdTenant}",
                    'Byte' => $pemakaian->Byte,
                    'BatasMb' => $batasMb,
                    'Persen' => $batasMb === null || $batasMb <= 0 ? null : (int) min(999, intdiv($pemakaian->Byte * 100, $batasMb * self::BYTE_PER_MB)),
                ];
            })->all()),
        ];
    }
}
