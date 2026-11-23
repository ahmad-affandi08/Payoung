<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-314: gerbang QRIS toko pindah dari DOKU Checkout ke DOKU SNAP. Gerbang tersimpan yang belum punya Merchant ID QRIS
 * (isian khas SNAP) dinonaktifkan dan dikembalikan ke `BelumDiuji`, supaya kasir tidak membuat QRIS dengan kredensial
 * Checkout yang tidak lagi dipakai. Kredensial tidak dihapus: tenant cukup melengkapi Merchant ID, Terminal ID, kode pos,
 * menyimpan ulang (kunci SNAP dibuat otomatis), mendaftarkan kunci publik di dasbor DOKU, lalu menguji dan mengaktifkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('GerbangPembayaranTenant')) {
            return;
        }

        foreach (DB::table('GerbangPembayaranTenant')->get(['Id', 'Pengaturan']) as $baris) {
            $pengaturan = json_decode((string) $baris->Pengaturan, true);

            if (is_array($pengaturan) && trim((string) ($pengaturan['IdPedagangQris'] ?? '')) !== '') {
                continue;
            }

            DB::table('GerbangPembayaranTenant')->where('Id', $baris->Id)->update([
                'Aktif' => false,
                'StatusUji' => 'BelumDiuji',
                'PesanUji' => 'Gerbang pindah ke DOKU SNAP. Lengkapi Merchant ID, Terminal ID, dan kode pos QRIS, lalu daftarkan kunci publik di dasbor DOKU.',
                'DiujiPada' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Tidak ada yang perlu dipulihkan: gerbang yang dinonaktifkan diaktifkan ulang lewat uji koneksi.
    }
};
