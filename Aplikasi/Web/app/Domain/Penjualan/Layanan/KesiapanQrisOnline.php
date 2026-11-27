<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\Layanan\InfoGerbangPembayaran;
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
        return $this->CariHambatan() === [];
    }

    /**
     * Kenapa jalur QRIS belum bisa menerbitkan tagihan (D-338), berteks untuk ditampilkan ke pengelola. Kosong = siap.
     * Dua syarat yang berubah di luar sakelar toko: gerbang pembayaran toko aktif, dan ada metode bayar QRIS dinamis
     * yang aktif. Tamu yang melihat tombol QRIS hilang tidak tahu sebabnya, jadi pengelola diberi tahu di sini.
     *
     * @return list<array{Kode: string, Pesan: string, Tautan: string}>
     */
    public function CariHambatan(): array
    {
        $hambatan = [];

        if ($this->gerbang->AmbilAktifTenant() === null) {
            $hambatan[] = [
                'Kode' => 'GerbangBelumAktif',
                'Pesan' => 'Gerbang pembayaran toko belum aktif. Aktifkan dulu di Pengaturan › '.InfoGerbangPembayaran::AmbilNamaMenu().'.',
                'Tautan' => InfoGerbangPembayaran::AmbilTautan(),
            ];
        }

        if (! MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->where('Aktif', true)->exists()) {
            $hambatan[] = [
                'Kode' => 'MetodeQrisBelumAktif',
                'Pesan' => 'Belum ada metode pembayaran QRIS dinamis yang aktif. Buat atau aktifkan di Metode pembayaran.',
                'Tautan' => InfoGerbangPembayaran::TAUTAN_METODE,
            ];
        }

        return $hambatan;
    }
}
