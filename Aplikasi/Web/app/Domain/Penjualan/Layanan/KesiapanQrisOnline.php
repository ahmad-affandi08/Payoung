<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;

/**
 * F-17 bagian 2/4: sakelar `PengaturanTokoOnline.QrisAktif` hanya diperiksa saat disimpan. Setelah itu gerbang pembayaran
 * bisa dinonaktifkan atau metode QRIS dimatikan, sehingga etalase tetap menawarkan QRIS lalu checkout menggantung
 * sampai pesanan hangus. Layanan ini menjawab: apakah jalur QRIS benar-benar bisa menerbitkan tagihan sekarang.
 *
 * Sakelar toko tetap dibaca pemanggil; di sini hanya dua syarat yang berubah di luar halaman pengaturan toko.
 */
final class KesiapanQrisOnline
{
    public function __construct(private readonly PembuatGerbangPembayaran $gerbang) {}

    public function CekSiap(): bool
    {
        return $this->gerbang->AmbilAktifTenant() !== null
            && MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->where('Aktif', true)->exists();
    }
}
