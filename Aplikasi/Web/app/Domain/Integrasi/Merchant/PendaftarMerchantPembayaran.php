<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;

/**
 * Colokan standar penyedia untuk **mendaftarkan merchant** (KYB: tenant mendaftar sebagai penerima pembayaran QRIS
 * lewat Payoung, D-83, D-179). Aksi pendaftaran, penyegar status, halaman Aktivasi QRIS, dan konsol hanya bicara lewat
 * port ini; protokol penyedia tinggal di adaptornya. Mengganti DOKU dengan penyedia lain = menulis satu adaptor baru dan
 * mendaftarkannya di `PembuatPendaftarMerchant` (lihat `Panduan/PenyediaPembayaran.md`).
 *
 * Aturan untuk semua adaptor:
 * - Tanpa state; kredensial platform dibaca dari konfigurasi integrasi aktif (P-05), bukan disimpan di adaptor.
 * - `Kirim` dapat dilanjutkan: langkah yang sudah berhasil dicatat pada baris pendaftaran (id berkas, id bisnis) dan tidak
 *   diulang bila `Kirim` dipanggil lagi setelah galat tidak pasti.
 * - Galat dilempar sebagai `GalatPendaftaranMerchant` (`tidakPasti` menentukan boleh tidaknya dicoba lagi), pesannya tidak
 *   memuat NIK, nomor rekening, atau rahasia.
 * - Kolom penyedia pada `PendaftaranMerchantPembayaran` (`IdBisnisDoku`, `IdBrandDoku`, `KunciBersama`, `StatusDoku`,
 *   `IdFile*`) dipakai sebagai kolom "id bisnis/brand/status penyedia" oleh adaptor mana pun; `Penyedia` menandai pemiliknya.
 */
interface PendaftarMerchantPembayaran
{
    /** Kode penyedia (`Doku`), sama dengan `PendaftaranMerchantPembayaran.Penyedia` dan kunci di `PembuatPendaftarMerchant::PENYEDIA`. */
    public function AmbilKode(): string;

    /** Nama untuk ditampilkan ke pengguna (`DOKU`). */
    public function AmbilLabel(): string;

    /** Kredensial platform sudah terisi dan layanan siap dipakai. */
    public function CekAktif(): bool;

    /**
     * Unggah berkas KYC lalu daftarkan bisnis. Berkas lokal yang sudah terunggah dihapus oleh adaptor setelah id-nya
     * tersimpan; berkas sisa dihapus oleh pemanggil. Alamat callback status (URL webhook milik penyedia itu di Payoung)
     * dibentuk adaptor sendiri dari `TokenCallback` pendaftaran.
     *
     * @param  array{Kode: string, Nama: string}  $bank  bank rekening pencairan (referensi Payoung)
     *
     * @throws GalatPendaftaranMerchant
     */
    public function Kirim(PendaftaranMerchantPembayaran $pendaftaran, array $bank): HasilPendaftaranMerchant;

    /**
     * Status terbaru di penyedia (sumber kebenaran; notifikasi hanyalah pemicu).
     *
     * @throws GalatPendaftaranMerchant
     */
    public function AmbilStatus(PendaftaranMerchantPembayaran $pendaftaran): HasilPendaftaranMerchant;
}
