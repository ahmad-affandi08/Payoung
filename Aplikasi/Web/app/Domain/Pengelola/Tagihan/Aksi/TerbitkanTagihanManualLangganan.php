<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PenerbitTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Keuangan/Super Admin menerbitkan tagihan manual untuk paket harga negosiasi, mis. Enterprise (P-08, D-112, audit E4).
 * Paket itu tidak punya `HargaPaket`, jadi harga kesepakatan satu siklus (sebelum PPN) diisi pelaku; PPN, nomor, dan
 * jatuh tempo memakai penerbit yang sama dengan tagihan buatan Owner. Tagihan ditandai `Manual`: dibayar dengan transfer
 * (Owner mengunggah bukti, atau Keuangan mencatat pembayaran langsung), tidak lewat gerbang.
 *
 * Kunci Langganan dulu lalu tagihan (urutan yang sama dengan verifikasi pembayaran); hanya satu tagihan terbuka per
 * tenant (BR-P08.4).
 */
final class TerbitkanTagihanManualLangganan
{
    public function __construct(
        private readonly PenerbitTagihanLangganan $penerbit,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, Tenant $tenant, string $kodePaket, SiklusTagihan $siklus, string $subtotal, string $keterangan): TagihanLangganan
    {
        $harga = Uang::Dari($subtotal);

        return DB::transaction(function () use ($pelaku, $tenant, $kodePaket, $siklus, $harga, $keterangan): TagihanLangganan {
            $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Tenant ini belum punya langganan.');

            if ($langganan->Status === StatusLangganan::Berhenti) {
                throw new PelanggaranAturanBisnis('LanggananBerhenti', 'Langganan tenant ini sudah berhenti.');
            }

            if ($langganan->CekDitangguhkanManual()) {
                throw new PelanggaranAturanBisnis('BR-P07.4', 'Tenant ini ditangguhkan manual oleh Super Admin. Cabut penangguhan lebih dulu.');
            }

            $paket = Paket::query()->where('Kode', strtoupper(trim($kodePaket)))->first();

            if ($paket === null || ! $paket->HargaNegosiasi) {
                throw new PelanggaranAturanBisnis('PaketBukanNegosiasi', 'Tagihan manual hanya untuk paket harga negosiasi. Paket lain ditagih Owner sendiri lewat halaman Langganan.', 'KodePaket');
            }

            $terbuka = DaftarTagihanPlatform::KueriTagihan()->where('IdTenant', $tenant->Id)->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->first();

            if ($terbuka !== null) {
                throw new PelanggaranAturanBisnis('TagihanMasihTerbuka', "Masih ada tagihan {$terbuka->Nomor} yang belum dibayar. Bayar atau batalkan tagihan itu dulu.");
            }

            $berjalan = in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true);
            $jenis = $berjalan && $langganan->IdPaket === $paket->Id ? JenisTagihanLangganan::Perpanjangan : JenisTagihanLangganan::Aktivasi;
            $tagihan = $this->penerbit->TerbitkanManual($langganan, $paket, $jenis, $siklus, $harga, $keterangan, $pelaku->Id);

            $this->audit->Catat(
                'tagihan.manual.terbit',
                $tagihan,
                nilaiBaru: ['Nomor' => $tagihan->Nomor, 'Jenis' => $jenis->value, 'Paket' => $paket->Kode, 'Siklus' => $siklus->value, 'Subtotal' => $tagihan->Subtotal, 'Total' => $tagihan->Total],
                alasan: $keterangan,
                idPelaku: $pelaku->Id,
                idTenant: $tenant->Id,
            );

            return $tagihan;
        });
    }
}
