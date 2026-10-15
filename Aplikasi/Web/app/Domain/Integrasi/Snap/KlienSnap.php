<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * Klien DOKU SNAP untuk akun induk milik Payoung (D-159), dipakai menagih tenant lewat halaman bayar Payoung sendiri.
 * Kredensial dari integrasi `GerbangBilling` (penyedia `DokuBilling`): Client ID, secret key, dan kunci pribadi RSA
 * yang dibuat server (kunci publiknya didaftarkan ke DOKU di dasbor › API Keys › Merchant Public Key).
 *
 * Dua lapis pengamanan SNAP:
 * 1. **Token B2B** (`POST /authorization/v1/access-token/b2b`): `X-SIGNATURE` = base64(SHA256withRSA(`{clientId}|{timestamp}`)),
 *    berlaku ±15 menit; disimpan di cache supaya tidak diminta ulang tiap transaksi.
 * 2. **Permintaan layanan:** `X-SIGNATURE` = base64(HMAC-SHA512(secret, `METHOD:path:token:sha256hex(badan):timestamp`)).
 *
 * Tidak ada panggilan yang dibungkus coba-ulang otomatis: pembuatan pembayaran bukan operasi idempoten di sisi DOKU.
 */
final class KlienSnap
{
    private const TARGET_TOKEN = '/authorization/v1/access-token/b2b';

    /** Token B2B DOKU berlaku 900 detik; dipakai 60 detik lebih singkat supaya tidak kedaluwarsa di tengah panggilan. */
    private const DETIK_TOKEN_MAKS = 840;

    public function CekSiap(): bool
    {
        return $this->IdKlien() !== '' && $this->KunciRahasia() !== '' && $this->AmbilKunciPribadi() !== null;
    }

    public function CekSandbox(): bool
    {
        return trim((string) config('integrasi.GerbangBilling.Pengaturan.Mode', '')) !== 'Produksi';
    }

    public function AmbilAlamatDasar(): string
    {
        return ProtokolDoku::AmbilAlamatDasar($this->CekSandbox());
    }

    public function IdKlien(): string
    {
        return trim((string) config('integrasi.GerbangBilling.Pengaturan.IdKlien', ''));
    }

    public function Pengaturan(string $kunci): string
    {
        return trim((string) config("integrasi.GerbangBilling.Pengaturan.{$kunci}", ''));
    }

    public function Kredensial(string $kunci): string
    {
        return trim((string) config("integrasi.GerbangBilling.Kredensial.{$kunci}", ''));
    }

    /**
     * Token B2B dari DOKU (dari cache bila masih berlaku).
     *
     * @throws GalatGerbang
     */
    public function AmbilTokenB2b(bool $paksaBaru = false): string
    {
        $kunciCache = 'snap.b2b.'.hash('sha256', $this->IdKlien().'|'.($this->CekSandbox() ? 'S' : 'P'));

        if ($paksaBaru) {
            Cache::forget($kunciCache);
        }

        $token = Cache::get($kunciCache);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $waktu = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $tandaTangan = $this->TandatanganiRsa($this->IdKlien().'|'.$waktu);

        try {
            $respons = Http::timeout(15)->acceptJson()->withHeaders([
                'X-CLIENT-KEY' => $this->IdKlien(),
                'X-TIMESTAMP' => $waktu,
                'X-SIGNATURE' => $tandaTangan,
            ])->withBody('{"grantType":"client_credentials"}', 'application/json')->post($this->AmbilAlamatDasar().self::TARGET_TOKEN);
        } catch (ConnectionException) {
            throw new GalatGerbang('DOKU tidak bisa dihubungi. Coba lagi sebentar lagi.');
        }

        $baru = $respons->json('accessToken');

        if (! $respons->successful() || ! is_string($baru) || $baru === '') {
            // Token gagal diminta = belum ada transaksi yang dibuat, jadi aman dianggap gagal pasti.
            throw new GalatGerbang($this->Saring("DOKU menolak permintaan token (HTTP {$respons->status()}): ".$this->PesanPenyedia($respons)));
        }

        $detik = max(60, min(self::DETIK_TOKEN_MAKS, (int) $respons->json('expiresIn', self::DETIK_TOKEN_MAKS) - 60));
        Cache::put($kunciCache, $baru, $detik);

        return $baru;
    }

    /**
     * Mengirim satu panggilan layanan SNAP (POST JSON). Badan dikirim persis seperti yang ditandatangani.
     *
     * @param  array<string, mixed>  $badan
     * @param  array<string, string>  $headerTambahan
     *
     * @throws GalatGerbang `tidakPasti` bila koneksi putus setelah permintaan mungkin sampai
     */
    public function Kirim(string $target, array $badan, array $headerTambahan = []): Response
    {
        $respons = $this->KirimSekali($target, $badan, $headerTambahan, $this->AmbilTokenB2b());

        // Token yang dicabut DOKU sebelum waktunya (401) diganti sekali; permintaan yang ditolak 401 tidak membuat apa pun.
        if ($respons->status() === 401) {
            $respons = $this->KirimSekali($target, $badan, $headerTambahan, $this->AmbilTokenB2b(paksaBaru: true));
        }

        return $respons;
    }

    /**
     * @param  array<string, mixed>  $badan
     * @param  array<string, string>  $headerTambahan
     */
    private function KirimSekali(string $target, array $badan, array $headerTambahan, string $token): Response
    {
        $isi = (string) json_encode($badan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $waktu = CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
        $teksTandaTangan = 'POST:'.$target.':'.$token.':'.strtolower(hash('sha256', $isi)).':'.$waktu;

        try {
            return Http::timeout(20)->acceptJson()->withHeaders([
                ...$headerTambahan,
                'X-PARTNER-ID' => $this->IdKlien(),
                'X-EXTERNAL-ID' => sprintf('%d%03d', (int) (microtime(true) * 1000), random_int(0, 999)),
                'X-TIMESTAMP' => $waktu,
                'X-SIGNATURE' => base64_encode(hash_hmac('sha512', $teksTandaTangan, $this->KunciRahasia(), true)),
                'CHANNEL-ID' => 'H2H',
                'Authorization' => 'Bearer '.$token,
            ])->withBody($isi, 'application/json')->post($this->AmbilAlamatDasar().$target);
        } catch (ConnectionException) {
            // Permintaan mungkin sudah sampai dan diproses DOKU: pemanggil membiarkan pembayarannya Menunggu.
            throw new GalatGerbang('DOKU tidak bisa dihubungi. Coba lagi sebentar lagi.', tidakPasti: true);
        }
    }

    /**
     * Uji koneksi dari konsol: meminta token B2B dengan nilai yang diberikan (belum tentu sudah dipakai server), tanpa
     * cache. Token yang diterima membuktikan Client ID benar **dan** kunci publik RSA sudah didaftarkan di dasbor DOKU.
     *
     * @return array{Berhasil: bool, Pesan: string}
     */
    public static function UjiTokenB2b(string $idKlien, string $kunciPribadiPem, bool $sandbox): array
    {
        $kunci = openssl_pkey_get_private($kunciPribadiPem);
        $waktu = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');

        if ($kunci === false || ! openssl_sign($idKlien.'|'.$waktu, $tandaTangan, $kunci, OPENSSL_ALGO_SHA256)) {
            return ['Berhasil' => false, 'Pesan' => 'Kunci pribadi SNAP tidak terbaca. Buat ulang kuncinya.'];
        }

        try {
            $respons = Http::timeout(10)->acceptJson()->withHeaders([
                'X-CLIENT-KEY' => $idKlien,
                'X-TIMESTAMP' => $waktu,
                'X-SIGNATURE' => base64_encode($tandaTangan),
            ])->withBody('{"grantType":"client_credentials"}', 'application/json')->post(ProtokolDoku::AmbilAlamatDasar($sandbox).self::TARGET_TOKEN);
        } catch (ConnectionException) {
            return ['Berhasil' => false, 'Pesan' => 'DOKU tidak bisa dihubungi untuk uji SNAP.'];
        }

        if ($respons->successful() && is_string($respons->json('accessToken')) && $respons->json('accessToken') !== '') {
            return ['Berhasil' => true, 'Pesan' => 'Token SNAP diterima DOKU (kunci publik sudah terdaftar).'];
        }

        $pesan = $respons->json('responseMessage');

        return ['Berhasil' => false, 'Pesan' => 'DOKU menolak token SNAP (HTTP '.$respons->status().(is_string($pesan) && $pesan !== '' ? ': '.mb_substr($pesan, 0, 120) : '').'). Pastikan kunci publik sudah didaftarkan di DOKU › API Keys › Merchant Public Key.'];
    }

    /** `responseCode` SNAP = status HTTP (3 digit) + kode layanan + kode kasus; sukses berawalan 200. */
    public static function CekResponsSukses(Response $respons): bool
    {
        $kode = $respons->json('responseCode');

        return $respons->successful() && is_string($kode) && str_starts_with($kode, '200');
    }

    public function PesanPenyedia(Response $respons): string
    {
        $pesan = $respons->json('responseMessage');

        return is_string($pesan) && $pesan !== '' ? $pesan : 'permintaan tidak diproses.';
    }

    public function Saring(string $pesan): string
    {
        foreach ([$this->KunciRahasia(), $this->Kredensial('KunciPribadiSnap')] as $rahasia) {
            if ($rahasia !== '') {
                $pesan = str_replace($rahasia, '••••', $pesan);
            }
        }

        return mb_substr($pesan, 0, 300);
    }

    public function AmbilKunciPribadi(): ?OpenSSLAsymmetricKey
    {
        $pem = $this->Kredensial('KunciPribadiSnap');

        if ($pem === '') {
            return null;
        }

        $kunci = openssl_pkey_get_private($pem);

        return $kunci === false ? null : $kunci;
    }

    private function TandatanganiRsa(string $teks): string
    {
        $kunci = $this->AmbilKunciPribadi();

        if ($kunci === null || ! openssl_sign($teks, $tandaTangan, $kunci, OPENSSL_ALGO_SHA256)) {
            throw new GalatGerbang('Kunci pribadi SNAP belum dibuat atau tidak terbaca. Buat ulang di konsol Integrasi.');
        }

        return base64_encode($tandaTangan);
    }

    private function KunciRahasia(): string
    {
        return $this->Kredensial('KunciRahasia');
    }
}
