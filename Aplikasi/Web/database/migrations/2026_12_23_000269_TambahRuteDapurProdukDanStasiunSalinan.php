<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-310 (F-10): rute tiket dapur lebih luwes. (1) `Produk.IdStasiunDapur`: satu menu bisa punya stasiun sendiri yang
 * menimpa stasiun kategorinya (varian ikut produk induknya). Kosong = ikut kategori seperti sebelumnya. (2)
 * `StasiunDapur.Salinan`: stasiun "checker" yang tidak memasak, hanya menerima salinan semua tiket yang dikirim ke
 * dapur untuk dicek sebelum disajikan. Stasiun salinan tidak bisa dipilih sebagai stasiun kategori atau produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->foreignId('IdStasiunDapur')->nullable()->after('IdKategori')
                ->constrained('StasiunDapur', 'Id', 'FkProdukIdStasiunDapur')->restrictOnDelete();
        });

        Schema::table('StasiunDapur', function (Blueprint $tabel): void {
            $tabel->boolean('Salinan')->default(false)->after('Urutan');
        });
    }

    public function down(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkProdukIdStasiunDapur');
            $tabel->dropColumn('IdStasiunDapur');
        });

        Schema::table('StasiunDapur', function (Blueprint $tabel): void {
            $tabel->dropColumn('Salinan');
        });
    }
};
