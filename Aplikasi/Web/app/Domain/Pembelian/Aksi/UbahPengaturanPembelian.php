<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Aksi\UbahPengaturanPembelianTenant;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah pengaturan pembelian tenant (F-04 fase 1): `BatasPersetujuanPo` (Rp 0 – Rp 1 triliun; 0 = setiap PO butuh
 * persetujuan) dan `ToleransiPenerimaanPersen` (0–100%, BR-04.1). Berlaku untuk pengajuan PO & penerimaan berikutnya.
 * Tanpa perubahan = tidak ada yang ditulis. Audit `pembelian.pengaturan.ubah`.
 */
final class UbahPengaturanPembelian
{
    private const BATAS_MAKSIMAL = '1000000000000.00';

    public function __construct(
        private readonly PengaturanPembelianTenant $pengaturan,
        private readonly UbahPengaturanPembelianTenant $ubahTenant,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis BatasPersetujuanTidakValid, ToleransiTidakValid, SumberDrafTidakValid, CakupanDrafTidakValid
     */
    public function Jalankan(DataPengaturanPembelian $baru, int $idPengguna): void
    {
        if ($baru->batasPersetujuanPo->BernilaiNegatif() || $baru->batasPersetujuanPo->Bandingkan(Uang::Dari(self::BATAS_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('BatasPersetujuanTidakValid', 'Batas persetujuan PO harus antara Rp 0 dan Rp 1 triliun.', 'BatasPersetujuanPo');
        }

        if ($baru->toleransiPenerimaanPersen->isNegative() || $baru->toleransiPenerimaanPersen->isGreaterThan(DataPengaturanPembelian::TOLERANSI_PENERIMAAN_MAKSIMAL)) {
            throw new PelanggaranAturanBisnis('ToleransiTidakValid', 'Toleransi penerimaan harus antara 0% dan 100%.', 'ToleransiPenerimaanPersen');
        }

        if (! in_array($baru->sumberDrafPo, [DataPengaturanPembelian::SUMBER_DRAF_MINIMUM, DataPengaturanPembelian::SUMBER_DRAF_PERKIRAAN], true)) {
            throw new PelanggaranAturanBisnis('SumberDrafTidakValid', 'Sumber draf PO harus stok minimum atau perkiraan pemakaian.', 'SumberDrafPo');
        }

        if (! in_array($baru->hariCakupanDrafPo, DataPengaturanPembelian::HARI_CAKUPAN_DRAF_OPSI, true)) {
            throw new PelanggaranAturanBisnis('CakupanDrafTidakValid', 'Cakupan draf PO harus 7, 14, atau 30 hari.', 'HariCakupanDrafPo');
        }

        DB::transaction(function () use ($baru, $idPengguna): void {
            $lama = $this->pengaturan->Ambil();
            $nilaiLama = ['BatasPersetujuanPo' => $lama->batasPersetujuanPo->KeString(), 'ToleransiPenerimaanPersen' => (string) $lama->toleransiPenerimaanPersen, 'DrafPoOtomatis' => $lama->drafPoOtomatis, 'SumberDrafPo' => $lama->sumberDrafPo, 'HariCakupanDrafPo' => $lama->hariCakupanDrafPo];
            $nilaiBaru = ['BatasPersetujuanPo' => $baru->batasPersetujuanPo->KeString(), 'ToleransiPenerimaanPersen' => (string) $baru->toleransiPenerimaanPersen, 'DrafPoOtomatis' => $baru->drafPoOtomatis, 'SumberDrafPo' => $baru->sumberDrafPo, 'HariCakupanDrafPo' => $baru->hariCakupanDrafPo];

            if ($nilaiLama === $nilaiBaru) {
                return;
            }

            $this->ubahTenant->Jalankan($baru);
            $this->audit->Catat('pembelian.pengaturan.ubah', nilaiLama: $nilaiLama, nilaiBaru: $nilaiBaru, idPengguna: $idPengguna);
        });
    }
}
