<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Enum\KanalRilis;
use App\Domain\Tenant\Enum\StatusRilis;
use App\Domain\Tenant\Model\RilisAplikasi;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Tautan unduh aplikasi kasir untuk halaman back-office (F-02b langkah 5, P-10).
 *
 * Halaman Perangkat & langkah Perangkat panduan awal dipakai **sebelum** aplikasi terpasang. Tanpa tautan di sini,
 * pemilik usaha sudah memegang kode aktivasi tetapi tidak tahu di mana mengunduh aplikasinya.
 *
 * Sumber tautan = rilis `Aktif` kanal `Stabil` yang punya `UrlUnduh`, versi tertinggi per platform, **tanpa memandang
 * `PersenRollout`**: tautan toko aplikasi sama untuk semua perangkat, dan halaman ini dibuka sebelum ada perangkat
 * yang embernya bisa dihitung. Rilis `Draf`/`Dihentikan` dan kanal `Beta` tidak pernah tampil. Bila belum ada rilis,
 * cadangan `config/aplikasi.php` dipakai, sama seperti `VersiAplikasiPerangkat`.
 */
final class PenyusunUnduhanAplikasi
{
    private const LABEL_PLATFORM = [
        'Android' => 'Android',
        'Ios' => 'iPhone & iPad',
        'Windows' => 'Windows',
    ];

    /**
     * @return list<array{Platform: string, Label: string, Versi: string, Url: string, QrSvg: string|null}>
     */
    public function Susun(AplikasiRilis $aplikasi): array
    {
        $konfigurasi = config("aplikasi.{$aplikasi->value}");
        $konfigurasi = is_array($konfigurasi) ? $konfigurasi : [];
        $hasil = [];

        foreach ($konfigurasi as $platform => $nilai) {
            $nilai = is_array($nilai) ? $nilai : [];
            $rilis = RilisAplikasi::query()
                ->where('Aplikasi', $aplikasi->value)
                ->where('Platform', $platform)
                ->where('Status', StatusRilis::Aktif->value)
                ->where('Kanal', KanalRilis::Stabil->value)
                ->whereNotNull('UrlUnduh')
                ->where('UrlUnduh', '!=', '')
                ->get()
                ->sort(fn (RilisAplikasi $a, RilisAplikasi $b): int => RilisAplikasi::BandingkanVersi($b->Versi, $a->Versi))
                ->first();

            // `??` sudah menelan akses properti pada `null`, jadi `?->` tidak diperlukan di sini.
            $url = $rilis->UrlUnduh ?? ($nilai['TautanUnduh'] ?? null);

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $hasil[] = [
                'Platform' => (string) $platform,
                'Label' => self::LABEL_PLATFORM[$platform] ?? (string) $platform,
                'Versi' => $rilis->Versi ?? (string) ($nilai['VersiTerbaru'] ?? ''),
                'Url' => trim($url),
                'QrSvg' => null,
            ];
        }

        // Satu QR saja: Android didahulukan karena paling sering dipakai di HP/tablet kasir. QR mempercepat pemasangan
        // saat halaman ini dibuka di komputer, tanpa perlu mengetik alamat toko aplikasi di HP.
        $indeksQr = array_key_first(array_filter($hasil, fn (array $baris): bool => $baris['Platform'] === 'Android'));
        $indeksQr ??= array_key_first($hasil);

        foreach ($hasil as $indeks => $baris) {
            $hasil[$indeks]['QrSvg'] = $indeks === $indeksQr
                ? (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd)))->writeString($baris['Url'])
                : null;
        }

        return $hasil;
    }
}
