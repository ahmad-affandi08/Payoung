<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Katalog\Aksi\UbahKetersediaanProduk;
use App\Domain\Katalog\Kueri\ProdukHabisOutlet;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Pos\V1\UbahKetersediaanProdukPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F-17 BR-17.2 (tandai habis / "86") di POS: `GET /api/pos/v1/produk-habis` mengembalikan `{Produk: [Uuid, ...]}` yang
 * habis di kanal kasir outlet perangkat (ditandai semua kanal atau khusus kasir, PRD-11), dan `POST /api/pos/v1/produk/{uuid}/habis` `{UuidPengguna, Habis}` menandai habis atau
 * tersedia lagi. Pelaku wajib anggota outlet ber-izin `produk.kelola`, `penjualan.buat`, atau `pesanan.meja.catat`.
 */
final class KetersediaanProdukKontroler extends Kontroler
{
    public function Ambil(Request $permintaan, ProdukHabisOutlet $kueri): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);

        return response()->json(['Produk' => $kueri->AmbilUuid($perangkat->IdOutlet)]);
    }

    public function Ubah(UbahKetersediaanProdukPermintaan $permintaan, string $produk, UbahKetersediaanProduk $ubah): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $habis = $ubah->Jalankan(
            $perangkat->IdOutlet,
            strtoupper($produk),
            $permintaan->boolean('Habis'),
            strtoupper($permintaan->string('UuidPengguna')->toString()),
            kanal: $permintaan->AmbilKanal(),
        );

        return response()->json(['Uuid' => strtoupper($produk), 'Habis' => $habis]);
    }
}
