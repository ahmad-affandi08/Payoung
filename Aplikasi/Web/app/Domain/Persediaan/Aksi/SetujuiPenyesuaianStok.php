<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Layanan\PemostingPenyesuaianStok;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menyetujui penyesuaian stok yang menunggu persetujuan (F-05b, §19.2) lalu mencatatnya (mutasi + jurnal) dalam satu
 * transaksi. Penyetuju ber-izin `persediaan.penyesuaian.setujui` (rute) dan bukan pembuat atau pengaju dokumen
 * (`PenyetujuTidakBoleh`). Idempoten: dokumen yang sudah dicatat dikembalikan apa adanya. Audit
 * `penyesuaian-stok.setujui`.
 */
final class SetujuiPenyesuaianStok
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly PemostingPenyesuaianStok $pemosting,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(PenyesuaianStok $dokumen, int $idPengguna): PenyesuaianStok
    {
        return DB::transaction(function () use ($dokumen, $idPengguna): PenyesuaianStok {
            $this->pengaturan->AmbilDenganKunciBaca();
            $dokumen = PenyesuaianStok::query()->whereKey($dokumen->Id)->lockForUpdate()->firstOrFail();

            if ($dokumen->Status === StatusPenyesuaianStok::Diposting) {
                return $dokumen;
            }

            if ($dokumen->Status !== StatusPenyesuaianStok::MenungguPersetujuan) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Penyesuaian berstatus {$dokumen->Status->AmbilLabel()} tidak sedang menunggu persetujuan.");
            }

            if ($idPengguna === $dokumen->DibuatOleh || $idPengguna === $dokumen->DiajukanOleh) {
                throw new PelanggaranAturanBisnis('PenyetujuTidakBoleh', 'Penyesuaian ini harus disetujui orang lain, bukan pembuat atau pengajunya.');
            }

            [$gudang, $produk, $detail] = $this->pemosting->Siapkan($dokumen, $this->pengunciSaldo);
            $dokumen->fill(['DisetujuiOleh' => $idPengguna, 'DisetujuiPada' => now()]);
            $jurnal = $this->pemosting->Posting($dokumen, $gudang, $produk, $detail, $idPengguna);
            $this->riwayat->Catat(PenyesuaianStok::JENIS_DOKUMEN, $dokumen->Id, StatusPenyesuaianStok::MenungguPersetujuan->value, StatusPenyesuaianStok::Diposting->value, $idPengguna);
            $this->audit->Catat('penyesuaian-stok.setujui', $dokumen, ['Status' => StatusPenyesuaianStok::MenungguPersetujuan->value], [
                'Status' => StatusPenyesuaianStok::Diposting->value,
                'Nomor' => $dokumen->Nomor,
                'NilaiPerkiraan' => $dokumen->NilaiPerkiraan,
                'TotalNilaiMasuk' => $dokumen->TotalNilaiMasuk,
                'TotalNilaiKeluar' => $dokumen->TotalNilaiKeluar,
                'NomorJurnal' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $dokumen;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
