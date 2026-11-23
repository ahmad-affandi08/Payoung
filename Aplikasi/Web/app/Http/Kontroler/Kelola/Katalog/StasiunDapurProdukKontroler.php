<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Katalog\Aksi\AturStasiunDapurProduk;
use App\Http\Permintaan\Kelola\Katalog\AturStasiunDapurProdukPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * D-310: stasiun dapur/bar satu menu dari detail produk (menimpa stasiun kategorinya). Izin `produk.kelola` dijaga rute.
 */
final class StasiunDapurProdukKontroler extends DasarKatalogKontroler
{
    public function Ubah(string $produk, AturStasiunDapurProdukPermintaan $permintaan, AturStasiunDapurProduk $atur): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $atur->Jalankan(0, $baris->Uuid, $permintaan->AmbilUuidStasiun(), idPenggunaBackOffice: $this->Pelaku()->Id);

        return back()->with('Kilat', $permintaan->AmbilUuidStasiun() === null
            ? "{$baris->Nama} mengikuti stasiun kategorinya."
            : "Stasiun dapur {$baris->Nama} disimpan.");
    }
}
