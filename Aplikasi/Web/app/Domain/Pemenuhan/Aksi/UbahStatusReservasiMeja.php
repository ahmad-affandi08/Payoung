<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pemenuhan\Enum\CaraSelesaiUangMukaMeja;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Illuminate\Support\Facades\DB;

/**
 * FnB reservasi meja (D-145): pindah status. Aturan:
 * - Hanya transisi `StatusReservasiMeja::BisaBerubahKe`; **Sudah datang** butuh meja sudah dipilih; **Tidak datang**
 *   hanya setelah jam mulai lewat; **Dibatalkan** butuh alasan 5–255 karakter.
 * - Reservasi yang berakhir (dibatalkan, tidak datang, atau selesai tanpa dibayar lewat kasir) dengan **sisa uang
 *   muka** wajib menyebut nasibnya (`Dikembalikan` + akun kas/bank, atau `Hangus` + alasan) dan diselesaikan di
 *   transaksi yang sama, sehingga tidak ada DP yang menggantung di kewajiban.
 * Status dicatat di `RiwayatStatusDokumen`; audit `reservasi-meja.status`.
 */
final class UbahStatusReservasiMeja
{
    public function __construct(
        private readonly SelesaikanUangMukaReservasiMeja $selesaikanDp,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(ReservasiMeja $reservasi, StatusReservasiMeja $tujuan, int $idPengguna, ?string $alasan = null, ?CaraSelesaiUangMukaMeja $caraDp = null, ?string $uuidAkun = null, ?string $alasanDp = null): ReservasiMeja
    {
        $alasan = $alasan === null || trim($alasan) === '' ? null : trim($alasan);

        if ($tujuan === StatusReservasiMeja::Dibatalkan && ($alasan === null || mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255)) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan pembatalan 5–255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($reservasi, $tujuan, $idPengguna, $alasan, $caraDp, $uuidAkun, $alasanDp): ReservasiMeja {
            $r = ReservasiMeja::query()->whereKey($reservasi->Id)->lockForUpdate()->firstOrFail();
            $asal = $r->Status;

            if (! $asal->BisaBerubahKe($tujuan)) {
                throw new PelanggaranAturanBisnis('StatusTidakValid', "Reservasi {$r->Nomor} berstatus {$asal->AmbilLabel()} dan tidak bisa diubah menjadi {$tujuan->AmbilLabel()}.", 'Status');
            }

            if ($tujuan === StatusReservasiMeja::Hadir && $r->IdMeja === null) {
                throw new PelanggaranAturanBisnis('MejaBelumDipilih', "Pilih meja untuk reservasi {$r->Nomor} sebelum menandai tamu sudah datang.", 'UuidMeja');
            }

            if ($tujuan === StatusReservasiMeja::TidakDatang && $r->MulaiPada->isFuture()) {
                throw new PelanggaranAturanBisnis('BelumWaktunya', 'Tidak datang baru bisa dicatat setelah jam reservasi lewat.', 'Status');
            }

            $akhir = in_array($tujuan, [StatusReservasiMeja::Dibatalkan, StatusReservasiMeja::TidakDatang, StatusReservasiMeja::Selesai], true);
            $sisa = $r->AmbilSisaUangMuka();

            if ($akhir && $sisa->Bandingkan(Uang::Nol()) > 0 && $caraDp === null) {
                throw new PelanggaranAturanBisnis('NasibUangMukaWajib', "Reservasi {$r->Nomor} masih memegang uang muka {$sisa->FormatRupiah()}. Pilih: kembalikan ke tamu atau hanguskan.", 'CaraDp');
            }

            $r->UbahStatus($tujuan);

            if ($tujuan === StatusReservasiMeja::Hadir) {
                $r->HadirPada = now();
            }

            if ($tujuan === StatusReservasiMeja::Dibatalkan) {
                $r->AlasanBatal = $alasan;
            }

            $r->save();
            $this->riwayat->Catat(ReservasiMeja::JENIS_DOKUMEN, $r->Id, $asal->value, $tujuan->value, $idPengguna, $alasan);
            $this->audit->Catat('reservasi-meja.status', $r, ['Status' => $asal->value], ['Status' => $tujuan->value, 'Alasan' => $alasan], idPengguna: $idPengguna);

            if ($akhir && $caraDp !== null && $sisa->Bandingkan(Uang::Nol()) > 0) {
                $r = $this->selesaikanDp->Jalankan($r, $caraDp, $uuidAkun, $alasanDp ?? ($alasan ?? "Reservasi {$tujuan->AmbilLabel()}"), $idPengguna);
            }

            return $r;
        });
    }
}
