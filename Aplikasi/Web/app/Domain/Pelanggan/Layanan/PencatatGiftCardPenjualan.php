<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use Carbon\CarbonImmutable;

/**
 * Layanan publik domain Pelanggan untuk domain Penjualan (CRM-06 gift card): mutasi kartu dicatat di transaksi DB yang
 * sama dengan penjualannya, idempoten per penjualan.
 * - Pemakaian (−) saat penjualan dibayar gift card diterima: penjualan sudah terjadi di kasir, jadi kartu tetap
 *   dipotong walau saldonya kurang, hangus, atau lewat masa berlaku (dua perangkat memakai kartu yang sama; kasir
 *   offline); alasannya dikembalikan sebagai tinjauan. Kode yang tidak dikenal tidak bisa dipotong sama sekali.
 * - Void mengembalikan pemakaian (`BatalPakai` +).
 */
final class PencatatGiftCardPenjualan
{
    public function __construct(private readonly BukuGiftCard $buku) {}

    /**
     * Potong gift card untuk penjualan. Hasil: daftar masalah untuk tinjauan (kosong = kartu sah dan saldo cukup).
     *
     * @return list<string>
     */
    public function CatatPemakaian(?string $kode, int $idPenjualan, string $nomor, Uang $jumlah, CarbonImmutable $tanggal, int $idPengguna): array
    {
        if ($this->buku->CariMutasi(JenisMutasiGiftCard::Pakai, SumberMutasiGiftCard::Penjualan, $idPenjualan) !== null) {
            return [];
        }

        $normal = $kode === null ? '' : KodeGiftCard::Normalisasi($kode);
        $kartu = $normal === '' ? null : GiftCard::query()->where('Kode', $normal)->first();

        if ($kartu === null) {
            return ['kode gift card tidak dikenal server, saldo kartu tidak dipotong'];
        }

        $kartu = $this->buku->AmbilKartuTerkunci($kartu->Id);
        $masalah = [];
        $saldo = Uang::Dari($kartu->Saldo);

        if ($kartu->Status === StatusGiftCard::Hangus || $kartu->Status === StatusGiftCard::Dibatalkan) {
            $masalah[] = 'kartu '.KodeGiftCard::Samarkan($kartu->Kode).' berstatus '.$kartu->Status->AmbilLabel();
        } elseif ($kartu->BerlakuSampai !== null && $kartu->BerlakuSampai->toDateString() < $tanggal->toDateString()) {
            $masalah[] = 'kartu '.KodeGiftCard::Samarkan($kartu->Kode).' sudah lewat masa berlaku '.$kartu->BerlakuSampai->toDateString();
        }

        if ($saldo->Bandingkan($jumlah) < 0) {
            $masalah[] = "saldo kartu {$saldo->FormatRupiah()} kurang dari pembayaran {$jumlah->FormatRupiah()}, saldo menjadi minus";
        }

        $this->buku->Catat($kartu->Id, Uang::Nol()->Kurangi($jumlah), JenisMutasiGiftCard::Pakai, SumberMutasiGiftCard::Penjualan, $idPenjualan, $nomor, $tanggal, idPengguna: $idPengguna);

        return $masalah;
    }

    /** Void: pemakaian gift card penjualan itu dikembalikan (idempoten). Hasil: jumlah yang dikembalikan. */
    public function BatalkanPemakaian(int $idPenjualan, string $nomor, CarbonImmutable $tanggal, int $idPengguna): Uang
    {
        $pakai = $this->buku->CariMutasi(JenisMutasiGiftCard::Pakai, SumberMutasiGiftCard::Penjualan, $idPenjualan);

        if ($pakai === null) {
            return Uang::Nol();
        }

        $kembali = Uang::Nol()->Kurangi(Uang::Dari($pakai->Jumlah));
        $this->buku->Catat($pakai->IdGiftCard, $kembali, JenisMutasiGiftCard::BatalPakai, SumberMutasiGiftCard::Penjualan, $idPenjualan, $nomor, $tanggal, 'Void penjualan', $idPengguna);

        return $kembali;
    }
}
