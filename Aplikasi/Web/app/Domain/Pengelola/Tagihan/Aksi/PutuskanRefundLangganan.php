<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Layanan\PelaksanaRefundLangganan;
use App\Domain\Pengelola\Tagihan\Layanan\PemberitahuRefundLangganan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\RefundLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin memutuskan refund di atas batas yang diajukan Keuangan (P-08, BR-P08.2, D-112). Setuju melaksanakan
 * akibatnya lewat `PelaksanaRefundLangganan`; tolak mencatat alasan dan membiarkan tagihan tetap Lunas. Keduanya final.
 */
final class PutuskanRefundLangganan
{
    public function __construct(
        private readonly PelaksanaRefundLangganan $pelaksana,
        private readonly PemberitahuRefundLangganan $pemberitahu,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Setujui(PenggunaPengelola $pemutus, string $uuidRefund): RefundLangganan
    {
        $awal = $this->Cari($pemutus, $uuidRefund);

        [$refund, $tagihan] = DB::transaction(function () use ($pemutus, $awal): array {
            $langganan = Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Langganan tenant ini tidak ditemukan.');
            $tagihan = DaftarTagihanPlatform::KueriTagihan()->with('Paket')->whereKey($awal->IdTagihanLangganan)->lockForUpdate()->firstOrFail();
            $refund = DaftarTagihanPlatform::KueriRefund()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();
            $this->PastikanMenunggu($refund);

            if ($tagihan->Status !== StatusTagihanLangganan::Lunas) {
                throw new PelanggaranAturanBisnis('HanyaTagihanLunas', "Tagihan {$tagihan->Nomor} sudah {$tagihan->Status->AmbilLabel()}, refund tidak bisa dilaksanakan.");
            }

            $diakhiri = $this->pelaksana->Laksanakan($refund, $tagihan, $langganan, $pemutus, CarbonImmutable::now());
            $this->audit->Catat(
                'tagihan.refund.setuju',
                $refund,
                nilaiLama: ['Refund' => StatusRefundLangganan::Diajukan->value, 'Tagihan' => StatusTagihanLangganan::Lunas->value],
                nilaiBaru: ['Refund' => StatusRefundLangganan::Disetujui->value, 'Tagihan' => StatusTagihanLangganan::Dikembalikan->value, 'Nomor' => $tagihan->Nomor, 'Jumlah' => $refund->Jumlah, 'LayananDiakhiri' => $diakhiri],
                alasan: $refund->Alasan,
                idPelaku: $pemutus->Id,
                idTenant: $tagihan->IdTenant,
            );

            return [$refund, $tagihan];
        });

        $this->pemberitahu->Kirim($tagihan, $refund);

        return $refund;
    }

    public function Tolak(PenggunaPengelola $pemutus, string $uuidRefund, string $alasanTolak): RefundLangganan
    {
        $awal = $this->Cari($pemutus, $uuidRefund);

        return DB::transaction(function () use ($pemutus, $awal, $alasanTolak): RefundLangganan {
            Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first();
            DaftarTagihanPlatform::KueriTagihan()->whereKey($awal->IdTagihanLangganan)->lockForUpdate()->firstOrFail();
            $refund = DaftarTagihanPlatform::KueriRefund()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();
            $this->PastikanMenunggu($refund);

            $refund->update([
                'Status' => StatusRefundLangganan::Ditolak,
                'IdPenggunaPengelolaPemutus' => $pemutus->Id,
                'DiputuskanPada' => CarbonImmutable::now(),
                'AlasanTolak' => trim($alasanTolak),
            ]);
            $this->audit->Catat(
                'tagihan.refund.tolak',
                $refund,
                nilaiLama: ['Refund' => StatusRefundLangganan::Diajukan->value],
                nilaiBaru: ['Refund' => StatusRefundLangganan::Ditolak->value, 'Jumlah' => $refund->Jumlah],
                alasan: trim($alasanTolak),
                idPelaku: $pemutus->Id,
                idTenant: $refund->IdTenant,
            );

            return $refund;
        });
    }

    private function Cari(PenggunaPengelola $pemutus, string $uuid): RefundLangganan
    {
        if (! $pemutus->PunyaIzin(IzinPengelola::TagihanRefundSetujui)) {
            throw new PelanggaranAturanBisnis('BR-P08.2', 'Refund di atas batas hanya bisa diputuskan Super Admin.');
        }

        return DaftarTagihanPlatform::KueriRefund()->where('Uuid', $uuid)->first()
            ?? throw new PelanggaranAturanBisnis('RefundTidakDitemukan', 'Pengajuan refund tidak ditemukan.');
    }

    private function PastikanMenunggu(RefundLangganan $refund): void
    {
        if ($refund->Status !== StatusRefundLangganan::Diajukan) {
            throw new PelanggaranAturanBisnis('RefundSudahDiputuskan', "Pengajuan refund ini sudah {$refund->Status->AmbilLabel()}.");
        }
    }
}
