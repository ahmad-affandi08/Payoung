<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * F-21: pelanggan membuka halaman publik invoice. Menambah `JumlahDilihat` dan mengisi `DilihatPada` supaya penagih tahu
 * invoice sudah dibaca. Hanya untuk invoice terbit; penambahan atomik di SQL (dua pembukaan bersamaan tidak saling
 * menimpa) dan lewat query builder, jadi penjaga kolom pada model tidak terlibat. Bukan perubahan dokumen: angka,
 * status, dan jurnal tidak tersentuh, maka tanpa audit.
 */
final class CatatInvoiceDilihat
{
    public function Jalankan(Invoice $invoice): void
    {
        if ($invoice->Status !== StatusInvoice::Terbit) {
            return;
        }

        Invoice::query()->whereKey($invoice->Id)->update([
            'JumlahDilihat' => DB::raw('JumlahDilihat + 1'),
            'DilihatPada' => now(),
        ]);
    }
}
