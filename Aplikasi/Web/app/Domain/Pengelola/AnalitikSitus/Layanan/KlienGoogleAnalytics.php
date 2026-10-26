<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\AnalitikSitus\Layanan;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien Google Analytics Data API v1 (GA4) untuk laporan situs di konsol Pengelola (D-215).
 *
 * Masuk dengan akun layanan Google (JWT RS256 ditukar menjadi token akses, cakupan baca-saja) dari integrasi P-05
 * jenis `AnalitikGoogle`. JWT dibuat sendiri seperti `PengirimFcm`: satu-satunya yang dibutuhkan adalah
 * `openssl_sign`, sedangkan SDK Google menarik puluhan dependensi baru. Semua kegagalan menjadi `GalatAnalitikGoogle`
 * dengan pesan yang aman ditampilkan.
 */
final class KlienGoogleAnalytics
{
    public const CAKUPAN = 'https://www.googleapis.com/auth/analytics.readonly';

    private const ALAMAT_API = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    private const ALAMAT_TOKEN_BAWAAN = 'https://oauth2.googleapis.com/token';

    private const DETIK_CACHE_TOKEN = 3300;

    /** @param array{client_email: string, private_key: string, token_uri: string}|null $akun */
    private function __construct(private readonly string $idProperti, private readonly ?array $akun) {}

    /** Klien dari integrasi `AnalitikGoogle` yang aktif di lingkungan ini. */
    public static function Aktif(): self
    {
        $konfigurasi = config('integrasi.AnalitikGoogle');
        $pengaturan = is_array($konfigurasi) && is_array($konfigurasi['Pengaturan'] ?? null) ? $konfigurasi['Pengaturan'] : [];
        $kredensial = is_array($konfigurasi) && is_array($konfigurasi['Kredensial'] ?? null) ? $konfigurasi['Kredensial'] : [];

        return self::Dari($pengaturan, $kredensial);
    }

    /**
     * @param  array<string, mixed>  $pengaturan
     * @param  array<string, mixed>  $kredensial
     */
    public static function Dari(array $pengaturan, array $kredensial): self
    {
        $properti = self::BersihkanIdProperti((string) ($pengaturan['IdProperti'] ?? ''));
        $mentah = $kredensial['AkunLayanan'] ?? null;
        $akun = is_string($mentah) ? json_decode($mentah, true) : null;

        if (! is_array($akun) || ! is_string($akun['client_email'] ?? null) || $akun['client_email'] === ''
            || ! is_string($akun['private_key'] ?? null) || $akun['private_key'] === '') {
            return new self($properti, null);
        }

        return new self($properti, [
            'client_email' => $akun['client_email'],
            'private_key' => $akun['private_key'],
            'token_uri' => is_string($akun['token_uri'] ?? null) && $akun['token_uri'] !== '' ? $akun['token_uri'] : self::ALAMAT_TOKEN_BAWAAN,
        ]);
    }

    /** "properties/412345678" atau " 412345678 " → "412345678"; selain angka (misal ID pengukuran G-…) → kosong. */
    public static function BersihkanIdProperti(string $nilai): string
    {
        $nilai = trim($nilai);
        $nilai = str_starts_with(strtolower($nilai), 'properties/') ? substr($nilai, 11) : $nilai;

        return preg_match('/^\d{3,20}$/', $nilai) === 1 ? $nilai : '';
    }

    public function CekAktif(): bool
    {
        return $this->idProperti !== '' && $this->akun !== null;
    }

    public function AmbilIdProperti(): string
    {
        return $this->idProperti;
    }

    public function AmbilEmailAkun(): ?string
    {
        return $this->akun['client_email'] ?? null;
    }

    /**
     * Menguji sambungan: menukar token baru lalu menjalankan laporan terkecil. Melempar `GalatAnalitikGoogle`
     * dengan petunjuk perbaikan bila ada yang salah.
     */
    public function Uji(): void
    {
        $this->TokenAkses(true);
        $this->Laporan([
            'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
            'metrics' => [['name' => 'activeUsers']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $badan
     * @return array<string, mixed>
     */
    public function Laporan(array $badan): array
    {
        return $this->Kirim(':runReport', $badan);
    }

    /**
     * Sampai lima laporan dalam satu panggilan (batas Google).
     *
     * @param  list<array<string, mixed>>  $daftar
     * @return list<array<string, mixed>>
     */
    public function LaporanBatch(array $daftar): array
    {
        $hasil = $this->Kirim(':batchRunReports', ['requests' => $daftar]);
        $laporan = $hasil['reports'] ?? [];

        return is_array($laporan) ? array_values(array_filter($laporan, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $badan
     * @return array<string, mixed>
     */
    public function Realtime(array $badan): array
    {
        return $this->Kirim(':runRealtimeReport', $badan);
    }

    /**
     * @param  array<string, mixed>  $badan
     * @return array<string, mixed>
     */
    private function Kirim(string $metode, array $badan, bool $ulang = true): array
    {
        if (! $this->CekAktif()) {
            throw new GalatAnalitikGoogle('Google Analytics belum dihubungkan. Atur di Integrasi › Google Analytics.');
        }

        $token = $this->TokenAkses(false);

        try {
            $respons = Http::withToken($token)->acceptJson()->timeout(25)->post(self::ALAMAT_API.$this->idProperti.$metode, $badan);
        } catch (ConnectionException) {
            throw new GalatAnalitikGoogle('Google Analytics tidak bisa dihubungi. Coba lagi sebentar lagi.');
        }

        if ($respons->status() === 401 && $ulang) {
            Cache::forget($this->KunciCacheToken());

            return $this->Kirim($metode, $badan, false);
        }

        if (! $respons->successful()) {
            throw new GalatAnalitikGoogle($this->PesanGalat($respons));
        }

        $isi = $respons->json();

        return is_array($isi) ? $isi : [];
    }

    private function PesanGalat(Response $respons): string
    {
        $pesanGoogle = $respons->json('error.message');
        $pesanGoogle = is_string($pesanGoogle) ? $pesanGoogle : '';

        return match ($respons->status()) {
            400 => 'Google menolak permintaan laporan'.($pesanGoogle !== '' ? ": {$pesanGoogle}" : '.'),
            401, 403 => 'Google menolak akses ke properti GA4 '.$this->idProperti.'. Pastikan '.($this->AmbilEmailAkun() ?? 'akun layanan').' sudah ditambahkan sebagai Viewer di properti itu dan Google Analytics Data API sudah diaktifkan di proyek Google Cloud-nya.',
            404 => 'Properti GA4 '.$this->idProperti.' tidak ditemukan. Periksa ID properti di Integrasi › Google Analytics.',
            429 => 'Kuota Google Analytics sedang habis. Coba lagi beberapa menit lagi.',
            default => "Google Analytics menjawab dengan galat (HTTP {$respons->status()}).",
        };
    }

    private function KunciCacheToken(): string
    {
        return 'ga-token:'.hash('sha256', (string) $this->AmbilEmailAkun());
    }

    private function TokenAkses(bool $segar): string
    {
        if ($this->akun === null) {
            throw new GalatAnalitikGoogle('Akun layanan Google belum diisi.');
        }

        $akun = $this->akun;

        if ($segar) {
            Cache::forget($this->KunciCacheToken());
        }

        $token = Cache::get($this->KunciCacheToken());

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $jwt = $this->BuatJwt($akun);

        try {
            $respons = Http::asForm()->timeout(10)->post($akun['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (ConnectionException) {
            throw new GalatAnalitikGoogle('Google tidak bisa dihubungi untuk menukar token akses.');
        }

        $baru = $respons->successful() ? $respons->json('access_token') : null;

        if (! is_string($baru) || $baru === '') {
            $sebab = $respons->json('error_description');
            $sebab = is_string($sebab) && $sebab !== '' ? $sebab : "HTTP {$respons->status()}";

            throw new GalatAnalitikGoogle("Google menolak akun layanan ({$sebab}). Periksa apakah kuncinya masih berlaku dan jam server tepat.");
        }

        Cache::put($this->KunciCacheToken(), $baru, self::DETIK_CACHE_TOKEN);

        return $baru;
    }

    /** @param array{client_email: string, private_key: string, token_uri: string} $akun */
    private function BuatJwt(array $akun): string
    {
        $sekarang = time();
        $kode = static fn (string $nilai): string => rtrim(strtr(base64_encode($nilai), '+/', '-_'), '=');
        $bahan = $kode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'.$kode(json_encode([
            'iss' => $akun['client_email'],
            'scope' => self::CAKUPAN,
            'aud' => $akun['token_uri'],
            'iat' => $sekarang,
            'exp' => $sekarang + 3600,
        ], JSON_THROW_ON_ERROR));
        $kunci = openssl_pkey_get_private($akun['private_key']);
        $tanda = '';

        if ($kunci === false || ! openssl_sign($bahan, $tanda, $kunci, OPENSSL_ALGO_SHA256)) {
            throw new GalatAnalitikGoogle('Kunci privat di akun layanan tidak bisa dipakai menandatangani. Berkasnya kemungkinan terpotong saat disalin.');
        }

        return $bahan.'.'.$kode($tanda);
    }
}
