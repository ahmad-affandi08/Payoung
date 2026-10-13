<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-05.4: lokasi produksi produk jenis Resep (`Dapur`/`Bar`). Penjualan produk itu mengurangi bahan resep dari lokasi
 * stok jenis tersebut di outlet penjualan; kosong = lokasi Toko (perilaku lama), jadi aman untuk tenant dan aplikasi lama.
 * Disimpan di `Produk` (bukan `Resep`) karena versi resep tidak boleh diubah (BR-03.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->string('LokasiProduksi', 20)->nullable()->after('MasaGaransiBulan');
        });
    }

    public function down(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->dropColumn('LokasiProduksi');
        });
    }
};
