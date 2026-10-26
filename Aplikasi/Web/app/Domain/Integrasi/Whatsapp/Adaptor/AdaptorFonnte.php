<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Adaptor;

use App\Domain\Integrasi\HasilUjiLayanan;
use App\Domain\Integrasi\Whatsapp\HasilKirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;

/** Fonnte (tidak resmi, WhatsApp Web): `POST https://api.fonnte.com/send`, header `Authorization: {token}`. */
final class AdaptorFonnte extends AdaptorWhatsappDasar
{
    private const ALAMAT = 'https://api.fonnte.com';

    public function AmbilKode(): string
    {
        return 'Fonnte';
    }

    public function UjiKoneksi(): HasilUjiLayanan
    {
        $respons = $this->Coba(fn () => $this->Http()->withHeaders(['Authorization' => $this->Kredensial('Token')])->asForm()->post(self::ALAMAT.'/device'));

        if ($respons === null || $respons->json('status') !== true) {
            return HasilUjiLayanan::Gagal($this->Saring('Fonnte menolak token: '.($respons?->json('reason') ?? 'tidak ada jawaban')));
        }

        $status = (string) ($respons->json('device_status') ?? '');

        return $status === 'connect'
            ? HasilUjiLayanan::Berhasil('Token diterima. Perangkat Fonnte tersambung.')
            : HasilUjiLayanan::Gagal("Token diterima, tetapi perangkat Fonnte belum tersambung ({$status}). Pindai ulang QR di dasbor Fonnte.");
    }

    public function Kirim(PesanWhatsapp $pesan): HasilKirimWhatsapp
    {
        $respons = $this->Coba(fn () => $this->Http()->withHeaders(['Authorization' => $this->Kredensial('Token')])->asForm()
            ->post(self::ALAMAT.'/send', ['target' => $this->Nomor($pesan), 'message' => $pesan->teks, 'countryCode' => '62']));
        $id = $respons?->json('id.0');

        return $respons?->json('status') === true
            ? HasilKirimWhatsapp::Berhasil(is_scalar($id) ? (string) $id : null)
            : $this->GagalRespons($respons, 'Pesan ditolak Fonnte.');
    }
}
