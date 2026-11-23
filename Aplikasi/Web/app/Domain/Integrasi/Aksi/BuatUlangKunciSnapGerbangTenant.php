<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use Illuminate\Support\Facades\DB;

/**
 * D-314: membuat ulang pasangan kunci RSA SNAP gerbang DOKU milik tenant (kunci lama hilang atau salah didaftarkan di
 * dasbor DOKU). Kunci publik baru harus didaftarkan lagi, jadi gerbang kembali `BelumDiuji` dan nonaktif sampai diuji
 * ulang. Audit hanya memuat sidik jari kunci publik, tidak pernah kuncinya.
 */
final class BuatUlangKunciSnapGerbangTenant
{
    public function __construct(
        private readonly PembuatKunciSnap $pembuatKunci,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(): GerbangPembayaranTenant
    {
        return DB::transaction(function (): GerbangPembayaranTenant {
            $gerbang = GerbangPembayaranTenant::query()->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('GerbangBelumDiatur', 'Simpan gerbang pembayaran dulu.');

            if ($gerbang->Penyedia !== PenyediaGerbang::Doku) {
                throw new PelanggaranAturanBisnis('PenyediaTanpaKunciSnap', 'Penyedia ini tidak memakai kunci SNAP.');
            }

            $pasangan = $this->pembuatKunci->Buat();
            $gerbang->fill([
                'Kredensial' => [...$gerbang->Kredensial, SimpanGerbangPembayaranTenant::KUNCI_PRIBADI => $pasangan['Pribadi']],
                'Pengaturan' => [...$gerbang->Pengaturan, SimpanGerbangPembayaranTenant::KUNCI_PUBLIK => $pasangan['Publik']],
                'StatusUji' => StatusUjiGerbang::BelumDiuji,
                'PesanUji' => null,
                'DiujiPada' => null,
                'Aktif' => false,
            ])->save();
            $this->audit->Catat('gerbang-pembayaran.kunci-snap-baru', $gerbang, nilaiBaru: [
                'SidikJariKunciPublik' => PembuatKunciSnap::BuatSidikJari($pasangan['Publik']),
            ]);

            return $gerbang;
        });
    }
}
