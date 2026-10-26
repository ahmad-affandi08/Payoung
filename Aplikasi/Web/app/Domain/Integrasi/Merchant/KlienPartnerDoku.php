<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien DOKU Partner API (`/adv-core-api/partner/v1.0/...`): Payoung berstatus Partner di DOKU dan mendaftarkan
 * merchant/brand tenant lewat KYB. Kredensial = integrasi platform `PendaftaranMerchant`/`DokuPartner`: `Client-Id` =
 * Brand ID partner (dari account manager DOKU), kunci = secret key partner.
 *
 * ASUMSI BELUM TERVERIFIKASI (cocokkan lewat Postman collection "DOKU Partner API" / UAT sebelum produksi):
 *  - tanda tangan semua panggilan (kecuali Upload File) memakai skema HMACSHA256 non-SNAP yang sama dengan Checkout
 *    (`ProtokolDoku`) dengan `Request-Target` = path tanpa query;
 *  - bentuk badan Business Registration (lihat `PembentukBadanPendaftaranDoku`) dan bentuk respons yang diurai longgar;
 *  - URL produksi Get Business Data memakai pola yang sama dengan UAT.
 *
 * Pesan galat selalu disaring dari secret key, Client-Id, dan token sebelum dilempar.
 */
final class KlienPartnerDoku
{
    public const HOST_UAT = 'https://api-uat.doku.com';

    public const HOST_PRODUKSI = 'https://api.doku.com';

    public const AWALAN = '/adv-core-api/partner/v1.0';

    /** Parameter Check Requirements / Get Business Data untuk usaha perseorangan (PERSONAL). */
    private const KONTEKS_PERSONAL = [
        'businessType' => 'PERSONAL',
        'businessLegalEntity' => 'PERSEORANGAN',
        'brandBusinessLine' => 'RETAIL',
        'businessContactNationality' => 'ID',
    ];

    public function CekAktif(): bool
    {
        return $this->IdKlien() !== '' && $this->KunciRahasia() !== '';
    }

    public function CekProduksi(): bool
    {
        return trim((string) config('integrasi.PendaftaranMerchant.Pengaturan.Mode', '')) === 'Produksi';
    }

    public function AmbilHost(): string
    {
        return $this->CekProduksi() ? self::HOST_PRODUKSI : self::HOST_UAT;
    }

    /** Generate Token (JWT Bearer untuk Upload File). */
    public function AmbilToken(): string
    {
        $respons = $this->PanggilBertandatangan('POST', '/token', [], ['grant_type' => 'client_credentials', 'valid_time' => '360'], 'membuat token');
        $token = $respons->json('token');

        if (! is_string($token) || trim($token) === '') {
            throw new GalatPartnerDoku('DOKU menjawab berhasil tetapi token tidak terbaca. Coba lagi sebentar lagi.', tidakPasti: true, statusHttp: $respons->status());
        }

        return trim($token);
    }

    /**
     * Check Requirements: dokumen wajib untuk usaha perseorangan.
     *
     * @return array<string, mixed>
     */
    public function AmbilPersyaratan(): array
    {
        $respons = $this->PanggilBertandatangan('GET', '/file', self::KONTEKS_PERSONAL, null, 'memeriksa persyaratan');

        /** @var array<string, mixed> */
        return (array) $respons->json();
    }

    /**
     * Upload File (multipart, Bearer). `$kode` hanya untuk kategori DOCUMENT (misal KTP). Mengembalikan id berkas.
     */
    public function UnggahBerkas(string $token, string $kategori, ?string $kode, string $isi, string $nama): string
    {
        $bagian = ['category' => $kategori];

        if ($kode !== null) {
            $bagian['code'] = $kode;
        }

        try {
            $respons = Http::timeout(60)->acceptJson()->withToken($token)
                ->attach('file', $isi, $nama)
                ->post($this->AmbilHost().self::AWALAN.'/file', $bagian);
        } catch (ConnectionException) {
            throw new GalatPartnerDoku('DOKU tidak bisa dihubungi saat mengunggah berkas. Akan dicoba lagi.', tidakPasti: true);
        }

        $this->PastikanBerhasil($respons, 'mengunggah berkas', $token);
        $id = $respons->json('id');

        if (! is_string($id) || trim($id) === '') {
            throw new GalatPartnerDoku('DOKU menjawab berhasil tetapi id berkas tidak terbaca. Akan dicoba lagi.', tidakPasti: true, statusHttp: $respons->status());
        }

        return trim($id);
    }

    /**
     * Business Registration.
     *
     * @param  array<string, mixed>  $badan
     */
    public function DaftarkanBisnis(array $badan): HasilBisnisDoku
    {
        $respons = $this->PanggilBertandatangan('POST', '/business', [], $badan, 'mendaftarkan bisnis');
        $hasil = $this->UraiBisnis((array) $respons->json());

        if ($hasil->idBisnis === null) {
            throw new GalatPartnerDoku('DOKU menjawab berhasil tetapi ID bisnis tidak terbaca. Periksa dasbor DOKU sebelum mencoba lagi.', tidakPasti: true, statusHttp: $respons->status());
        }

        return $hasil;
    }

    /** Get Business Data (sumber kebenaran status KYB). */
    public function AmbilDataBisnis(string $idBrand): HasilBisnisDoku
    {
        $respons = $this->PanggilBertandatangan('GET', '/business/'.rawurlencode($idBrand), self::KONTEKS_PERSONAL, null, 'membaca data bisnis');

        return $this->UraiBisnis((array) $respons->json());
    }

    /**
     * @param  array<string, mixed>  $json
     */
    public function UraiBisnis(array $json): HasilBisnisDoku
    {
        $bisnis = is_array($json['business'] ?? null) ? $json['business'] : $json;
        $daftarBrand = $bisnis['brands'] ?? $json['brands'] ?? [];
        $brand = is_array($daftarBrand) && is_array($daftarBrand[0] ?? null) ? $daftarBrand[0] : [];
        $layanan = [];

        foreach ((array) ($brand['services'] ?? []) as $satu) {
            if (is_array($satu)) {
                $layanan[] = ['Kode' => $this->Teks($satu['code'] ?? null), 'Nama' => $this->Teks($satu['name'] ?? null), 'Status' => strtoupper($this->Teks($satu['status'] ?? null))];
            }
        }

        $alasan = null;

        foreach ([$brand, $bisnis, $json] as $sumber) {
            foreach (['rejection_reason', 'rejected_reason', 'reject_reason', 'reason', 'notes'] as $kunci) {
                if ($alasan === null && is_string($sumber[$kunci] ?? null) && trim($sumber[$kunci]) !== '') {
                    $alasan = trim($sumber[$kunci]);
                }
            }
        }

        $kunciBersama = $this->Teks($brand['shared_key'] ?? null);
        $idBisnis = $this->Teks($bisnis['id'] ?? null);
        $idBrand = $this->Teks($brand['id'] ?? null);

        return new HasilBisnisDoku(
            $idBisnis === '' ? null : $idBisnis,
            strtoupper($this->Teks($bisnis['status'] ?? $json['status'] ?? null)),
            $idBrand === '' ? null : $idBrand,
            strtoupper($this->Teks($brand['status'] ?? null)),
            $kunciBersama === '' ? null : $kunciBersama,
            $layanan,
            $alasan === null ? null : mb_substr($this->Saring($alasan), 0, 300),
        );
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $badan
     */
    private function PanggilBertandatangan(string $metode, string $jalur, array $query, ?array $badan, string $kegiatan): Response
    {
        $target = self::AWALAN.$jalur;
        $isi = $badan === null ? null : (string) json_encode($badan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $permintaan = Http::timeout(20)->acceptJson()
                ->withHeaders(ProtokolDoku::BuatHeader($this->IdKlien(), $this->KunciRahasia(), $target, $isi));
            $url = $this->AmbilHost().$target;
            $respons = $metode === 'GET'
                ? $permintaan->get($url, $query)
                : $permintaan->withBody((string) $isi, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw new GalatPartnerDoku("DOKU tidak bisa dihubungi saat {$kegiatan}. Akan dicoba lagi.", tidakPasti: true);
        }

        $this->PastikanBerhasil($respons, $kegiatan);

        return $respons;
    }

    private function PastikanBerhasil(Response $respons, string $kegiatan, string $token = ''): void
    {
        if ($respons->successful()) {
            return;
        }

        $status = $respons->status();

        // Kredensial partner Payoung ditolak = masalah di sisi kita, bukan data tenant: jangan menyalahkan isian tenant.
        if (in_array($status, [401, 403], true)) {
            throw new GalatPartnerDoku('Kredensial Partner DOKU di sisi Payoung ditolak. Tim Payoung perlu memeriksanya. Coba lagi nanti.', tidakPasti: true, statusHttp: $status);
        }

        $pesan = $this->Saring("DOKU menolak saat {$kegiatan} (HTTP {$status}): ".$this->PesanPenyedia($respons), $token);

        throw new GalatPartnerDoku($pesan, tidakPasti: $respons->serverError() || in_array($status, [408, 425, 429], true), statusHttp: $status);
    }

    /** DOKU mengirim galat sebagai `message` (daftar atau teks) atau `error.message`; bentuk lain jadi pesan umum. */
    private function PesanPenyedia(Response $respons): string
    {
        $pesan = $respons->json('message.0') ?? $respons->json('message') ?? $respons->json('error.message');

        return is_string($pesan) && $pesan !== '' ? $pesan : 'permintaan tidak diterima.';
    }

    private function Teks(mixed $nilai): string
    {
        return is_string($nilai) ? trim($nilai) : '';
    }

    private function Saring(string $pesan, string $token = ''): string
    {
        $rahasia = array_filter([$this->KunciRahasia(), $this->IdKlien(), $token], static fn (string $s): bool => $s !== '');

        return mb_substr(str_replace($rahasia, '••••', $pesan), 0, 300);
    }

    private function IdKlien(): string
    {
        return trim((string) config('integrasi.PendaftaranMerchant.Pengaturan.IdKlien', ''));
    }

    private function KunciRahasia(): string
    {
        return trim((string) config('integrasi.PendaftaranMerchant.Kredensial.KunciRahasia', ''));
    }
}
