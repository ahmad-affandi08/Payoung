<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\PengabarStok;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;

/**
 * OWN-03 / PLT-08, D-187: kabar kiriman stok yang tak kunjung diterima. Transfer yang masih dalam perjalanan atau baru
 * diterima sebagian sejak `persediaan.TransferMacet.HariPeringatan` hari (bawaan 3) mengabari manajer outlet **tujuan**;
 * sejak `HariLanjut` hari (bawaan 7) pengirim dan manajer outlet **asal** ikut dikabari karena barangnya patut dicari.
 * Satu kabar per transfer per tingkat (`H3`, `H7`); tingkat yang berlaku hanya yang tertinggi. Transfer dari/ke outlet
 * kanvas tidak dihitung. Dijalankan penjadwal bersama notifikasi operasional (`tindakan:buat-notifikasi-operasional`).
 */
final class BeritahuTransferStokMacet
{
    /** Batas transfer yang dibaca per jalan; sisanya dibaca pada jalan berikutnya. */
    public const BATAS_TRANSFER = 200;

    public function __construct(
        private readonly PengabarStok $pengabar,
        private readonly InfoGudang $infoGudang,
    ) {}

    /**
     * @return int jumlah penerima yang diproses (kabar yang sudah ada tidak menggandakan)
     */
    public function Jalankan(int $idTenant, CarbonImmutable $sekarang): int
    {
        $hariPeringatan = max(1, (int) config('persediaan.TransferMacet.HariPeringatan', 3));
        $hariLanjut = max($hariPeringatan, (int) config('persediaan.TransferMacet.HariLanjut', 7));

        return (int) $this->pengabar->DalamTenant($idTenant, function () use ($idTenant, $sekarang, $hariPeringatan, $hariLanjut): int {
            $jumlah = 0;
            $transfer = TransferStok::query()
                ->whereIn('Status', [StatusTransferStok::Dikirim->value, StatusTransferStok::DiterimaSebagian->value])
                ->where('DikirimPada', '<=', $sekarang->subDays($hariPeringatan))
                ->orderBy('DikirimPada')
                ->limit(self::BATAS_TRANSFER)
                ->get();

            foreach ($transfer as $t) {
                if ($t->DikirimPada === null || $this->pengabar->CekMelibatkanKanvas($t->IdOutletAsal, $t->IdOutletTujuan)) {
                    continue;
                }

                $hari = (int) $t->DikirimPada->diffInDays($sekarang);
                $lanjut = $hari >= $hariLanjut;
                $gudang = $this->infoGudang->AmbilBanyak([$t->IdGudangAsal, $t->IdGudangTujuan]);
                $asal = $gudang[$t->IdGudangAsal]->nama ?? 'gudang asal';
                $tujuan = $gudang[$t->IdGudangTujuan]->nama ?? 'gudang tujuan';
                $sebagian = $t->Status === StatusTransferStok::DiterimaSebagian;
                $isi = "{$t->Nomor} dari {$asal} ke {$tujuan} ".($sebagian ? 'baru diterima sebagian' : 'belum diterima')." sudah {$hari} hari. "
                    .($lanjut ? 'Periksa apakah barangnya sudah tiba atau perlu dicari.' : 'Periksa barangnya, lalu terima atau tutup transfernya.');
                $href = "/kelola/persediaan/transfer/{$t->Uuid}";
                $kunci = "TransferStokMacet:{$t->Uuid}:".($lanjut ? 'H'.$hariLanjut : 'H'.$hariPeringatan);

                $jumlah += $this->pengabar->Kabari($idTenant, $t->IdOutletTujuan, [], null, JenisNotifikasiPengguna::TransferStokMacet, $kunci, "Kiriman stok belum diterima {$hari} hari", $isi, $href);

                if ($lanjut) {
                    $jumlah += $this->pengabar->Kabari($idTenant, $t->IdOutletAsal, [$t->DikirimOleh], null, JenisNotifikasiPengguna::TransferStokMacet, $kunci, "Kiriman stok belum diterima {$hari} hari", $isi, $href);
                }
            }

            return $jumlah;
        });
    }
}
