<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use Illuminate\Support\Facades\DB;

/**
 * F-21 bagian 3: pelanggan membuka halaman publik penawaran. Menambah `JumlahDilihat` dan mengisi `DilihatPada` supaya
 * penagih tahu penawaran sudah dibaca. Hanya untuk penawaran terbit atau diterima; atomik di SQL dan lewat query builder
 * (bukan perubahan dokumen, maka tanpa audit).
 */
final class CatatPenawaranDilihat
{
    public function Jalankan(Penawaran $penawaran): void
    {
        if (! in_array($penawaran->Status, [StatusPenawaran::Terbit, StatusPenawaran::Diterima], true)) {
            return;
        }

        Penawaran::query()->whereKey($penawaran->Id)->update([
            'JumlahDilihat' => DB::raw('JumlahDilihat + 1'),
            'DilihatPada' => now(),
        ]);
    }
}
