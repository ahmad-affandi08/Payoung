<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * Pintu penjaga layanan WhatsApp untuk tenant (D-299 WhatsApp ala Majoo). Satu sumber kebenaran untuk pertanyaan
 * "apakah fitur WhatsApp boleh tampil dan jalan di tenant ini".
 *
 * Dua syarat, keduanya wajib:
 * 1. Layanan WhatsApp platform sudah **diaktifkan di konsol** (integrasi WhatsApp aktif, `config('integrasi.Whatsapp')`
 *    terisi oleh `PenerapKonfigurasiIntegrasi`). Di edisi Lisensi, integrasi server milik pembeli.
 * 2. Paket tenant memuat fitur `integrasi.whatsapp`.
 *
 * Bila salah satu tidak terpenuhi, semua yang berkaitan dengan WhatsApp disembunyikan di layar tenant, Kasir, dan Owner,
 * dan pengirim tidak dibuat sehingga tidak ada pesan yang terkirim lewat jalur apa pun.
 */
final class KetersediaanWhatsapp
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    /** True bila konsol (atau integrasi server edisi Lisensi) sudah mengaktifkan penyedia WhatsApp platform. */
    public function PlatformAktif(): bool
    {
        $penyedia = config('integrasi.Whatsapp.Penyedia');

        return is_string($penyedia) && $penyedia !== '';
    }

    /** True bila WhatsApp tersedia bagi tenant tertentu. Tanpa tenant (pesan platform), cukup platform aktif. */
    public function TersediaUntuk(?int $idTenant): bool
    {
        if (! $this->PlatformAktif()) {
            return false;
        }

        return $idTenant === null || $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP);
    }

    /** Untuk tenant pada konteks permintaan ini. */
    public function Tersedia(): bool
    {
        return $this->TersediaUntuk($this->konteks->Ambil());
    }
}
