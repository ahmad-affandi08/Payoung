<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\KuponLanggananPemakaian;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Keuangan/Super Admin menghapus-buku tagihan yang sudah lewat jatuh tempo dan tidak akan tertagih
 * (`JatuhTempo → Dihapuskan`, P-08, D-112, audit E6). Nomor tagihan tetap tercatat (BR-P08.1); tagihannya tidak lagi
 * menghalangi tagihan baru dan tidak lagi dihitung sebagai piutang langganan. Pemakaian kupon dilepas seperti pada
 * pembatalan. Status langganan tidak diubah di sini: penjadwal tunggakan (BR-P08.10) sudah bekerja dari tanggal periode.
 *
 * Ditolak bila ada pembayaran `Menunggu` (uangnya mungkin sudah masuk), dan hanya untuk tagihan `JatuhTempo`:
 * tagihan yang belum jatuh tempo dibatalkan Owner, bukan dihapus-buku.
 */
final class HapusBukuTagihanLangganan
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $uuidTagihan, string $alasan): TagihanLangganan
    {
        $awal = DaftarTagihanPlatform::KueriTagihan()->where('Uuid', $uuidTagihan)->first()
            ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan tidak ditemukan.');

        return DB::transaction(function () use ($pelaku, $awal, $alasan): TagihanLangganan {
            Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first();
            $tagihan = DaftarTagihanPlatform::KueriTagihan()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            if ($tagihan->Status !== StatusTagihanLangganan::JatuhTempo) {
                throw new PelanggaranAturanBisnis('HanyaJatuhTempo', "Hanya tagihan yang sudah lewat jatuh tempo yang bisa dihapus-buku. Tagihan {$tagihan->Nomor} berstatus {$tagihan->Status->AmbilLabel()}.");
            }

            $menunggu = DaftarTagihanPlatform::KueriPembayaran()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Status', StatusPembayaranLangganan::Menunggu->value)
                ->exists();

            if ($menunggu) {
                throw new PelanggaranAturanBisnis('PembayaranMasihDiverifikasi', 'Tagihan ini punya pembayaran yang belum diputuskan. Terima atau tolak dulu sebelum menghapus-buku.');
            }

            $tagihan->update([
                'Status' => StatusTagihanLangganan::Dihapuskan,
                'DihapusBukuPada' => now(),
                'AlasanHapusBuku' => trim($alasan),
            ]);

            KuponLanggananPemakaian::query()
                ->where('IdTenant', $tagihan->IdTenant)
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->whereNull('DibatalkanPada')
                ->update(['DibatalkanPada' => now()]);

            $this->audit->Catat(
                'tagihan.hapus-buku',
                $tagihan,
                nilaiLama: ['Status' => StatusTagihanLangganan::JatuhTempo->value],
                nilaiBaru: ['Status' => StatusTagihanLangganan::Dihapuskan->value, 'Nomor' => $tagihan->Nomor, 'Total' => $tagihan->Total],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
                idTenant: $tagihan->IdTenant,
            );

            return $tagihan;
        });
    }
}
