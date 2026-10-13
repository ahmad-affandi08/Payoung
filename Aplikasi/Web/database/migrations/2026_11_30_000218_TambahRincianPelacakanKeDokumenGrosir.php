<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-12 grosir untuk produk ber-batch (F-05g) dan bernomor seri (F-05h). Surat jalan grosir mengalokasikan batch
 * secara FEFO di server atau mencatat nomor seri tiap unit, dan retur harus tahu persis batch/nomor mana yang
 * diserahkan serta berapa HPP-nya, supaya stok kembali ke batch asal dan pada nilai saat itu.
 *
 * `RincianPelacakan` = snapshot JSON bagian yang dilacak pada baris itu: batch (`NomorBatch`, `TanggalKedaluwarsa`,
 * `JumlahDasar`, `HppSatuan`) atau nomor seri (`Nomor`, `HppSatuan`). Pada `SuratJalanDetail` = yang diserahkan; pada
 * `ReturGrosirDetail` = yang dikembalikan. Sisa yang masih bisa diretur dihitung dari selisih keduanya.
 *
 * Expand-only: kolom nullable, baris lama (produk tanpa pelacakan) tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('SuratJalanDetail', function (Blueprint $tabel): void {
            $tabel->json('RincianPelacakan')->nullable();
        });
        Schema::table('ReturGrosirDetail', function (Blueprint $tabel): void {
            $tabel->json('RincianPelacakan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('SuratJalanDetail', function (Blueprint $tabel): void {
            $tabel->dropColumn('RincianPelacakan');
        });
        Schema::table('ReturGrosirDetail', function (Blueprint $tabel): void {
            $tabel->dropColumn('RincianPelacakan');
        });
    }
};
