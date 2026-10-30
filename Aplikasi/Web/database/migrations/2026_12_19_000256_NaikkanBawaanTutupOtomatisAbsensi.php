<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-250), penyesuaian: masa tunggu sebelum absensi yang lupa keluar ditutup otomatis naik dari 120 menit ke 300
 * menit (5 jam) supaya lembur beberapa jam tidak terpotong. Baris yang masih memakai nilai bawaan lama (120) ikut naik;
 * nilai lain berarti pengelola sudah mengaturnya sendiri dan tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->unsignedSmallInteger('TutupOtomatisSetelahMenit')->default(300)->change();
        });

        DB::table('AturanKehadiran')->where('TutupOtomatisSetelahMenit', 120)->update(['TutupOtomatisSetelahMenit' => 300]);
    }

    public function down(): void
    {
        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->unsignedSmallInteger('TutupOtomatisSetelahMenit')->default(120)->change();
        });
    }
};
