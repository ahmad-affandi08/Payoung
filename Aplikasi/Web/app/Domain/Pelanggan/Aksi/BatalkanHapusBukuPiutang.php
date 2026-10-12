<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\HapusBukuPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan hapus-buku piutang (E8, izin `akuntansi.kelola`): dokumen asal tidak diubah (aturan #8), jurnalnya dibalik
 * pada tanggal bisnis hari ini, tagihan dikembalikan sebesar yang dihapus-buku, dan klaim `IdPiutangAktif` dilepas
 * sehingga piutangnya bisa dihapus-buku lagi atau dilunasi (pelanggan yang membayar belakangan). Idempoten. Audit
 * `piutang.hapus-buku.batalkan`.
 */
final class BatalkanHapusBukuPiutang
{
    public function __construct(
        private readonly BalikkanJurnal $balikkan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, HapusBukuTidakDitemukan
     */
    public function Jalankan(string $uuidHapusBuku, string $alasan, int $idPengguna): HapusBukuPiutang
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuidHapusBuku, $alasan, $idPengguna): HapusBukuPiutang {
            $dokumen = HapusBukuPiutang::query()->where('Uuid', $uuidHapusBuku)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('HapusBukuTidakDitemukan', 'Hapus-buku piutang tidak ditemukan.');

            if ($dokumen->Status === StatusDokumenTerposting::Dibatalkan) {
                return $dokumen;
            }

            $piutang = Piutang::query()->whereKey($dokumen->IdPiutang)->lockForUpdate()->firstOrFail();
            $jurnal = $dokumen->IdJurnal === null ? null : $this->balikkan->Jalankan(
                $dokumen->IdJurnal,
                $this->tanggalBisnis->Hitung(null),
                mb_substr("Pembatalan hapus-buku piutang {$dokumen->Nomor}", 0, 255),
                JenisSumberJurnal::HapusBukuPiutang,
                $dokumen->Id,
                'Pembatalan',
                $idPengguna,
            );

            $kurang = Uang::Dari($piutang->JumlahDikurangi)->Kurangi($dokumen->AmbilJumlah());
            $piutang->JumlahDikurangi = ($kurang->BernilaiNegatif() ? Uang::Nol() : $kurang)->KeString();
            $asal = $piutang->Status;
            $piutang->Status = StatusPiutang::BelumLunas;
            $piutang->SelaraskanStatus();
            $piutang->save();

            $dokumen->UbahStatus(StatusDokumenTerposting::Dibatalkan);
            $dokumen->IdPiutangAktif = null;
            $dokumen->IdJurnalPembatalan = $jurnal?->idJurnal;
            $dokumen->DibatalkanOleh = $idPengguna;
            $dokumen->DibatalkanPada = now();
            $dokumen->AlasanBatal = $alasan;
            $dokumen->DiubahOleh = $idPengguna;
            $dokumen->save();

            $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, $piutang->Status->value, $idPengguna, "Pembatalan hapus-buku: {$alasan}");
            $this->riwayat->Catat(HapusBukuPiutang::JENIS_DOKUMEN, $dokumen->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('piutang.hapus-buku.batalkan', $dokumen, ['Status' => StatusDokumenTerposting::Diposting->value], [
                'Status' => StatusDokumenTerposting::Dibatalkan->value,
                'Nomor' => $dokumen->Nomor,
                'Alasan' => $alasan,
                'NomorJurnalPembatalan' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $dokumen;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
