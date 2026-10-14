<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FnB minimum charge (§9.1, D-144): batas belanja minimum per area (mis. VIP) dan per meja. `Meja.MinimumCharge`
 * menimpa `AreaMeja.MinimumCharge`; kosong di keduanya = tanpa minimum. Uang DECIMAL (CLAUDE.md #7). Hanya menambah
 * kolom (expand): meja dan area yang ada tidak berubah perilakunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('AreaMeja', function (Blueprint $tabel): void {
            $tabel->decimal('MinimumCharge', 18, 2)->nullable()->after('Urutan');
        });

        Schema::table('Meja', function (Blueprint $tabel): void {
            $tabel->decimal('MinimumCharge', 18, 2)->nullable()->after('Urutan');
        });
    }

    public function down(): void
    {
        Schema::table('Meja', function (Blueprint $tabel): void {
            $tabel->dropColumn('MinimumCharge');
        });

        Schema::table('AreaMeja', function (Blueprint $tabel): void {
            $tabel->dropColumn('MinimumCharge');
        });
    }
};
