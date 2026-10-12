<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-114: `PengaturanPlatform.Nilai` kini juga menyimpan daftar rekening tujuan transfer (JSON beberapa rekening) yang
 * bisa melebihi 500 karakter, jadi kolomnya dilebarkan menjadi teks. Migrasi 000184 yang sudah di-merge tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `PengaturanPlatform` MODIFY `Nilai` TEXT NOT NULL');
    }

    public function down(): void
    {
        // Tidak dibalik: mempersempit kolom bisa memotong daftar rekening yang tersimpan.
    }
};
