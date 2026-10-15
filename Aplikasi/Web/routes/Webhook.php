<?php

declare(strict_types=1);

use App\Domain\Integrasi\Layanan\PencariGerbangWebhook;
use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Http\Kontroler\Publik\LaporanCspKontroler;
use App\Http\Kontroler\Publik\WebhookBillingKontroler;
use App\Http\Kontroler\Publik\WebhookBillingSnapKontroler;
use App\Http\Kontroler\Publik\WebhookDukunganMasukKontroler;
use App\Http\Kontroler\Publik\WebhookGerbangPembayaranKontroler;
use App\Http\Kontroler\Publik\WebhookPendaftaranMerchantKontroler;
use App\Http\Perantara\VerifikasiTandaTanganDukunganMasuk;
use Illuminate\Support\Facades\Route;

/*
 * Webhook masuk dari layanan luar (PRD §16.6), didaftarkan dari bootstrap/app.php dengan grup `api` (tanpa sesi/CSRF).
 * Keaslian diverifikasi per penyedia (tanda tangan/token), bukan lewat login.
 */

// Hanya DOKU (keputusan pemilik produk, menggantikan D-19): kode penyedia lain tidak cocok dengan rute = 404.
$penyedia = 'doku';

// BR-P08.11: notifikasi DOKU akun platform (tagihan langganan Payoung sendiri). Akun gerbangnya milik platform,
// jadi URL-nya tunggal tanpa token (disetel pemilik produk di dasbor DOKU) dan tenant ditentukan dari nomor pesanan.
// Didaftarkan lebih dulu agar tidak pernah tertangkap pola `/webhook/{penyedia}/{tokenWebhook}` gerbang tenant di
// bawahnya. D-35: tidak ada di edisi Lisensi.
if (! EdisiAplikasi::CekLisensi()) {
    Route::post('/webhook/billing/doku', [WebhookBillingKontroler::class, 'Terima'])
        ->middleware('throttle:webhook')
        ->name('webhook.billing.doku');

    // D-159: tujuan DOKU SNAP akun platform (token B2B + notifikasi QRIS/VA/e-wallet). Satu awalan, jalur sisanya ditampung
    // (DOKU menambahkan jalur tetap ke URL notifikasi). Notifikasi hanya memicu pertanyaan status, tidak melunasi sendiri.
    Route::post('/webhook/billing/snap/{jalur}', [WebhookBillingSnapKontroler::class, 'Terima'])
        ->where('jalur', '.*')
        ->middleware('throttle:webhook')
        ->name('webhook.billing.snap');

    // Callback KYB DOKU Partner API (pendaftaran merchant tenant). `callback_url` dikirim otomatis saat registrasi; dua
    // segmen `pembayaran/doku-partner` tidak bentrok dengan pola gerbang tenant (penyedia dibatasi `doku`).
    Route::post('/webhook/pembayaran/doku-partner', [WebhookPendaftaranMerchantKontroler::class, 'Terima'])
        ->middleware('throttle:webhook')
        ->name('webhook.pembayaran.doku-partner');

    // PGL-15: pesan email/WhatsApp masuk menjadi tiket/balasan dukungan. Tanda tangan HMAC (rahasia `DUKUNGAN_MASUK_RAHASIA`
    // kosong = 404); dua segmen `dukungan/masuk` tidak bentrok dengan pola gerbang tenant (penyedia dibatasi `doku`).
    Route::post('/webhook/dukungan/masuk', [WebhookDukunganMasukKontroler::class, 'Terima'])
        ->middleware(['throttle:webhook-dukungan', VerifikasiTandaTanganDukunganMasuk::class])
        ->name('webhook.dukungan.masuk');
}

// F-08 BR-08.5, v2.06: notifikasi gerbang pembayaran QRIS dinamis milik tenant (kode adaptor huruf kecil + token
// webhook tenant). URL ini ditampilkan di back-office tenant untuk disalin ke dasbor penyedia.
Route::post('/webhook/{penyedia}/{tokenWebhook}', [WebhookGerbangPembayaranKontroler::class, 'TerimaTenant'])
    ->where(['penyedia' => $penyedia, 'tokenWebhook' => PencariGerbangWebhook::POLA_TOKEN])
    ->middleware('throttle:webhook')
    ->name('webhook.gerbang-pembayaran.tenant');

// Rute lama gerbang tingkat platform (sebelum v2.06): selalu 404 `PenyediaTidakAktif`.
Route::post('/webhook/{penyedia}', [WebhookGerbangPembayaranKontroler::class, 'Terima'])
    ->where('penyedia', $penyedia)
    ->middleware('throttle:webhook')
    ->name('webhook.gerbang-pembayaran');

// Audit PAY-P1-04: laporan pelanggaran CSP Report-Only dari peramban (`report-uri`), publik & tanpa sesi.
Route::post('/laporan-csp', [LaporanCspKontroler::class, 'Terima'])
    ->middleware('throttle:laporan-csp')
    ->name('publik.laporan-csp');
