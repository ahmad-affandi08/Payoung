<?php

declare(strict_types=1);

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Lisensi\Kontrak\PengaturIntegrasiServer;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\DataSayaKontroler;
use App\Http\Kontroler\Kelola\IntegrasiServerKontroler;
use App\Http\Kontroler\Kelola\PengaturanKontroler;
use App\Http\Kontroler\Kelola\TokenApiKontroler;
use App\Http\Kontroler\Kelola\WebhookKontroler;
use App\Http\Kontroler\Kelola\WhatsappTenantKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office Pengaturan (F-01, PRD §13.6, D-06). Didaftarkan dari routes/web.php di dalam grup `/kelola`
 * (auth + IdentifikasiTenantSesi).
 *
 * Halaman indeks tanpa izin khusus: isinya disaring per butir di frontend memakai izin pengguna, sama seperti menu
 * samping. Profil usaha butuh `outlet.kelola` karena menulis data tenant (nama, NPWP, PKP, logo) dan alamat outlet.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;

Route::prefix('pengaturan')->group(function () use ($izin): void {
    Route::get('/', [PengaturanKontroler::class, 'Indeks'])->name('kelola.pengaturan');

    Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::OutletKelola)])->group(function (): void {
        Route::get('/profil-usaha', [PengaturanKontroler::class, 'TampilkanProfilUsaha'])->name('kelola.pengaturan.profil-usaha');
        Route::post('/profil-usaha', [PengaturanKontroler::class, 'SimpanProfilUsaha'])->name('kelola.pengaturan.profil-usaha.simpan');
        Route::get('/profil-usaha/logo', [PengaturanKontroler::class, 'UnduhLogo'])->name('kelola.pengaturan.profil-usaha.logo');
    });

    // Audit E5 (UU PDP): ekspor data lengkap usaha (dan penutupan akun di SaaS); khusus Pemilik.
    Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::DataKelola)])->group(function (): void {
        Route::get('/data-saya', [DataSayaKontroler::class, 'Tampilkan'])->name('kelola.pengaturan.data-saya');
        Route::post('/data-saya/ekspor', [DataSayaKontroler::class, 'Minta'])->middleware('throttle:5,1')->name('kelola.pengaturan.data-saya.ekspor');
        // Penutupan akun hanya ada di edisi SaaS (edisi Lisensi: server milik pembeli).
        if (! EdisiAplikasi::CekLisensi()) {
            Route::post('/data-saya/penghapusan', [DataSayaKontroler::class, 'Ajukan'])->middleware('throttle:5,1')->name('kelola.pengaturan.data-saya.penghapusan');
            Route::delete('/data-saya/penghapusan', [DataSayaKontroler::class, 'Batalkan'])->middleware('throttle:10,1')->name('kelola.pengaturan.data-saya.penghapusan.batal');
        }
        Route::get('/data-saya/ekspor/{uuidEkspor}/unduh', [DataSayaKontroler::class, 'Unduh'])->where('uuidEkspor', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:10,1')->name('kelola.pengaturan.data-saya.unduh');
    });

    // X7 Open API v1: token API publik (khusus Owner, paket ber-fitur `api.publik` dicek di Aksi).
    Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::IntegrasiApiKelola)])->group(function (): void {
        Route::get('/api', [TokenApiKontroler::class, 'Daftar'])->name('kelola.pengaturan.api');
        Route::post('/api', [TokenApiKontroler::class, 'Buat'])->name('kelola.pengaturan.api.buat');
        Route::delete('/api/{uuidToken}', [TokenApiKontroler::class, 'Cabut'])->where('uuidToken', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('kelola.pengaturan.api.cabut');
        // X7 bagian 2: webhook keluar.
        Route::get('/webhook', [WebhookKontroler::class, 'Daftar'])->name('kelola.pengaturan.webhook');
        Route::post('/webhook', [WebhookKontroler::class, 'Buat'])->name('kelola.pengaturan.webhook.buat');
        Route::put('/webhook/{uuidWebhook}/status', [WebhookKontroler::class, 'UbahStatus'])->where('uuidWebhook', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('kelola.pengaturan.webhook.status');
        Route::delete('/webhook/{uuidWebhook}', [WebhookKontroler::class, 'Hapus'])->where('uuidWebhook', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('kelola.pengaturan.webhook.hapus');
        Route::post('/webhook/kiriman/{uuidKiriman}/kirim-ulang', [WebhookKontroler::class, 'KirimUlang'])->where('uuidKiriman', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('kelola.pengaturan.webhook.kirim-ulang');

        // D-108: WhatsApp milik tenant (nomor sendiri, tanpa kuota Payoung) + pemantau kuota. Edisi Lisensi punya halaman
        // integrasi server sendiri di bawah, jadi tidak memakai halaman ini.
        if (! EdisiAplikasi::CekLisensi()) {
            Route::get('/whatsapp', [WhatsappTenantKontroler::class, 'Tampilkan'])->name('kelola.pengaturan.whatsapp');
            Route::post('/whatsapp', [WhatsappTenantKontroler::class, 'Simpan'])->name('kelola.pengaturan.whatsapp.simpan');
            Route::post('/whatsapp/uji', [WhatsappTenantKontroler::class, 'Uji'])->name('kelola.pengaturan.whatsapp.uji');
            Route::post('/whatsapp/aktifkan', [WhatsappTenantKontroler::class, 'Aktifkan'])->name('kelola.pengaturan.whatsapp.aktifkan');
            Route::post('/whatsapp/nonaktifkan', [WhatsappTenantKontroler::class, 'Nonaktifkan'])->name('kelola.pengaturan.whatsapp.nonaktifkan');
        }

        // D-35: email, WhatsApp, dan penyimpanan berkas server toko. Hanya edisi Lisensi; di SaaS milik konsol.
        if (EdisiAplikasi::CekLisensi()) {
            $jenis = strtolower(implode('|', PengaturIntegrasiServer::JENIS));
            Route::get('/integrasi-server', [IntegrasiServerKontroler::class, 'Daftar'])->name('kelola.pengaturan.integrasi-server');
            Route::post('/integrasi-server', [IntegrasiServerKontroler::class, 'Simpan'])->name('kelola.pengaturan.integrasi-server.simpan');
            Route::post('/integrasi-server/{jenis}/uji', [IntegrasiServerKontroler::class, 'Uji'])->where('jenis', $jenis)->name('kelola.pengaturan.integrasi-server.uji');
            Route::post('/integrasi-server/{jenis}/nonaktifkan', [IntegrasiServerKontroler::class, 'Nonaktifkan'])->where('jenis', $jenis)->name('kelola.pengaturan.integrasi-server.nonaktifkan');
        }
    });
});
