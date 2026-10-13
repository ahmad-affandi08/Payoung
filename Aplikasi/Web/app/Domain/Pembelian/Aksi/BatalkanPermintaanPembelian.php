<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan permintaan pembelian yang belum menjadi PO (Draf, Diajukan, atau Disetujui). Alasan wajib 5–255 karakter.
 * Idempoten untuk yang sudah dibatalkan. Audit `permintaan-pembelian.batalkan`.
 */
final class BatalkanPermintaanPembelian
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis StatusTidakSesuai, AlasanTidakValid
     */
    public function Jalankan(PermintaanPembelian $permintaan, int $idPengguna, ?string $alasan = null): PermintaanPembelian
    {
        $alasan = trim((string) $alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($permintaan, $idPengguna, $alasan): PermintaanPembelian {
            $terkunci = PermintaanPembelian::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === StatusPermintaanPembelian::Dibatalkan) {
                return $terkunci;
            }

            if (! $terkunci->Status->BisaBerubahKe(StatusPermintaanPembelian::Dibatalkan)) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan pembelian berstatus {$terkunci->Status->AmbilLabel()} tidak bisa dibatalkan.");
            }

            $asal = $terkunci->Status;
            $terkunci->UbahStatus(StatusPermintaanPembelian::Dibatalkan);
            $terkunci->fill(['AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now(), 'DiubahOleh' => $idPengguna])->save();

            $this->riwayat->Catat(PermintaanPembelian::JENIS_DOKUMEN, $terkunci->Id, $asal->value, StatusPermintaanPembelian::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('permintaan-pembelian.batalkan', $terkunci, ['Status' => $asal->value], ['Status' => StatusPermintaanPembelian::Dibatalkan->value, 'Alasan' => $alasan], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }
}
