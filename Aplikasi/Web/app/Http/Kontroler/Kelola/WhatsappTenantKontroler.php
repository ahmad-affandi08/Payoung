<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Integrasi\Aksi\SimpanWhatsappTenant;
use App\Domain\Integrasi\Aksi\UbahStatusWhatsappTenant;
use App\Domain\Integrasi\Aksi\UjiWhatsappTenant;
use App\Domain\Integrasi\Kueri\HalamanWhatsappTenant;
use App\Http\Permintaan\Kelola\Pengaturan\SimpanWhatsappTenantPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-108: Pengaturan › WhatsApp (`/kelola/pengaturan/whatsapp`, izin `integrasi.api.kelola`). Tenant memantau kuota
 * pesan nomor Payoung dan boleh memasang nomor WhatsApp sendiri (diuji dulu, lalu diaktifkan); pesan lewat nomor
 * sendiri tidak memakai kuota.
 */
final class WhatsappTenantKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(HalamanWhatsappTenant $kueri): Response
    {
        return Inertia::render('Kelola/Pengaturan/Whatsapp', $kueri->Ambil());
    }

    public function Simpan(SimpanWhatsappTenantPermintaan $permintaan, SimpanWhatsappTenant $simpan): RedirectResponse
    {
        $simpan->Jalankan($permintaan->AmbilData());

        return to_route('kelola.pengaturan.whatsapp')->with('Kilat', 'Pengaturan WhatsApp disimpan. Uji koneksi lalu aktifkan agar pesan lewat nomor Anda.');
    }

    public function Uji(UjiWhatsappTenant $uji): RedirectResponse
    {
        $hasil = $uji->Jalankan();

        return $hasil->berhasil
            ? to_route('kelola.pengaturan.whatsapp')->with('Kilat', "Koneksi berhasil. {$hasil->pesan}")
            : to_route('kelola.pengaturan.whatsapp')->withErrors(['Umum' => "Koneksi gagal. {$hasil->pesan}"]);
    }

    public function Aktifkan(UbahStatusWhatsappTenant $ubah): RedirectResponse
    {
        $ubah->Jalankan(true);

        return to_route('kelola.pengaturan.whatsapp')->with('Kilat', 'WhatsApp sendiri aktif. Pesan ke pelanggan kini lewat nomor Anda dan tidak memakai kuota.');
    }

    public function Nonaktifkan(UbahStatusWhatsappTenant $ubah): RedirectResponse
    {
        $ubah->Jalankan(false);

        return to_route('kelola.pengaturan.whatsapp')->with('Kilat', 'WhatsApp sendiri dinonaktifkan. Pesan kembali lewat nomor Payoung dan memakai kuota paket.');
    }
}
