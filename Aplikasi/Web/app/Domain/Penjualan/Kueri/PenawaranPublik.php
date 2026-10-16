<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;

/**
 * Penawaran untuk halaman publik `/p/{kodePenawaran}` (F-21 bagian 3), di dalam konteks tenant yang sudah dipasang
 * pemanggil. Hanya penawaran yang sudah terbit yang tampil (draf belum ditawarkan, jadi 404); yang dibatalkan, ditolak,
 * atau kedaluwarsa tetap dibuka dengan tandanya supaya pelanggan yang menyimpan tautan lama tidak keliru.
 */
final class PenawaranPublik
{
    public function __construct(
        private readonly DokumenPenawaran $dokumen,
        private readonly ProfilTenant $profil,
    ) {}

    public function Cari(string $kodePublik): ?Penawaran
    {
        $penawaran = Penawaran::query()->where('KodePublik', $kodePublik)->first();

        return $penawaran !== null && $penawaran->Status !== StatusPenawaran::Draf ? $penawaran : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Penawaran $penawaran): array
    {
        return $this->dokumen->Ambil($penawaran, $this->HariIni($penawaran->IdTenant), untukPublik: true);
    }

    public function HariIni(int $idTenant): string
    {
        return CarbonImmutable::now($this->profil->Ambil($idTenant)['ZonaWaktu'])->toDateString();
    }
}
