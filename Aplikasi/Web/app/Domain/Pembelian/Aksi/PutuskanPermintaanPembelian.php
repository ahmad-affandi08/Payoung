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
 * Menyetujui atau menolak permintaan pembelian yang diajukan (F-04 PR, izin `pembelian.po.setujui`). Pembuat dan
 * pengaju tidak bisa memutuskan permintaannya sendiri. Penolakan wajib beralasan 5–255 karakter dan mengembalikan
 * permintaan ke Draf supaya bisa direvisi. Audit `permintaan-pembelian.setujui`/`.tolak`.
 */
final class PutuskanPermintaanPembelian
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis StatusTidakSesuai, PenyetujuPembuat, AlasanTidakValid
     */
    public function Jalankan(PermintaanPembelian $permintaan, int $idPengguna, bool $setujui = true, ?string $alasan = null): PermintaanPembelian
    {
        $alasan = trim((string) $alasan);

        if (! $setujui && (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255)) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan penolakan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($permintaan, $idPengguna, $setujui, $alasan): PermintaanPembelian {
            $terkunci = PermintaanPembelian::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status !== StatusPermintaanPembelian::Diajukan) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan pembelian berstatus {$terkunci->Status->AmbilLabel()} tidak sedang menunggu persetujuan.");
            }

            if ($terkunci->DibuatOleh === $idPengguna || $terkunci->DiajukanOleh === $idPengguna) {
                throw new PelanggaranAturanBisnis('PenyetujuPembuat', 'Permintaan pembelian tidak bisa disetujui atau ditolak oleh pembuat atau pengajunya. Minta pemegang izin persetujuan lain.');
            }

            $tujuan = $setujui ? StatusPermintaanPembelian::Disetujui : StatusPermintaanPembelian::Draf;
            $terkunci->UbahStatus($tujuan);
            $terkunci->fill($setujui
                ? ['DiputuskanOleh' => $idPengguna, 'DiputuskanPada' => now(), 'DiubahOleh' => $idPengguna]
                : ['AlasanDitolak' => $alasan, 'DiajukanOleh' => null, 'DiajukanPada' => null, 'DiubahOleh' => $idPengguna])->save();

            $this->riwayat->Catat(PermintaanPembelian::JENIS_DOKUMEN, $terkunci->Id, StatusPermintaanPembelian::Diajukan->value, $tujuan->value, $idPengguna, $setujui ? null : $alasan);
            $this->audit->Catat($setujui ? 'permintaan-pembelian.setujui' : 'permintaan-pembelian.tolak', $terkunci, ['Status' => StatusPermintaanPembelian::Diajukan->value], [
                'Status' => $tujuan->value,
                'Alasan' => $setujui ? null : $alasan,
            ], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }
}
