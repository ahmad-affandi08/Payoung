<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use App\Domain\Dukungan\Layanan\PenulisPesanTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Dukungan\Layanan\PemberiTahuEskalasiTiket;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\DB;

/**
 * Petugas dukungan menaikkan tiket ke L2 atau Teknis (P-09 "Eskalasi L1 → L2 → Teknis"). Hanya naik, hanya untuk tiket
 * yang masih terbuka, alasan wajib. Dicatat sebagai catatan internal (tidak terlihat tenant), diaudit, dan tim tujuan
 * diberi tahu lewat email. Eskalasi otomatis karena SLA lewat ada di [EskalasikanTiketLewatSla].
 */
final class EskalasikanTiketDukungan
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenulisPesanTiket $penulisPesan,
        private readonly PemberiTahuEskalasiTiket $pemberiTahu,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $uuidTiket, TingkatEskalasiTiket $tingkat, string $alasan): TiketDukungan
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new PelanggaranAturanBisnis('P-09', 'Isi alasan eskalasi.', 'Alasan');
        }

        $tiket = $this->konteks->JalankanLintasTenant("Mengeskalasi tiket dukungan {$uuidTiket}", fn (): TiketDukungan => DB::transaction(
            function () use ($pelaku, $uuidTiket, $tingkat, $alasan): TiketDukungan {
                $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->where('Uuid', $uuidTiket)->lockForUpdate()->firstOrFail();

                if (! $tiket->Status->CekTerbuka()) {
                    throw new PelanggaranAturanBisnis('P-09', 'Hanya tiket yang masih terbuka yang bisa dieskalasi.', 'Tingkat');
                }

                if (! $tingkat->CekLebihTinggiDari($tiket->TingkatEskalasi)) {
                    throw new PelanggaranAturanBisnis('P-09', "Tiket sudah berada di {$tiket->TingkatEskalasi->AmbilLabel()}. Eskalasi hanya bisa naik.", 'Tingkat');
                }

                $asal = $tiket->TingkatEskalasi;
                $tiket->TingkatEskalasi = $tingkat;
                $tiket->DieskalasiPada = now();
                $tiket->save();

                $this->penulisPesan->TulisSistem($tiket, "{$pelaku->Nama} mengeskalasi tiket dari {$asal->AmbilLabel()} ke {$tingkat->AmbilLabel()}: {$alasan}", catatanInternal: true);
                $this->audit->Catat(
                    'dukungan.tiket.eskalasi',
                    $tiket,
                    nilaiLama: ['TingkatEskalasi' => $asal->value],
                    nilaiBaru: ['TingkatEskalasi' => $tingkat->value],
                    alasan: $alasan,
                    idPelaku: $pelaku->Id,
                    idTenant: $tiket->IdTenant,
                );

                return $tiket;
            },
        ));

        $this->pemberiTahu->Beritahu($tiket, $tingkat, $alasan);

        return $tiket;
    }
}
