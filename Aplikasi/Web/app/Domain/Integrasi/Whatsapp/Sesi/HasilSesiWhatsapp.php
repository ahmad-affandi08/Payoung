<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Sesi;

/**
 * Hasil panggilan colokan sesi WhatsApp. `kodeQr` berisi gambar QR (data URI PNG) bila perangkat menunggu dipindai;
 * `terhubung` true bila nomor sudah tersambung. Gagal tidak pernah melempar exception, `pesan` aman ditampilkan.
 */
final readonly class HasilSesiWhatsapp
{
    private function __construct(
        public bool $berhasil,
        public bool $terhubung,
        public ?string $kodeQr,
        public ?string $tokenPerangkat,
        public string $pesan,
    ) {}

    public static function MenungguPindai(string $kodeQr, ?string $tokenPerangkat = null): self
    {
        return new self(true, false, $kodeQr, $tokenPerangkat, 'Pindai QR dengan WhatsApp di ponsel.');
    }

    public static function Terhubung(?string $tokenPerangkat = null): self
    {
        return new self(true, true, null, $tokenPerangkat, 'Nomor terhubung.');
    }

    public static function TerbuatTanpaQr(string $tokenPerangkat): self
    {
        return new self(true, false, null, $tokenPerangkat, 'Sesi dibuat.');
    }

    public static function Terputus(string $pesan = 'Nomor belum atau tidak lagi terhubung.'): self
    {
        return new self(true, false, null, null, mb_substr($pesan, 0, 300));
    }

    public static function Gagal(string $pesan): self
    {
        return new self(false, false, null, null, mb_substr($pesan, 0, 300));
    }
}
