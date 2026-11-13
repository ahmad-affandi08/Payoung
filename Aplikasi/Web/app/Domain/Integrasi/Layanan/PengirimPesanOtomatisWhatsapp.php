<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Layanan;

use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Enum\StatusRiwayatPesanWhatsapp;
use App\Domain\Integrasi\Model\RiwayatPesanWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;

/**
 * Pintu tunggal pesan WhatsApp otomatis (D-299): memeriksa sakelar jenisnya, memilih nomor pengirim (outlet dulu,
 * sesuai fungsi), mengirim teks, dan mencatat riwayat dengan nomor tersamar. Dipanggil dengan konteks tenant aktif.
 * Mengembalikan true bila pesan terkirim.
 */
final class PengirimPesanOtomatisWhatsapp
{
    public function __construct(
        private readonly PengaturPesanWhatsapp $pengatur,
        private readonly PembuatPengirimWhatsapp $pembuat,
    ) {}

    public function Kirim(JenisPesanWhatsapp $jenis, ?int $idOutlet, string $nomor, string $teks): bool
    {
        $nomorSah = PesanWhatsapp::AmbilNomorSah($nomor);

        if ($nomorSah === null || ! $this->pengatur->Aktif($jenis)) {
            return false;
        }

        $pengirim = $this->pembuat->AmbilAktif($idOutlet, $jenis->AmbilFungsi());

        if ($pengirim === null) {
            return false;
        }

        $hasil = $pengirim->Kirim(new PesanWhatsapp($nomorSah, $teks));
        RiwayatPesanWhatsapp::query()->create([
            'IdOutlet' => $idOutlet, 'Jenis' => $jenis, 'Tujuan' => '••••'.substr($nomorSah, -4),
            'Status' => $hasil->berhasil ? StatusRiwayatPesanWhatsapp::Terkirim : StatusRiwayatPesanWhatsapp::Gagal,
            'PesanGalat' => $hasil->berhasil ? null : mb_substr($hasil->pesan, 0, 300),
        ]);

        return $hasil->berhasil;
    }
}
