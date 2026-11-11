<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Persediaan\Kebijakan\DokumenPersediaanKebijakan;

/**
 * Bantuan bersama kontroler dokumen persediaan F-05b (transfer, opname, penyesuaian): prop `Izin` (tipe FE
 * `IzinDokumenPersediaan`), opsi lokasi stok tanpa lokasi "Dalam perjalanan", lokasi tujuan transfer (semua outlet),
 * dan pemeriksaan outlet akses (tidak boleh = 404; boleh lihat tapi bukan outlet pelaku = 403).
 */
abstract class DasarDokumenPersediaanKontroler extends DasarPersediaanKontroler
{
    /**
     * @return array{Lihat: bool, Kelola: bool, Setujui: bool, LihatJurnal: bool}
     */
    protected function AmbilIzinDokumen(): array
    {
        return [
            'Lihat' => $this->CekIzin(IzinTenant::PersediaanLihat),
            'Kelola' => $this->CekIzin(IzinTenant::PersediaanKelola),
            'Setujui' => $this->CekIzin(IzinTenant::PersediaanPenyesuaianSetujui),
            'LihatJurnal' => $this->CekIzin(IzinTenant::LaporanKeuanganLihat),
        ];
    }

    /**
     * Opsi lokasi stok yang boleh diakses, tanpa lokasi dalam perjalanan (tipe FE `OpsiGudang[]`).
     *
     * @return list<array{Uuid: string, Kode: string, Nama: string, Jenis: string, NamaOutlet: string|null, Aktif: bool}>
     */
    protected function AmbilOpsiGudangDokumen(bool $semuaOutlet = false, bool $hanyaAktif = true): array
    {
        $gudang = app(InfoGudang::class)->AmbilBoleh($semuaOutlet ? null : $this->IdOutletBoleh(), $hanyaAktif);

        return array_values(array_map(fn (DataInfoGudang $g): array => [
            'Uuid' => $g->uuid,
            'Kode' => $g->kode,
            'Nama' => $g->nama,
            'Jenis' => $g->jenis->value,
            'NamaOutlet' => $g->namaOutlet,
            'Aktif' => $g->aktif,
        ], array_filter($gudang, fn (DataInfoGudang $g): bool => $g->jenis !== JenisGudang::DalamPerjalanan)));
    }

    /** Lokasi stok tenant (outlet mana pun), misal tujuan transfer. Tidak ada = 404. */
    protected function CariGudangTenant(string $uuid): DataInfoGudang
    {
        $gudang = app(InfoGudang::class)->AmbilDariUuid([$uuid])[$uuid] ?? null;
        abort_if($gudang === null, 404);

        return $gudang;
    }

    protected function CekBolehOutlet(?int $idOutlet): bool
    {
        return app(DokumenPersediaanKebijakan::class)->CekBolehOutlet($idOutlet, $this->IdOutletBoleh());
    }

    /**
     * Batas atas tanggal dokumen = tanggal bisnis outlet (jam tutup buku, sama dengan `PemeriksaLokasiDokumen`), bukan
     * tanggal kalender. Outlet belum diketahui (formulir): tanggal bisnis paling awal di antara outlet yang boleh diakses,
     * supaya tidak pernah melewati batas server.
     */
    protected function HariIni(?int $idOutlet = null): string
    {
        $tanggalBisnis = app(TanggalBisnisOutlet::class);
        $daftarOutlet = $idOutlet !== null ? [$idOutlet] : ($this->IdOutletBoleh() ?? Outlet::query()->pluck('Id')->all());

        if ($daftarOutlet === []) {
            return $tanggalBisnis->Hitung(null)->format('Y-m-d');
        }

        return min(array_map(fn (int $id): string => $tanggalBisnis->Hitung($id)->format('Y-m-d'), $daftarOutlet));
    }
}
