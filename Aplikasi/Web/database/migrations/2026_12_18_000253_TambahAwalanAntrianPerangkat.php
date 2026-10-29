<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K26 (nomor antrian multi-kasir), tahap expand: perangkat boleh punya awalan huruf nomor antrian (maks. 2 huruf, mis.
 * `A` → `A-042`) yang diatur di back-office supaya dua kasir di satu outlet tidak memanggil nomor yang sama. Kosong =
 * nomor polos seperti sebelumnya, jadi perangkat dan data lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Perangkat', function (Blueprint $tabel): void {
            $tabel->string('AwalanAntrian', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Perangkat', function (Blueprint $tabel): void {
            $tabel->dropColumn('AwalanAntrian');
        });
    }
};
