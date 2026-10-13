<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Penjualan\Aksi\KirimUlangPesanKeluar;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Model\PesanKeluar;

/**
 * Alat bantu (ubah): kirim ulang pesan WhatsApp struk digital yang gagal terkirim (PGL-16 bagian 2). Petugas memilih pesan
 * dari daftar yang tujuannya tersamar; nomor penuh tidak pernah keluar dari server. Pesan dikirim lewat jalur yang sama
 * (kuota pesan WhatsApp tenant tetap berlaku). Hasil mencatat status pesan lama dan pesan baru.
 */
final class KirimUlangPesanWhatsappDukungan
{
    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly KirimUlangPesanKeluar $kirimUlang,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, string $uuidPesan): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::KirimUlangPesanWhatsapp, ['UuidPesan' => $uuidPesan], function () use ($uuidPesan): HasilKerjaAlat {
            $lama = PesanKeluar::query()->where('Uuid', $uuidPesan)->first();
            $sebelum = $lama === null ? null : ['Status' => $lama->Status->value, 'Percobaan' => $lama->Percobaan, 'PesanGalat' => $lama->PesanGalat];
            $baru = $this->kirimUlang->Jalankan($uuidPesan);

            return new HasilKerjaAlat(
                StatusHasilAlatDukungan::Berhasil,
                'Pesan dikirim ulang dan menunggu antrean.',
                $sebelum,
                ['Status' => StatusPesanKeluar::Diantrekan->value, 'UuidPesanBaru' => $baru->Uuid],
            );
        });
    }
}
