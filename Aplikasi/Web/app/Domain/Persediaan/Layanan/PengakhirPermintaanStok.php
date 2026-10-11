<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use Illuminate\Support\Facades\DB;

/**
 * Akhir hidup permintaan stok yang dipakai tiga aksi (F-05b, D-105): dibatalkan peminta, ditolak pengirim, atau ditutup
 * (sisa tidak akan dikirim). Alasan wajib (min. 5 karakter), status asal dikunci dan diperiksa, riwayat status dan audit
 * tercatat. Tidak menyentuh stok atau jurnal.
 */
final class PengakhirPermintaanStok
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<StatusPermintaanStok>  $statusAsalBoleh
     */
    public function Akhiri(PermintaanStok $permintaan, StatusPermintaanStok $ke, array $statusAsalBoleh, string $alasan, int $idPengguna, string $kodeAudit): PermintaanStok
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasannya (minimal 5 karakter).', 'Alasan');
        }

        return DB::transaction(function () use ($permintaan, $ke, $statusAsalBoleh, $alasan, $idPengguna, $kodeAudit): PermintaanStok {
            $terkunci = PermintaanStok::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if (! in_array($terkunci->Status, $statusAsalBoleh, true)) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan berstatus {$terkunci->Status->AmbilLabel()} tidak bisa diubah ke {$ke->AmbilLabel()}.");
            }

            $dari = $terkunci->Status;
            $terkunci->UbahStatus($ke);
            $terkunci->fill(['Alasan' => mb_substr($alasan, 0, 255), 'DitutupOleh' => $idPengguna, 'DitutupPada' => now()]);
            $terkunci->save();

            $this->riwayat->Catat(PermintaanStok::JENIS_DOKUMEN, $terkunci->Id, $dari->value, $ke->value, $idPengguna, mb_substr($alasan, 0, 255));
            $this->audit->Catat($kodeAudit, $terkunci, ['Status' => $dari->value], ['Status' => $ke->value, 'Alasan' => $terkunci->Alasan], idPengguna: $idPengguna);

            return $terkunci;
        });
    }
}
