<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Billing;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\Snap\Data\PembayaranSnapDibuat;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Colokan standar penyedia pembayaran untuk **tagihan langganan Payoung sendiri** (P-08, BR-P08.11, D-179). Akunnya milik
 * platform (bukan milik tenant), dikonfigurasi di konsol pengelola. Semua kode di luar `Domain/Integrasi` (aksi tenant,
 * rekonsiliasi, kontroler, webhook) hanya boleh bicara lewat port ini, tidak pernah ke kelas penyedia tertentu, supaya
 * mengganti DOKU dengan penyedia lain cukup menulis satu adaptor baru dan mendaftarkannya di `PembuatPenyediaTagihan`
 * (lihat `Panduan/PenyediaPembayaran.md`).
 *
 * Ada dua jalur bayar yang boleh disediakan penyedia, masing-masing opsional:
 * - **Checkout**: tenant dialihkan ke halaman bayar penyedia (kartu & metode lain).
 * - **Halaman bayar Payoung**: QRIS, virtual account, atau e-wallet ditampilkan di halaman Payoung; penyedia hanya
 *   memproses (`MetodeBayarSnap`, standar SNAP Bank Indonesia).
 *
 * Aturan yang berlaku untuk semua adaptor:
 * - Pelunasan **tidak pernah** dari peramban atau isi notifikasi mentah. Status selalu dikonfirmasi ke API status penyedia
 *   (`Konfirmasi`/`TanyaStatus`) sebelum tagihan lunas.
 * - Adaptor tidak menyimpan state dan tidak mencatat kredensial.
 * - Galat dari penyedia dilempar sebagai `GalatGerbang`; `tidakPasti: true` berarti transaksi mungkin sudah terbentuk di
 *   penyedia (jangan ditutup), selain itu pasti tidak membuat apa pun.
 */
interface PenyediaTagihanPlatform
{
    /** Kode penyedia (`Doku`), sama dengan kunci di `PembuatPenyediaTagihan::PENYEDIA`. */
    public function AmbilKode(): string;

    /** Nama untuk ditampilkan ke pengguna (`DOKU`). */
    public function AmbilLabel(): string;

    /** Ada minimal satu jalur bayar yang siap dipakai. */
    public function CekAktif(): bool;

    /** Jalur Checkout (alihkan ke halaman bayar penyedia) siap dipakai. */
    public function CekCheckoutAktif(): bool;

    /** Jalur halaman bayar Payoung (QRIS/VA/e-wallet) siap dipakai. */
    public function CekHalamanBayarAktif(): bool;

    /**
     * Gerbang yang menjawab status pembayaran jenis ini siap? `null` = pembayaran Checkout, selain itu `MetodeBayar`
     * halaman bayar Payoung.
     */
    public function CekAktifUntuk(?string $metodeBayar): bool;

    /**
     * Pilihan bayar halaman Payoung yang sudah lengkap pengaturannya (yang belum lengkap tidak ditawarkan).
     *
     * @return list<array{Metode: string, Label: string, Kanal: string|null}>
     */
    public function AmbilPilihanHalamanBayar(): array;

    /** Batas bayar (menit) satu pembayaran halaman bayar Payoung sejak dibuat. */
    public function AmbilMenitKedaluwarsaHalamanBayar(): int;

    /**
     * @param  string  $urlKembali  halaman Payoung tempat penyedia mengarahkan peramban setelah selesai
     *
     * @throws GalatGerbang
     */
    public function BuatCheckout(string $nomorPesanan, Uang $total, string $namaPembayar, string $emailPembayar, string $urlKembali): HasilPembayaranBilling;

    /**
     * @param  string|null  $ipPelanggan  alamat IPv4 peramban tenant (dibutuhkan sebagian e-wallet)
     *
     * @throws GalatGerbang
     */
    public function BuatPembayaranHalamanBayar(
        MetodeBayarSnap $metode,
        ?string $kanal,
        string $nomorPesanan,
        Uang $total,
        string $namaPembayar,
        string $emailPembayar,
        string $urlKembali,
        ?string $ipPelanggan = null,
    ): PembayaranSnapDibuat;

    /**
     * Status satu pembayaran, ditanyakan ke penyedia. Hasilnya dibentuk sama dengan notifikasi webhook supaya diproses
     * jalur yang sama (`TerimaNotifikasiBillingLangganan`). `null` = penyedia tidak bisa dihubungi atau jawabannya tidak
     * terbaca (dicoba lagi nanti).
     *
     * @param  array<string, string>|null  $rincian  `PembayaranLangganan.RincianBayar` (halaman bayar Payoung)
     * @param  string  $jumlah  total tagihan (string desimal)
     */
    public function TanyaStatus(
        string $nomorPesanan,
        ?string $metodeBayar,
        ?array $rincian,
        ?DateTimeInterface $kedaluwarsaPada,
        DateTimeInterface $dibuatPada,
        string $jumlah,
    ): ?NotifikasiBilling;

    /** Notifikasi HTTP dari penyedia sah (tanda tangan/kunci cocok)? Gerbang belum aktif = tidak sah. */
    public function CekTandaTanganSah(Request $permintaan): bool;

    /** Urai notifikasi yang tanda tangannya sudah sah. `null` = bukan notifikasi yang dikenal. */
    public function UraiNotifikasi(Request $permintaan): ?NotifikasiBilling;

    /** Konfirmasi status notifikasi ke API status penyedia. `null` = belum bisa dikonfirmasi (penyedia diminta mengulang). */
    public function Konfirmasi(NotifikasiBilling $notifikasi): ?NotifikasiBilling;
}
