<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

use App\Domain\Referensi\Enum\JenisReferensiBank;

/**
 * Jenis metode pembayaran (PRD §15.3 `MetodePembayaran.Jenis`, F-08). Panduan awal (F-01 langkah 5) membuat QRIS
 * statis, QRIS dinamis (F-08), EDC, dan transfer; Tunai dibuat sistem dan selalu tersedia.
 */
enum JenisMetodePembayaran: string
{
    case Tunai = 'Tunai';
    case QrisStatis = 'QrisStatis';
    case QrisDinamis = 'QrisDinamis';
    case Edc = 'Edc';
    case Transfer = 'Transfer';
    case Ewallet = 'Ewallet';
    case Tempo = 'Tempo';
    case Deposit = 'Deposit';
    // CRM-06 (D-153): gift card berkode dari tenant yang sama; saldo dibaca online saat dipakai, satu kartu per penjualan.
    case GiftCard = 'GiftCard';
    case Poin = 'Poin';
    case Voucher = 'Voucher';
    case Marketplace = 'Marketplace';
    // F-12 bagian 2: uang muka pre-order yang dipakai saat pesanan penjualan diambil (dibuat sistem).
    case UangMuka = 'UangMuka';
    // K-11: nilai barang yang diretur dipakai membayar barang pengganti (dibuat sistem, bukan pilihan bayar biasa).
    case Tukar = 'Tukar';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Tunai => 'Tunai',
            self::QrisStatis => 'QRIS statis',
            self::QrisDinamis => 'QRIS dinamis',
            self::Edc => 'Kartu (EDC)',
            self::Transfer => 'Transfer bank',
            self::Ewallet => 'Dompet digital',
            self::Tempo => 'Tempo (piutang)',
            self::Deposit => 'Deposit pelanggan',
            self::GiftCard => 'Gift card',
            self::Poin => 'Poin',
            self::Voucher => 'Voucher',
            self::Marketplace => 'Platform ojol / marketplace',
            self::UangMuka => 'Uang muka (DP)',
            self::Tukar => 'Tukar barang',
        };
    }

    /** Jenis yang bisa dipakai membayar di aplikasi POS fase 1 (F-07b; lainnya menyusul fase 2). */
    public function CekDidukungPos(): bool
    {
        // F-12: Tempo (piutang) untuk pelanggan ber-limit kredit; bagian 2: uang muka pre-order saat diambil.
        // F-08: QRIS dinamis lewat gerbang pembayaran aktif (tagihan dibuat online, `Referensi` = Uuid `TagihanQris`).
        // F-16d bagian 1: deposit pelanggan (wajib online saat dipakai; aplikasi lama melewati jenis yang tidak dikenalnya).
        // X8 (v2.36): Marketplace = pesanan ojol/marketplace yang dibayar platform (dana lewat pencairan).
        // K-11: Tukar = nilai retur tukar barang yang membayar barang pengganti (wajib `UuidReturTukar`).
        return in_array($this, [self::Tunai, self::QrisStatis, self::QrisDinamis, self::Edc, self::Transfer, self::Ewallet, self::Tempo, self::UangMuka, self::Deposit, self::GiftCard, self::Marketplace, self::Tukar], true);
    }

    /** F-16d bagian 1: jenis yang boleh dipakai mengisi saldo deposit di POS (bukan tempo, deposit, atau uang muka). */
    public function CekBolehIsiDeposit(): bool
    {
        return in_array($this, [self::Tunai, self::QrisStatis, self::QrisDinamis, self::Edc, self::Transfer, self::Ewallet], true);
    }

    /** F-12 bagian 2: jenis yang boleh dipakai membayar uang muka pre-order di POS (tanpa tempo & uang muka). */
    public function CekBolehUangMuka(): bool
    {
        return in_array($this, [self::Tunai, self::QrisStatis, self::Edc, self::Transfer, self::Ewallet], true);
    }

    /**
     * Jenis yang bisa ditambahkan dari back-office (F-01 langkah 5; F-08: QRIS dinamis, butuh gerbang aktif platform;
     * F-16d: deposit pelanggan, satu per tenant, akun Saldo Deposit Pelanggan; X8: platform ojol/marketplace, satu per
     * kanal, akun Piutang Pencairan).
     */
    public function CekBisaDibuatPanduan(): bool
    {
        return in_array($this, [self::QrisStatis, self::QrisDinamis, self::Edc, self::Transfer, self::Deposit, self::GiftCard, self::Marketplace], true);
    }

    /**
     * Jenis referensi bank yang boleh dipilih (EDC: bank/jaringan EDC; transfer: bank/dompet digital).
     *
     * @return list<JenisReferensiBank>
     */
    public function AmbilJenisBankBoleh(): array
    {
        return match ($this) {
            self::Edc => [JenisReferensiBank::Bank, JenisReferensiBank::JaringanEdc],
            self::Transfer => [JenisReferensiBank::Bank, JenisReferensiBank::Ewallet],
            default => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function AmbilJenisPanduan(): array
    {
        return array_values(array_filter(self::cases(), fn (self $jenis) => $jenis->CekBisaDibuatPanduan()));
    }
}
