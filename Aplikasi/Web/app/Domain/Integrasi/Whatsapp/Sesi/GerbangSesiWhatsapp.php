<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Sesi;

/**
 * Colokan sesi perangkat WhatsApp per nomor (D-299, pola D-179): membuat sesi, meminta QR untuk dipindai, memeriksa
 * status sambungan, dan memutus. Platform memegang akun penyedia, tenant tidak pernah mengisi token. Penyedia pertama
 * Fonnte; penyedia lain cukup menambah adaptor. Tidak melempar exception, semua hasil lewat `HasilSesiWhatsapp`.
 */
interface GerbangSesiWhatsapp
{
    /** Kode penyedia sesi (sama dengan kode penyedia WhatsApp P-05). */
    public function AmbilKode(): string;

    /** Buat sesi perangkat baru untuk [nomor]; hasil membawa `tokenPerangkat` bila berhasil. */
    public function BuatSesi(string $label, string $nomor): HasilSesiWhatsapp;

    /** Minta QR baru (atau tanda sudah terhubung) untuk sesi [tokenPerangkat]. */
    public function MintaQr(string $tokenPerangkat): HasilSesiWhatsapp;

    /** Status sambungan sesi: `Terhubung` atau `Terputus`. */
    public function PeriksaStatus(string $tokenPerangkat): HasilSesiWhatsapp;

    /** Putus sesi dari sisi penyedia. */
    public function Putuskan(string $tokenPerangkat): HasilSesiWhatsapp;
}
