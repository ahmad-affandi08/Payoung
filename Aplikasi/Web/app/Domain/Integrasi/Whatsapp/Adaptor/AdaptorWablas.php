<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Adaptor;

use App\Domain\Integrasi\ApiPublik\Layanan\PenjagaAlamatWebhook;
use App\Domain\Integrasi\HasilUjiLayanan;
use App\Domain\Integrasi\Whatsapp\HasilKirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Wablas (tidak resmi): server per akun (`https://{kota}.wablas.com`), header `Authorization: {token}` atau
 * `{token}.{kunci rahasia}` untuk akun yang memakai kunci rahasia.
 */
final class AdaptorWablas extends AdaptorWhatsappDasar
{
    public function AmbilKode(): string
    {
        return 'Wablas';
    }

    private function Otorisasi(): string
    {
        $rahasia = $this->Kredensial('KunciRahasia');

        return $rahasia === '' ? $this->Kredensial('Token') : $this->Kredensial('Token').'.'.$rahasia;
    }

    private function Alamat(string $jalur): string
    {
        return rtrim($this->Pengaturan('Domain'), '/').$jalur;
    }

    /** Domain diisi tenant sendiri: tanpa pengalihan, supaya jawaban 3xx tidak bisa membelokkan permintaan ke jaringan dalam. */
    protected function Http(): PendingRequest
    {
        return parent::Http()->withoutRedirecting();
    }

    /**
     * Domain Wablas diisi tenant, jadi server tidak boleh dipakai menembak alamat internal (SSRF: metadata awan, localhost,
     * jaringan privat). Domain resmi `*.wablas.com` (https) langsung diterima, domain khusus lain harus lolos penjaga alamat.
     * Hasilnya `null` bila aman, selain itu alasan penolakan.
     */
    private function AlasanDomainDitolak(): ?string
    {
        $domain = rtrim($this->Pengaturan('Domain'), '/');
        $bagian = parse_url($domain);
        $host = is_array($bagian) ? strtolower((string) ($bagian['host'] ?? '')) : '';
        $resmi = is_array($bagian) && strtolower((string) ($bagian['scheme'] ?? '')) === 'https'
            && ! isset($bagian['user']) && ! isset($bagian['pass']) && ! isset($bagian['port'])
            && str_ends_with($host, '.wablas.com');

        if ($resmi || app(PenjagaAlamatWebhook::class)->Periksa($domain)['Aman']) {
            return null;
        }

        return 'Domain Wablas harus alamat https:// publik di internet (misalnya https://jkt.wablas.com).';
    }

    public function UjiKoneksi(): HasilUjiLayanan
    {
        if (($ditolak = $this->AlasanDomainDitolak()) !== null) {
            return HasilUjiLayanan::Gagal($ditolak);
        }

        $respons = $this->Coba(fn () => $this->Http()->get($this->Alamat('/api/device/info'), ['token' => $this->Kredensial('Token')]));

        return $respons !== null && $respons->json('status') === true
            ? HasilUjiLayanan::Berhasil('Token diterima Wablas (perangkat: '.($respons->json('data.status') ?? 'tidak diketahui').').')
            : HasilUjiLayanan::Gagal($this->Saring('Wablas menolak token atau domain: '.($respons?->json('message') ?? 'tidak ada jawaban')));
    }

    public function Kirim(PesanWhatsapp $pesan): HasilKirimWhatsapp
    {
        if (($ditolak = $this->AlasanDomainDitolak()) !== null) {
            return HasilKirimWhatsapp::Gagal($ditolak);
        }

        $respons = $this->Coba(fn () => $this->Http()->withHeaders(['Authorization' => $this->Otorisasi()])->asForm()
            ->post($this->Alamat('/api/send-message'), ['phone' => $this->Nomor($pesan), 'message' => $pesan->teks]));
        $id = $respons?->json('data.messages.0.id');

        return $respons?->json('status') === true
            ? HasilKirimWhatsapp::Berhasil(is_scalar($id) ? (string) $id : null)
            : $this->GagalRespons($respons, 'Pesan ditolak Wablas.');
    }
}
