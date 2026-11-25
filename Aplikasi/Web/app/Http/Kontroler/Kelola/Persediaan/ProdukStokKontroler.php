<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Persediaan\Kueri\CariProdukStok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian produk berstok untuk form stok awal (DesainF05a D): `GET /kelola/persediaan/produk/cari?kata=&gudang=
 * &batas=` → `{ "Data": [...] }` (tipe FE `HasilCariProdukStok`). `gudang` (Uuid) opsional; lokasi stok di luar
 * outlet pelaku atau tenant lain = 404.
 */
final class ProdukStokKontroler extends DasarPersediaanKontroler
{
    public function Cari(Request $permintaan, CariProdukStok $cari): JsonResponse
    {
        $uuidGudang = $permintaan->string('gudang')->toString();
        $idGudang = $uuidGudang === '' ? null : $this->CariGudangBoleh($uuidGudang)->id;

        return response()->json([
            'Data' => $cari->Cari(
                mb_substr(trim($permintaan->string('kata')->toString()), 0, 100),
                $idGudang,
                max(1, min(50, $permintaan->integer('batas', 20))),
                hanyaProduksi: $permintaan->string('jenis')->toString() === 'Produksi',
            ),
        ]);
    }
}
