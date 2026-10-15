<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-159: pembayaran tagihan langganan lewat DOKU SNAP (halaman bayar Payoung). `MetodeBayar`/`KanalBayar` = cara bayar
 * yang dipilih tenant (QRIS, virtual account + bank, DANA, ShopeePay; kosong = DOKU Checkout atau transfer manual),
 * `RincianBayar` = isi QR, nomor VA, atau tautan e-wallet beserta pengenal untuk menanyakan status, dan
 * `KedaluwarsaPada` = batas bayar. Kolom berisi nilai kosong untuk semua pembayaran lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PembayaranLangganan', function (Blueprint $tabel): void {
            $tabel->string('MetodeBayar', 20)->nullable()->after('RefGateway');
            $tabel->string('KanalBayar', 40)->nullable()->after('MetodeBayar');
            $tabel->json('RincianBayar')->nullable()->after('KanalBayar');
            $tabel->timestamp('KedaluwarsaPada')->nullable()->after('RincianBayar');
        });
    }

    public function down(): void
    {
        Schema::table('PembayaranLangganan', function (Blueprint $tabel): void {
            $tabel->dropColumn(['MetodeBayar', 'KanalBayar', 'RincianBayar', 'KedaluwarsaPada']);
        });
    }
};
