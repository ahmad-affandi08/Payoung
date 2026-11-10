<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Kasir;

use App\Domain\Kasir\Aksi\UbahPajakOutletKasir;
use App\Domain\Kasir\Kueri\PengaturanPajakOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Kasir\UbahPajakOutletKasirPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Pajak & biaya layanan di Atur kasir (D-263), izin `outlet.kelola`. Pengaturannya per outlet (PBJT, biaya
 * layanan, harga termasuk pajak). Dulu hanya bisa diubah lewat langkah Pajak di Panduan awal.
 */
final class PengaturanPajakKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(Request $permintaan, PengaturanPajakOutlet $pengaturan, PetaUuidOutlet $peta): Response
    {
        $opsi = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        abort_if($opsi === [], 404);

        $dipilih = $permintaan->query('outlet');
        $uuid = is_string($dipilih) && in_array($dipilih, array_column($opsi, 'Uuid'), true) ? $dipilih : $opsi[0]['Uuid'];

        return Inertia::render('Kelola/Kasir/Pajak', [
            'UuidOutlet' => $uuid,
            'OpsiOutlet' => array_map(static fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $opsi),
            ...$pengaturan->Ambil($this->CariOutlet($uuid)),
        ]);
    }

    public function Simpan(UbahPajakOutletKasirPermintaan $permintaan, UbahPajakOutletKasir $ubah): RedirectResponse
    {
        $outlet = $this->CariOutlet($permintaan->string('Outlet')->toString());
        $ubah->Jalankan(
            $outlet,
            $permintaan->boolean('PungutPbjt'),
            $permintaan->boolean('BiayaLayananAktif'),
            $permintaan->AmbilPersen(),
            $permintaan->boolean('HargaTermasukPajak'),
        );

        return to_route('kelola.kasir.pajak', ['outlet' => $outlet->Uuid])->with('Kilat', "Pengaturan pajak {$outlet->Nama} disimpan. Berlaku untuk penjualan berikutnya.");
    }
}
