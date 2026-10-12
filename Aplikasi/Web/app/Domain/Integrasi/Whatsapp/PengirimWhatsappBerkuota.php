<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Integrasi\HasilUjiLayanan;
use Throwable;

/**
 * Pembungkus pengirim nomor Payoung untuk pesan atas nama tenant (D-108): tiap pesan memesan satu kuota bulanan
 * sebelum dikirim dan mengembalikannya bila penyedia gagal. Kuota habis menghasilkan `HasilKirimWhatsapp::Gagal`
 * (tanpa menghubungi penyedia), jadi pemanggil tetap memakai jalur kegagalan yang sudah ada.
 */
final class PengirimWhatsappBerkuota implements PengirimWhatsapp
{
    public const PESAN_HABIS = 'Kuota pesan WhatsApp bulan ini habis. Tambah add-on kuota, naikkan paket, atau pasang WhatsApp sendiri di Pengaturan.';

    public function __construct(
        private readonly PengirimWhatsapp $dalam,
        private readonly MeterKuotaWhatsapp $meter,
        private readonly int $idTenant,
    ) {}

    public function AmbilKode(): string
    {
        return $this->dalam->AmbilKode();
    }

    public function CekResmi(): bool
    {
        return $this->dalam->CekResmi();
    }

    public function UjiKoneksi(): HasilUjiLayanan
    {
        return $this->dalam->UjiKoneksi();
    }

    public function Kirim(PesanWhatsapp $pesan): HasilKirimWhatsapp
    {
        $periode = $this->meter->AmbilPeriode();

        if (! $this->meter->Pesan($this->idTenant)) {
            return HasilKirimWhatsapp::Gagal(self::PESAN_HABIS);
        }

        try {
            $hasil = $this->dalam->Kirim($pesan);
        } catch (Throwable $galat) {
            $this->meter->Lepas($this->idTenant, $periode);

            throw $galat;
        }

        if (! $hasil->berhasil) {
            $this->meter->Lepas($this->idTenant, $periode);
        }

        return $hasil;
    }
}
