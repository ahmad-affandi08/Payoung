<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\TransferStok;

/**
 * Mencatat bahwa transfer yang berkait dengan permintaan stok sudah dikirim (F-05b, D-105). Dipanggil `KirimTransferStok`
 * di transaksi yang sama: jumlah dikirim per produk ditambahkan ke baris permintaan; semua baris terpenuhi = Selesai,
 * sebagian = DiprosesSebagian. Permintaan yang sudah tidak terbuka (dibatalkan, ditolak, ditutup, atau selesai) menolak
 * pengiriman baru supaya transfer tidak menjawab permintaan yang sudah berakhir. Produk di luar permintaan boleh ikut
 * dikirim tetapi tidak dihitung. Tidak mengubah stok atau jurnal.
 */
final class CatatPemenuhanPermintaanStok
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(TransferStok $transfer, int $idPengguna): void
    {
        if ($transfer->IdPermintaanStok === null) {
            return;
        }

        $permintaan = PermintaanStok::query()->whereKey($transfer->IdPermintaanStok)->lockForUpdate()->firstOrFail();

        if (! $permintaan->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis('PermintaanSudahBerakhir', "Permintaan {$permintaan->Nomor} berstatus {$permintaan->Status->AmbilLabel()} dan tidak menerima pengiriman lagi. Buat transfer biasa tanpa menautkan permintaan.");
        }

        $kirim = [];

        foreach ($transfer->Detail()->get() as $d) {
            $kirim[$d->IdProduk] = ($kirim[$d->IdProduk] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($d->JumlahDikirim));
        }

        $lengkap = true;

        foreach ($permintaan->Detail()->orderBy('Urutan')->lockForUpdate()->get() as $baris) {
            if (isset($kirim[$baris->IdProduk])) {
                $baris->JumlahDikirim = Kuantitas::Dari($baris->JumlahDikirim)->Tambah($kirim[$baris->IdProduk])->KeString();
                $baris->save();
            }

            $lengkap = $lengkap && $baris->AmbilSisa()->KeDesimal()->isZero();
        }

        $dari = $permintaan->Status;
        $ke = $lengkap ? StatusPermintaanStok::Selesai : StatusPermintaanStok::DiprosesSebagian;

        if ($ke !== $dari) {
            $permintaan->UbahStatus($ke);
            $permintaan->save();
            $this->riwayat->Catat(PermintaanStok::JENIS_DOKUMEN, $permintaan->Id, $dari->value, $ke->value, $idPengguna, "Transfer {$transfer->Nomor} dikirim");
        }

        $this->audit->Catat('permintaan-stok.pemenuhan', $permintaan, ['Status' => $dari->value], ['Status' => $ke->value, 'Transfer' => $transfer->Nomor], idPengguna: $idPengguna);
    }
}
