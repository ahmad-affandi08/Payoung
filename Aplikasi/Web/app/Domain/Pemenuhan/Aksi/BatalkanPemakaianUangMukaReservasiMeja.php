<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;

/**
 * FnB reservasi meja + DP (D-145): void penjualan yang menagih reservasi mengembalikan uang muka yang dipakainya menjadi
 * sisa reservasi (jurnal penjualan dibalik oleh void: Cr Uang Muka Pelanggan kembali) dan membuka reservasi lagi
 * (Selesai → Hadir) supaya bisa ditagihkan ulang atau diselesaikan DP-nya dari back-office.
 */
final class BatalkanPemakaianUangMukaReservasiMeja
{
    // `$uangMukaDipakai` dihitung pemanggil (domain Penjualan) dari baris pembayaran Uang Muka penjualan yang di-void.
    public function __construct(private readonly PencatatRiwayatStatus $riwayat) {}

    public function Jalankan(int $idPenjualan, Uang $uangMukaDipakai, ?int $idPengguna): void
    {
        $r = ReservasiMeja::query()->where('IdPenjualan', $idPenjualan)->lockForUpdate()->first();

        if (! $r instanceof ReservasiMeja) {
            return;
        }

        $asal = $r->Status;
        $r->DpTerpakai = Uang::Dari($r->DpTerpakai)->Kurangi($uangMukaDipakai)->KeString();
        $r->IdPenjualan = null;

        if ($asal === StatusReservasiMeja::Selesai) {
            $r->forceFill(['Status' => StatusReservasiMeja::Hadir]);
        }

        $r->save();

        if ($asal !== $r->Status) {
            $this->riwayat->Catat(ReservasiMeja::JENIS_DOKUMEN, $r->Id, $asal->value, $r->Status->value, $idPengguna, 'Penjualan penagihan di-void');
        }
    }
}
