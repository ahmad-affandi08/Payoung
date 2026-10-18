<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

use App\Domain\Integrasi\HasilUjiLayanan;
use Illuminate\Http\Request;

/**
 * Port gerbang pembayaran (PRD §16.6, F-08 QRIS dinamis). Penyedia dipilih di konsol Platform Pengelola (P-05);
 * adaptor tidak menyimpan state dan tidak pernah mencatat kredensial.
 */
interface GerbangPembayaran
{
    public function AmbilKode(): string;

    /** Tes kredensial tanpa membuat transaksi. */
    public function UjiKoneksi(): HasilUjiLayanan;

    /** @throws GalatGerbang */
    public function BuatQris(PermintaanQris $permintaan): HasilQris;

    /** @throws GalatGerbang */
    public function CekStatus(string $nomorPesanan, string $idReferensi): StatusPembayaranGerbang;

    /**
     * Audit P0 F-02: `CekStatus` cukup dengan `NomorPesanan` (tanpa `IdReferensi` dari gerbang), sehingga tagihan yang
     * hasil pembuatannya tidak pasti bisa direkonsiliasi.
     */
    public function CekDapatCekDariNomorPesanan(): bool;

    /**
     * Daftar kemampuan penyedia ini (D-179). Selalu memuat `QrisDinamis`; sisanya opsional. Kode pemanggil memeriksa
     * lewat `CekMendukung` sebelum memakai fitur yang tidak dimiliki semua penyedia.
     *
     * @return list<KemampuanGerbang>
     */
    public function AmbilKemampuan(): array;

    public function CekMendukung(KemampuanGerbang $kemampuan): bool;

    /**
     * Mengembalikan uang atas pembayaran lunas. Hanya untuk penyedia yang mendukung `KemampuanGerbang::Refund`; yang
     * lain melempar `GalatGerbang` (`tidakPasti: false`: tidak ada yang terjadi di gerbang) sehingga pemanggil
     * jatuh ke jalur manual.
     *
     * @throws GalatGerbang
     */
    public function Refund(PermintaanRefund $permintaan): HasilRefund;

    /**
     * Verifikasi & urai notifikasi masuk. Null = tanda tangan/token tidak sah (webhook diabaikan, dijawab 401).
     */
    public function UraiWebhook(Request $permintaan): ?HasilWebhook;
}
