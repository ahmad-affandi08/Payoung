<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembayaran;

use App\Domain\Integrasi\Aksi\BuatUlangKunciSnapGerbangTenant;
use App\Domain\Integrasi\Aksi\SimpanGerbangPembayaranTenant;
use App\Domain\Integrasi\Aksi\UbahStatusGerbangPembayaranTenant;
use App\Domain\Integrasi\Aksi\UjiGerbangPembayaranTenant;
use App\Domain\Integrasi\Kueri\HalamanGerbangPembayaranTenant;
use App\Domain\Penjualan\Aksi\SiapkanMetodeQrisDinamis;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pembayaran\SimpanGerbangPembayaranPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F-08 / P-05 v2.06: gerbang pembayaran QRIS dinamis milik tenant (`pembayaran.gerbang.atur`). Tenant memilih penyedia
 * yang diizinkan platform, mengisi kredensial akun merchant-nya, menguji, lalu mengaktifkan.
 */
final class GerbangPembayaranKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(HalamanGerbangPembayaranTenant $kueri): Response
    {
        return Inertia::render('Kelola/Pembayaran/Gerbang', $kueri->Ambil());
    }

    public function Simpan(SimpanGerbangPembayaranPermintaan $permintaan, SimpanGerbangPembayaranTenant $simpan): RedirectResponse
    {
        $gerbang = $simpan->Jalankan($permintaan->AmbilData());

        return to_route('kelola.pembayaran.gerbang')->with('Kilat', $gerbang->Aktif
            ? 'Gerbang pembayaran disimpan.'
            : 'Gerbang pembayaran disimpan. Uji koneksi lalu aktifkan agar kasir bisa memakai QRIS dinamis.');
    }

    public function Uji(UjiGerbangPembayaranTenant $uji): RedirectResponse
    {
        $hasil = $uji->Jalankan();

        return $hasil->berhasil
            ? to_route('kelola.pembayaran.gerbang')->with('Kilat', "Koneksi berhasil. {$hasil->pesan}")
            : to_route('kelola.pembayaran.gerbang')->withErrors(['Umum' => "Koneksi gagal. {$hasil->pesan}"]);
    }

    public function BuatUlangKunci(BuatUlangKunciSnapGerbangTenant $buat): RedirectResponse
    {
        $buat->Jalankan();

        return to_route('kelola.pembayaran.gerbang')->with('Kilat', 'Kunci SNAP baru dibuat. Daftarkan kunci publik yang baru di dasbor DOKU, lalu uji koneksi lagi.');
    }

    public function Aktifkan(UbahStatusGerbangPembayaranTenant $ubah, SiapkanMetodeQrisDinamis $siapkanMetode): RedirectResponse
    {
        $ubah->Jalankan(true);
        // Audit kemudahan pakai #24: metode bayar QRIS ikut dibuat bila belum ada, jadi kasir langsung bisa memakainya.
        $metode = $siapkanMetode->Jalankan();

        return to_route('kelola.pembayaran.gerbang')->with('Kilat', $metode === null
            ? 'QRIS tersambung. Kasir sudah bisa menerima pembayaran QRIS.'
            : "QRIS tersambung. Pilihan bayar \"{$metode->Nama}\" sudah ditambahkan ke kasir.");
    }

    public function Nonaktifkan(UbahStatusGerbangPembayaranTenant $ubah): RedirectResponse
    {
        $ubah->Jalankan(false);

        return to_route('kelola.pembayaran.gerbang')->with('Kilat', 'Gerbang pembayaran dinonaktifkan. QRIS dinamis tidak bisa dipakai kasir.');
    }
}
