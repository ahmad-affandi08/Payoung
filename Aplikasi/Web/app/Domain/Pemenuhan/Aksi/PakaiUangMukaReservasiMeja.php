<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;

/**
 * FnB reservasi meja + DP (D-145): penjualan kasir yang merujuk reservasi (`Penjualan.Buat` `UuidReservasiMeja`) memakai
 * uang muka (metode Uang Muka) dan menyelesaikan reservasi di transaksi yang sama. Jurnalnya dibuat penjualan (Dr Uang
 * Muka Pelanggan), bukan di sini. Reservasi yang belum ditandai datang dicatat Hadir lalu Selesai.
 *
 * Keadaan yang berubah setelah kasir menagih (bisa offline) **tidak menolak penjualan** (§18.3): reservasi tidak dikenal,
 * di outlet lain, sudah ditagih penjualan lain, sudah berakhir, atau uang muka dipakai melebihi sisa dikembalikan sebagai
 * masalah untuk tinjauan `UangMukaBermasalah`. Sisa DP yang tidak terpakai (tagihan lebih kecil dari DP) tetap sebagai
 * kewajiban sampai pengelola mengembalikan/menghanguskannya (butir Kotak Tindakan).
 */
final class PakaiUangMukaReservasiMeja
{
    public function __construct(private readonly PencatatRiwayatStatus $riwayat) {}

    /**
     * @return list<string> masalah untuk tinjauan penjualan
     */
    public function Jalankan(string $uuidReservasi, int $idPenjualan, int $idOutlet, Uang $uangMukaDipakai, ?int $idPengguna): array
    {
        $r = ReservasiMeja::query()->where('Uuid', $uuidReservasi)->lockForUpdate()->first();

        if ($r === null || $r->IdOutlet !== $idOutlet) {
            return ['reservasi meja tidak ditemukan di outlet ini'];
        }

        if ($r->IdPenjualan === $idPenjualan) {
            return [];
        }

        if ($r->IdPenjualan !== null) {
            return ["reservasi meja {$r->Nomor} sudah ditagihkan lewat penjualan lain"];
        }

        if (! $r->Status->CekMemakaiMeja()) {
            return ["reservasi meja {$r->Nomor} berstatus {$r->Status->AmbilLabel()}"];
        }

        $masalah = [];
        $sisa = $r->AmbilSisaUangMuka();

        if ($uangMukaDipakai->Bandingkan($sisa) > 0) {
            $masalah[] = "uang muka dipakai {$uangMukaDipakai->FormatRupiah()} melebihi sisa {$r->Nomor} {$sisa->FormatRupiah()}";
        }

        if ($r->Status !== StatusReservasiMeja::Hadir) {
            $dari = $r->Status;

            if (! $dari->BisaBerubahKe(StatusReservasiMeja::Hadir)) {
                return ["reservasi meja {$r->Nomor} berstatus {$dari->AmbilLabel()}"];
            }

            $r->UbahStatus(StatusReservasiMeja::Hadir);
            $r->HadirPada ??= now();
            $this->riwayat->Catat(ReservasiMeja::JENIS_DOKUMEN, $r->Id, $dari->value, StatusReservasiMeja::Hadir->value, $idPengguna);
        }

        $r->UbahStatus(StatusReservasiMeja::Selesai);
        $r->DpTerpakai = Uang::Dari($r->DpTerpakai)->Tambah($uangMukaDipakai)->KeString();
        $r->IdPenjualan = $idPenjualan;
        $r->save();
        $this->riwayat->Catat(ReservasiMeja::JENIS_DOKUMEN, $r->Id, StatusReservasiMeja::Hadir->value, StatusReservasiMeja::Selesai->value, $idPengguna, 'Dibayar di kasir');

        return $masalah;
    }
}
