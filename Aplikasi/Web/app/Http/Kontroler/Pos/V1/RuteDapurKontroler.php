<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Katalog\Aksi\AturStasiunDapurKategori;
use App\Domain\Katalog\Aksi\AturStasiunDapurProduk;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Pos\V1\AturStasiunDapurPermintaan;
use Illuminate\Http\JsonResponse;

/**
 * D-310 (F-10): mengatur dari kasir ke stasiun dapur/bar mana sebuah kategori atau menu dikirim. Online, pelaku wajib
 * anggota outlet perangkat ber-izin `dapur.rute.atur` (bawaan Kasir). Berlaku untuk seluruh usaha; perangkat lain menerimanya
 * lewat `GET /api/pos/v1/meja` (`KategoriStasiun`, `ProdukStasiun`). `UuidStasiun` null = ikut aturan induk.
 * Jawaban `{Uuid, UuidStasiun}` = keadaan akhir (idempoten).
 */
final class RuteDapurKontroler extends Kontroler
{
    public function AturKategori(AturStasiunDapurPermintaan $permintaan, string $kategori, AturStasiunDapurKategori $atur): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $stasiun = $atur->Jalankan(
            $perangkat->IdOutlet,
            $kategori,
            $permintaan->AmbilUuidStasiun(),
            strtoupper($permintaan->string('UuidPengguna')->toString()),
        );

        return response()->json(['Uuid' => strtoupper($kategori), 'UuidStasiun' => $stasiun]);
    }

    public function AturProduk(AturStasiunDapurPermintaan $permintaan, string $produk, AturStasiunDapurProduk $atur): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $stasiun = $atur->Jalankan(
            $perangkat->IdOutlet,
            $produk,
            $permintaan->AmbilUuidStasiun(),
            strtoupper($permintaan->string('UuidPengguna')->toString()),
        );

        return response()->json(['Uuid' => strtoupper($produk), 'UuidStasiun' => $stasiun]);
    }
}
