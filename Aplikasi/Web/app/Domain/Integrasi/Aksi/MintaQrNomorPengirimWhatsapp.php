<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\HasilSesiWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;

/**
 * D-299: meminta QR untuk dipindai (atau menandai nomor terhubung bila penyedia melaporkan sudah tersambung).
 * Tidak menyimpan QR; klien memanggil ulang sampai terhubung.
 */
final class MintaQrNomorPengirimWhatsapp
{
    public function __construct(
        private readonly PembuatGerbangSesiWhatsapp $pembuatGerbang,
        private readonly SegarkanStatusNomorPengirimWhatsapp $segarkan,
    ) {}

    public function Jalankan(NomorPengirimWhatsapp $nomor): HasilSesiWhatsapp
    {
        $gerbang = $this->pembuatGerbang->Ambil()
            ?? throw new PelanggaranAturanBisnis('SesiWhatsappBelumSiap', 'Layanan nomor pengirim belum siap. Hubungi dukungan Payoung.', statusHttp: 409);

        if ($nomor->TokenPerangkat === null) {
            throw new PelanggaranAturanBisnis('SesiHilang', 'Sesi nomor ini hilang. Hapus nomor lalu daftarkan ulang.', statusHttp: 409);
        }

        $hasil = $gerbang->MintaQr($nomor->TokenPerangkat);

        if ($hasil->terhubung) {
            $this->segarkan->Terapkan($nomor, true);
        }

        return $hasil;
    }
}
