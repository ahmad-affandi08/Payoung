<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Sesi;

/**
 * Memilih colokan sesi dari integrasi WhatsApp yang aktif di konsol (`config('integrasi.Whatsapp')`). Null bila
 * konsol belum mengaktifkan WhatsApp atau penyedia aktifnya belum punya akun sesi (Fonnte tanpa `TokenAkun`).
 */
final class PembuatGerbangSesiWhatsapp
{
    public function Ambil(): ?GerbangSesiWhatsapp
    {
        $konfigurasi = config('integrasi.Whatsapp');

        if (! is_array($konfigurasi) || ! is_string($konfigurasi['Penyedia'] ?? null)) {
            return null;
        }

        $kredensial = is_array($konfigurasi['Kredensial'] ?? null) ? $konfigurasi['Kredensial'] : [];

        return match ($konfigurasi['Penyedia']) {
            'Fonnte' => is_string($kredensial['TokenAkun'] ?? null) && $kredensial['TokenAkun'] !== ''
                ? new AdaptorSesiFonnte($kredensial['TokenAkun'])
                : null,
            default => null,
        };
    }

    /** Nomor pengirim per outlet bisa dipakai bila penyedia aktif mendukung sesi QR. */
    public function Tersedia(): bool
    {
        return $this->Ambil() !== null;
    }
}
