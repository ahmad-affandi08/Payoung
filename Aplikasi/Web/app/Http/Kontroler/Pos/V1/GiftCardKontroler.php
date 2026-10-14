<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Pelanggan\Kueri\CekGiftCardPos;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gift card di POS (CRM-06, D-153, wajib online §18.4): `POST /api/pos/v1/gift-card/cek` memeriksa kode dan mengembalikan
 * saldonya. Kode dikirim di badan (bukan URL/query) supaya tidak masuk log akses. Pemakaian final terjadi saat item
 * outbox `Penjualan.Buat` dengan pembayaran jenis `GiftCard` (kode di `Referensi`) diterima.
 */
final class GiftCardKontroler extends Kontroler
{
    public function Cek(Request $permintaan, CekGiftCardPos $cek): JsonResponse
    {
        /** @var array{Kode: string} $valid */
        $valid = $permintaan->validate(['Kode' => ['required', 'string', 'max:30']], attributes: ['Kode' => 'kode gift card']);

        return response()->json($cek->Ambil($valid['Kode']));
    }
}
