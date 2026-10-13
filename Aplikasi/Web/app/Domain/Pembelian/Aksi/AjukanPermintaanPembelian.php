<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PenyetujuLain;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use Illuminate\Support\Facades\DB;

/**
 * Mengajukan draf permintaan pembelian (F-04 PR): menunggu persetujuan pemegang `pembelian.po.setujui` yang bukan
 * pembuatnya. D-38 four-eyes adaptif: bila pelaku Pemilik, atau tidak ada pemegang izin itu yang lain yang menjangkau
 * outlet lokasinya, permintaan langsung Disetujui dengan alasan di audit. Idempoten untuk yang sudah diajukan.
 * Audit `permintaan-pembelian.ajukan`.
 */
final class AjukanPermintaanPembelian
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly AksesPengguna $akses,
        private readonly PenyetujuLain $penyetujuLain,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis StatusTidakSesuai
     */
    public function Jalankan(PermintaanPembelian $permintaan, int $idPengguna): PermintaanPembelian
    {
        return DB::transaction(function () use ($permintaan, $idPengguna): PermintaanPembelian {
            $terkunci = PermintaanPembelian::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === StatusPermintaanPembelian::Diajukan || $terkunci->Status === StatusPermintaanPembelian::Disetujui) {
                return $terkunci;
            }

            if ($terkunci->Status !== StatusPermintaanPembelian::Draf) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan pembelian berstatus {$terkunci->Status->AmbilLabel()} tidak bisa diajukan.");
            }

            if ($terkunci->Detail()->count() === 0) {
                throw new PelanggaranAturanBisnis('BarisKosong', 'Permintaan belum punya barang.', 'Baris');
            }

            $langsung = $this->AmbilAlasanLangsung($terkunci, $idPengguna);
            $tujuan = $langsung === null ? StatusPermintaanPembelian::Diajukan : StatusPermintaanPembelian::Disetujui;
            $terkunci->UbahStatus($tujuan);
            $terkunci->fill([
                'AlasanDitolak' => null,
                'DiajukanOleh' => $idPengguna,
                'DiajukanPada' => now(),
                'DiputuskanOleh' => $langsung === null ? null : $idPengguna,
                'DiputuskanPada' => $langsung === null ? null : now(),
                'DiubahOleh' => $idPengguna,
            ])->save();

            $this->riwayat->Catat(PermintaanPembelian::JENIS_DOKUMEN, $terkunci->Id, StatusPermintaanPembelian::Draf->value, $tujuan->value, $idPengguna);
            $this->audit->Catat('permintaan-pembelian.ajukan', $terkunci, ['Status' => StatusPermintaanPembelian::Draf->value], [
                'Status' => $tujuan->value,
                ...($langsung === null ? [] : ['DisetujuiLangsung' => $langsung]),
            ], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }

    /** D-38: alasan disetujui tanpa penyetuju kedua, atau null bila four-eyes berlaku. */
    private function AmbilAlasanLangsung(PermintaanPembelian $permintaan, int $idPengguna): ?string
    {
        if (($this->akses->Ambil($permintaan->IdTenant, $idPengguna)['Pemilik'] ?? false) === true) {
            return 'Pemilik';
        }

        return $this->penyetujuLain->CekAda($permintaan->IdTenant, $idPengguna, IzinTenant::PembelianPoSetujui, $permintaan->IdOutlet) ? null : 'PenyetujuTunggal';
    }
}
