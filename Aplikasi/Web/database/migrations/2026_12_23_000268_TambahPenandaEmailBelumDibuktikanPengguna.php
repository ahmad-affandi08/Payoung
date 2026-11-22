<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-309: akun yang dibuat admin tenant lewat "Tambah staf" ditandai email-nya belum dibuktikan pemilik kotak surat
 * (`EmailBelumDibuktikan`). Akun itu tetap bisa langsung masuk (tidak ada langkah tambahan bagi staf), tetapi saat
 * identitas Google ditautkan lewat kecocokan email, kata sandi dan sesinya diganti: tanpa itu, penyerang yang
 * mendahului mendaftarkan email korban tetap memegang kata sandi akun korban. Penanda hilang begitu pemilik email
 * terbukti (tautan verifikasi, atur ulang kata sandi, ganti email, atau terima undangan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pengguna', function (Blueprint $tabel): void {
            $tabel->boolean('EmailBelumDibuktikan')->default(false)->after('EmailDiverifikasiPada');
        });
    }

    public function down(): void
    {
        Schema::table('Pengguna', function (Blueprint $tabel): void {
            $tabel->dropColumn('EmailBelumDibuktikan');
        });
    }
};
