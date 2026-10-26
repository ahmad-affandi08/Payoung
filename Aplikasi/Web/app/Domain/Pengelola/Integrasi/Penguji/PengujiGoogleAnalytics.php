<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Penguji;

use App\Domain\Pengelola\AnalitikSitus\Layanan\GalatAnalitikGoogle;
use App\Domain\Pengelola\AnalitikSitus\Layanan\KlienGoogleAnalytics;
use App\Domain\Pengelola\Integrasi\Data\HasilUjiKoneksi;

/**
 * Menguji Google Analytics (D-215): menukar akun layanan menjadi token akses lalu menjalankan laporan terkecil di
 * properti GA4. Satu laporan itu membuktikan sekaligus berkas JSON utuh, Data API aktif, dan akun layanan sudah
 * menjadi Viewer di properti yang dimaksud.
 */
final class PengujiGoogleAnalytics implements PengujiKoneksi
{
    public function Uji(array $pengaturan, array $kredensial): HasilUjiKoneksi
    {
        $properti = trim((string) ($pengaturan['IdProperti'] ?? ''));

        if (preg_match('/^G-[A-Z0-9]+$/i', $properti) === 1) {
            return HasilUjiKoneksi::Gagal('Itu ID pengukuran (Measurement ID) untuk pemasangan tag. Yang dibutuhkan ID properti berupa angka: Google Analytics › Admin › Property settings › Property ID.');
        }

        if (KlienGoogleAnalytics::BersihkanIdProperti($properti) === '') {
            return HasilUjiKoneksi::Gagal('ID properti GA4 harus berupa angka saja, misal 412345678.');
        }

        $mentah = $kredensial['AkunLayanan'] ?? '';
        $akun = json_decode($mentah, true);

        if (! is_array($akun)) {
            return HasilUjiKoneksi::Gagal('Isi akun layanan bukan JSON yang sah. Salin seluruh isi berkas kunci dari Google Cloud, termasuk kurung kurawalnya.');
        }

        foreach (['client_email', 'private_key'] as $wajib) {
            if (! is_string($akun[$wajib] ?? null) || $akun[$wajib] === '') {
                return HasilUjiKoneksi::Gagal("Akun layanan tidak memuat `{$wajib}`. Pakai berkas kunci JSON dari Google Cloud › IAM › Akun layanan › Kunci.");
            }
        }

        $klien = KlienGoogleAnalytics::Dari($pengaturan, $kredensial);

        try {
            $klien->Uji();
        } catch (GalatAnalitikGoogle $galat) {
            return HasilUjiKoneksi::Gagal($galat->getMessage());
        }

        return HasilUjiKoneksi::Berhasil("Terhubung ke properti GA4 {$klien->AmbilIdProperti()} sebagai {$klien->AmbilEmailAkun()}.");
    }
}
