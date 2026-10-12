<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-111: fitur "Franchise & royalti" (`franchise.royalti`) tercantum di paket Enterprise tetapi tidak punya kode,
 * rute, maupun layar. Menjual fitur yang belum ada dikeluarkan dari katalog sampai flow-nya dibangun. Baris di
 * paket dan override tenant dihapus lebih dulu karena `PaketFitur.KunciFitur` berkunci asing ke `Fitur`.
 * Tidak dibalik: bila flow franchise kelak dibangun, fiturnya masuk lagi lewat katalog.
 */
return new class extends Migration
{
    private const KUNCI = 'franchise.royalti';

    public function up(): void
    {
        DB::table('PaketFitur')->where('KunciFitur', self::KUNCI)->delete();

        if (Schema::hasTable('OverrideTenant')) {
            DB::table('OverrideTenant')->where('Jenis', 'Fitur')->where('Kunci', self::KUNCI)->delete();
        }

        DB::table('Fitur')->where('Kunci', self::KUNCI)->delete();
    }

    public function down(): void
    {
        // Sengaja kosong: fitur yang dihapus tidak punya implementasi untuk dikembalikan.
    }
};
