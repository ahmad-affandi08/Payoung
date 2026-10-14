<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\MutasiGiftCard;
use Carbon\CarbonImmutable;

/**
 * Menutup sisa saldo gift card (CRM-06, D-153) di transaksi pemanggil, dengan kartu yang sudah dikunci:
 * - `Hangus`: sisa saldo diakui sebagai pendapatan lain (kedaluwarsa atau dihanguskan): Dr Saldo Gift Card, Cr
 *   Pendapatan Lain.
 * - `Kembali`: penjualan kartu dibatalkan dan uangnya dikembalikan dari akun kas/bank: Dr Saldo Gift Card, Cr kas/bank.
 * Mutasi (−sisa) menjadi sumber jurnal (`JenisSumberJurnal::MutasiGiftCard`). Idempoten per kartu.
 */
final class PenutupSaldoGiftCard
{
    public function __construct(
        private readonly BukuGiftCard $buku,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenyediaAkunPeran $penyediaAkun,
    ) {}

    public function Tutup(
        GiftCard $kartu,
        JenisMutasiGiftCard $jenis,
        SumberMutasiGiftCard $sumber,
        CarbonImmutable $tanggal,
        string $keterangan,
        ?int $idPengguna,
        ?int $idAkunKasBank = null,
    ): MutasiGiftCard {
        $nilai = Uang::Dari($kartu->Saldo);
        $mutasi = $this->buku->Catat($kartu->Id, Uang::Nol()->Kurangi($nilai), $jenis, $sumber, $kartu->Id, null, $tanggal, $keterangan, $idPengguna, $idAkunKasBank);

        if ($mutasi->IdJurnal !== null || $nilai->Bandingkan(Uang::Nol()) <= 0) {
            return $mutasi;
        }

        $this->penyediaAkun->Pastikan(PeranAkun::SaldoGiftCard, '2-1550', 'Saldo Gift Card');
        $kredit = $jenis === JenisMutasiGiftCard::Kembali && $idAkunKasBank !== null
            ? new DataBarisJurnal(null, $idAkunKasBank, null, Uang::Nol(), $nilai, $keterangan)
            : DataBarisJurnal::Kredit(PeranAkun::PendapatanLain, $nilai, null, $keterangan);
        $label = $jenis === JenisMutasiGiftCard::Kembali ? 'Pengembalian uang gift card' : 'Gift card hangus';
        $mutasi->IdJurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::MutasiGiftCard,
            idSumber: $mutasi->Id,
            uuidSumber: $mutasi->Uuid,
            nomorSumber: null,
            tanggal: $tanggal,
            keterangan: mb_substr("{$label} ".KodeGiftCard::Samarkan($kartu->Kode), 0, 255),
            baris: [
                DataBarisJurnal::Debit(PeranAkun::SaldoGiftCard, $nilai, null, $keterangan),
                $kredit,
            ],
            idPengguna: $idPengguna,
        ))->idJurnal;
        $mutasi->save();

        return $mutasi;
    }
}
