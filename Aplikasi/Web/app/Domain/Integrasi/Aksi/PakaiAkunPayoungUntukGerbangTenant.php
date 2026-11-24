<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\LingkunganGerbang;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDokuSnap;
use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Integrasi\Snap\KlienSnap;
use App\Domain\Tenant\Kueri\TenantBerpenanda;
use Illuminate\Support\Facades\DB;

/**
 * D-316: tenant uji (penanda Uji, Demo, atau Internal) memakai akun DOKU induk Payoung sebagai gerbang QRIS-nya, tanpa
 * mengisi kredensial sendiri. Perlu karena DOKU hanya menyimpan satu kunci publik per Client ID: mendaftarkan kunci tenant
 * di akun yang sama akan menimpa kunci tagihan langganan Payoung.
 *
 * Hanya untuk tenant berpenanda, dan ditegakkan lagi saat adaptor dibuat (`PembuatGerbangPembayaran`). Uang pelanggan
 * mendarat di akun Payoung, jadi tenant komersial tidak boleh memakainya. Gerbang kembali `BelumDiuji` dan nonaktif sampai
 * diuji ulang. Kredensial toko sebelumnya dihapus (tidak ada yang perlu disimpan untuk akun Payoung).
 */
final class PakaiAkunPayoungUntukGerbangTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly TenantBerpenanda $tenantBerpenanda,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(): GerbangPembayaranTenant
    {
        $idTenant = $this->konteks->Wajib();

        if (! $this->tenantBerpenanda->Cek($idTenant)) {
            throw new PelanggaranAturanBisnis('HanyaTenantUji', 'Akun DOKU Payoung hanya untuk tenant uji. Hubungkan akun DOKU toko Anda sendiri.', 'Umum', 403);
        }

        $klien = new KlienSnap;

        if (! $klien->CekSiap()) {
            throw new PelanggaranAturanBisnis('AkunPayoungBelumSiap', 'Akun DOKU Payoung belum diatur di konsol (Integrasi › Akun DOKU Payoung).', 'Umum', 409);
        }

        return DB::transaction(function () use ($idTenant, $klien): GerbangPembayaranTenant {
            $gerbang = GerbangPembayaranTenant::query()->lockForUpdate()->first() ?? new GerbangPembayaranTenant(['TokenWebhook' => SimpanGerbangPembayaranTenant::BuatToken($idTenant)]);
            $gerbang->fill([
                'Penyedia' => PenyediaGerbang::Doku,
                'Lingkungan' => $klien->CekSandbox() ? LingkunganGerbang::Sandbox : LingkunganGerbang::Produksi,
                'Pengaturan' => ['SumberAkun' => AdaptorDokuSnap::SUMBER_AKUN_PAYOUNG],
                'Kredensial' => [],
                'PetunjukKredensial' => [],
                'StatusUji' => StatusUjiGerbang::BelumDiuji,
                'PesanUji' => null,
                'DiujiPada' => null,
                'Aktif' => false,
            ])->save();
            $this->audit->Catat('gerbang-pembayaran.pakai-akun-payoung', $gerbang, nilaiBaru: ['SumberAkun' => AdaptorDokuSnap::SUMBER_AKUN_PAYOUNG]);

            return $gerbang;
        });
    }
}
