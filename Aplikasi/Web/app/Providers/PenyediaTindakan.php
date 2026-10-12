<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Akuntansi\Layanan\PenyediaTindakanAkuntansi;
use App\Domain\Bengkel\Layanan\PenyediaTindakanBengkel;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan as KontrakPenyediaTindakan;
use App\Domain\Integrasi\Layanan\PenyediaTindakanWhatsapp;
use App\Domain\Karyawan\Layanan\PenyediaTindakanKehadiran;
use App\Domain\Kasir\Layanan\PenyediaTindakanKasir;
use App\Domain\Laporan\Layanan\PenyediaTindakanStok;
use App\Domain\Organisasi\Layanan\PenyediaTindakanPerangkat;
use App\Domain\Pajak\Layanan\PenyediaTindakanPajak;
use App\Domain\PanduanAwal\Layanan\PenyediaTindakanPanduanAwal;
use App\Domain\Pelanggan\Layanan\PenyediaTindakanPelanggan;
use App\Domain\Pembelian\Layanan\PenyediaTindakanPembelian;
use App\Domain\Pemenuhan\Layanan\PenyediaTindakanLaundry;
use App\Domain\Pemenuhan\Layanan\PenyediaTindakanReservasi;
use App\Domain\Penjualan\Layanan\PenyediaTindakanPenjualan;
use App\Domain\Promo\Layanan\PenyediaTindakanPromo;
use App\Domain\Tenant\Layanan\PenyediaTindakanPenyimpanan;
use Illuminate\Support\ServiceProvider;

/**
 * D-23 C Kotak Tindakan: mendaftarkan penyedia butir tindakan tiap domain dengan tag `PenyediaTindakan::TAG`.
 * Domain baru cukup menambah penyedianya di sini.
 */
final class PenyediaTindakan extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            PenyediaTindakanPenjualan::class,
            PenyediaTindakanKasir::class,
            PenyediaTindakanPelanggan::class,
            PenyediaTindakanStok::class,
            PenyediaTindakanPembelian::class,
            PenyediaTindakanAkuntansi::class,
            PenyediaTindakanPromo::class,
            PenyediaTindakanWhatsapp::class,
            PenyediaTindakanPajak::class,
            PenyediaTindakanPenyimpanan::class,
            PenyediaTindakanPanduanAwal::class,
            PenyediaTindakanReservasi::class,
            PenyediaTindakanLaundry::class,
            PenyediaTindakanBengkel::class,
            PenyediaTindakanKehadiran::class,
            PenyediaTindakanPerangkat::class,
        ], KontrakPenyediaTindakan::TAG);
    }
}
