<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Sesi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sesi per nomor lewat Fonnte (`add-device`, `qr`, `device`, `disconnect`). Akun Fonnte milik Payoung (`TokenAkun`
 * dari integrasi konsol), tiap nomor tenant menjadi satu perangkat dengan token sendiri.
 */
final class AdaptorSesiFonnte implements GerbangSesiWhatsapp
{
    private const ALAMAT = 'https://api.fonnte.com';

    public function __construct(private readonly string $tokenAkun) {}

    public function AmbilKode(): string
    {
        return 'Fonnte';
    }

    public function BuatSesi(string $label, string $nomor): HasilSesiWhatsapp
    {
        if ($this->tokenAkun === '') {
            return HasilSesiWhatsapp::Gagal('Layanan WhatsApp belum siap. Hubungi dukungan Payoung.');
        }

        $nama = mb_substr(preg_replace('/[^\p{L}\p{N} ]+/u', '', $label) ?: 'Nomor', 0, 30);
        $nama = mb_strlen($nama) < 2 ? 'Nomor '.$nama : $nama;
        $respons = $this->Panggil($this->tokenAkun, '/add-device', ['name' => $nama, 'device' => $nomor, 'autoread' => 'false', 'personal' => 'true', 'group' => 'false']);

        if ($respons === null) {
            return HasilSesiWhatsapp::Gagal('Layanan WhatsApp tidak bisa dihubungi. Coba lagi sebentar.');
        }

        $token = $respons->json('token') ?? $respons->json('data.token');

        if ($respons->json('status') !== true || ! is_string($token) || $token === '') {
            return HasilSesiWhatsapp::Gagal($this->Alasan($respons, 'Nomor tidak bisa didaftarkan.'));
        }

        return HasilSesiWhatsapp::TerbuatTanpaQr($token);
    }

    public function MintaQr(string $tokenPerangkat): HasilSesiWhatsapp
    {
        $respons = $this->Panggil($tokenPerangkat, '/qr', ['type' => 'qr']);

        if ($respons === null) {
            return HasilSesiWhatsapp::Gagal('Layanan WhatsApp tidak bisa dihubungi. Coba lagi sebentar.');
        }

        $alasan = strtolower((string) ($respons->json('reason') ?? ''));

        if ($respons->json('status') === true && is_string($respons->json('url')) && $respons->json('url') !== '') {
            $url = (string) $respons->json('url');

            return HasilSesiWhatsapp::MenungguPindai(str_starts_with($url, 'data:') ? $url : 'data:image/png;base64,'.$url);
        }

        if (str_contains($alasan, 'already connect')) {
            return HasilSesiWhatsapp::Terhubung();
        }

        return HasilSesiWhatsapp::Gagal($this->Alasan($respons, 'QR belum tersedia. Coba lagi sebentar.'));
    }

    public function PeriksaStatus(string $tokenPerangkat): HasilSesiWhatsapp
    {
        $respons = $this->Panggil($tokenPerangkat, '/device', []);

        if ($respons === null) {
            return HasilSesiWhatsapp::Gagal('Layanan WhatsApp tidak bisa dihubungi.');
        }

        if ($respons->json('status') !== true) {
            return HasilSesiWhatsapp::Terputus($this->Alasan($respons, 'Sesi tidak dikenali penyedia.'));
        }

        return $respons->json('device_status') === 'connect' ? HasilSesiWhatsapp::Terhubung() : HasilSesiWhatsapp::Terputus();
    }

    public function Putuskan(string $tokenPerangkat): HasilSesiWhatsapp
    {
        $respons = $this->Panggil($tokenPerangkat, '/disconnect', []);

        return $respons === null
            ? HasilSesiWhatsapp::Gagal('Layanan WhatsApp tidak bisa dihubungi.')
            : HasilSesiWhatsapp::Terputus('Sesi diputus.');
    }

    /** @param  array<string, mixed>  $data */
    private function Panggil(string $token, string $jalur, array $data): ?Response
    {
        try {
            return Http::timeout(20)->acceptJson()->withHeaders(['Authorization' => $token])->asForm()->post(self::ALAMAT.$jalur, $data);
        } catch (ConnectionException) {
            return null;
        }
    }

    private function Alasan(Response $respons, string $bawaan): string
    {
        $alasan = $respons->json('reason') ?? $respons->json('message');
        $teks = is_string($alasan) && $alasan !== '' ? $alasan : $bawaan;

        return str_replace([$this->tokenAkun], '••••', $teks);
    }
}
