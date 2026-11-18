<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;

/**
 * Pemeriksaan lokasi stok & tanggal dokumen persediaan F-05b: lokasi milik tenant, aktif, dan bukan lokasi
 * "Dalam perjalanan" (hanya dipakai transfer secara otomatis); tanggal dokumen tidak di masa depan menurut tanggal
 * kalender (zona waktu) outlet lokasi itu. Bukan tanggal bisnis: sesudah tengah malam sebelum jam tutup buku pengguna tetap
 * melihat tanggal kalender baru di ponsel/layar dan wajar mendokumentasikan dengan tanggal itu.
 */
final class PemeriksaLokasiDokumen
{
    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
    ) {}

    public function AmbilLokasi(int $idGudang, string $bidang = 'UuidGudang'): DataInfoGudang
    {
        $gudang = $this->infoGudang->AmbilBanyak([$idGudang])[$idGudang] ?? null;

        if ($gudang === null) {
            throw new PelanggaranAturanBisnis('GudangTidakDikenal', 'Lokasi stok tidak ditemukan.', $bidang);
        }

        if (! $gudang->aktif) {
            throw new PelanggaranAturanBisnis('GudangDiarsipkan', "Lokasi stok {$gudang->nama} sudah diarsipkan.", $bidang);
        }

        if ($gudang->jenis === JenisGudang::DalamPerjalanan) {
            throw new PelanggaranAturanBisnis('GudangDalamPerjalanan', "Lokasi {$gudang->nama} khusus barang dalam perjalanan transfer dan tidak bisa dipilih.", $bidang);
        }

        return $gudang;
    }

    public function HariIni(?int $idOutlet): CarbonImmutable
    {
        $hari = $idOutlet === null ? $this->tanggalBisnis->Hitung(null) : $this->tanggalBisnis->HitungKalender($idOutlet);

        return CarbonImmutable::parse($hari->format('Y-m-d'));
    }

    public function PastikanBukanMasaDepan(CarbonImmutable $tanggal, ?int $idOutlet): void
    {
        if ($tanggal->format('Y-m-d') > $this->HariIni($idOutlet)->format('Y-m-d')) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal dokumen tidak boleh di masa depan.', 'Tanggal');
        }
    }
}
