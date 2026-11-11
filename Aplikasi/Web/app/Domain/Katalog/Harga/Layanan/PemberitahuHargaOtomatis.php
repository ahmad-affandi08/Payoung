<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Data\HasilHargaOtomatis;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;

/**
 * Kabar ke pengguna ber-izin `produk.harga.ubah` setiap kali harga otomatis mengubah harga atau menahan usulan (D-266),
 * supaya perubahan harga tidak pernah terjadi tanpa sepengetahuan pemilik. Satu kabar per putaran dan penerima (kunci
 * unik), berisi jumlah dan beberapa contoh. Pengguna yang membisukan jenis ini tidak menerimanya.
 */
final class PemberitahuHargaOtomatis
{
    private const CONTOH = 3;

    public function __construct(
        private readonly PenerimaNotifikasi $penerima,
        private readonly BuatNotifikasiPengguna $buat,
    ) {}

    public function Beritahu(int $idTenant, string $kunciPutaran, HasilHargaOtomatis $hasil): void
    {
        if (! $hasil->CekAdaYangBerubah()) {
            return;
        }

        $bagian = [];

        if ($hasil->diterapkan > 0) {
            $bagian[] = "{$hasil->diterapkan} harga jual berubah mengikuti harga beli";
        }

        if ($hasil->diusulkan > 0) {
            $bagian[] = "{$hasil->diusulkan} perubahan besar menunggu keputusan Anda";
        }

        $contoh = array_map(
            fn (array $p): string => "{$p['Nama']} (".Uang::Dari((string) $p['Lama'])->FormatRupiah().' ke '.Uang::Dari((string) $p['Baru'])->FormatRupiah().')',
            array_slice($hasil->perubahan, 0, self::CONTOH),
        );
        $isi = ucfirst(implode(', ', $bagian)).'. '.implode(', ', $contoh).($hasil->diterapkan + $hasil->diusulkan > self::CONTOH ? ', dan lainnya.' : '.');

        foreach ($this->penerima->AmbilIdPengguna($idTenant, IzinTenant::ProdukHargaUbah, null) as $idPengguna) {
            $this->buat->Jalankan(
                $idPengguna,
                JenisNotifikasiPengguna::HargaOtomatis,
                'HargaOtomatis:'.$kunciPutaran,
                'Harga jual mengikuti harga beli',
                $isi,
                ['Tautan' => 'notifikasi', 'Href' => '/kelola/produk/harga-otomatis'],
            );
        }
    }
}
