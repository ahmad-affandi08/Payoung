<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti transfer pada konfirmasi pembayaran invoice (F-21 bagian 5, D-170): foto opsional yang dilampirkan pelanggan.
 * Jalurnya di disk privat dan hanya diunduh lewat rute back-office berizin. Berkasnya bukti pembukuan: dipertahankan
 * selama masa retensi pajak seperti lampiran pembukuan lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('KonfirmasiPembayaranInvoice', function (Blueprint $tabel): void {
            $tabel->string('PathBukti', 255)->nullable()->after('Catatan');
        });
    }

    public function down(): void
    {
        Schema::table('KonfirmasiPembayaranInvoice', function (Blueprint $tabel): void {
            $tabel->dropColumn('PathBukti');
        });
    }
};
