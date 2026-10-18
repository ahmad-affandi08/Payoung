<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Tenant\Aksi\TerimaNotifikasiBillingLangganan;
use App\Http\Kontroler\Kontroler;
use App\Http\Respons\GalatApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BR-P08.11: notifikasi DOKU untuk tagihan langganan Payoung (tanpa login/CSRF, dibatasi laju). URL-nya tetap
 * `/webhook/billing/doku` karena disetel pemilik produk di dasbor DOKU akun platform.
 *
 * Berbeda dari `WebhookGerbangPembayaranKontroler` (QRIS milik toko, D-19) yang URL-nya bertoken per tenant: akun
 * gerbang di sini milik platform, jadi URL-nya tunggal dan tenant ditentukan dari nomor pesanan (`invoice_number`)
 * di dalam notifikasi.
 *
 * Aksinya berada di domain Tenant, bukan Pengelola: endpoint ini publik tanpa login, dan test arsitektur
 * `PengelolaTes` melarang kode di luar Platform Pengelola memakai domain Pengelola. Tenant ditetapkan dari nomor
 * pesanan sehingga pembayaran dicari lewat scope `MilikTenant` seperti biasa.
 *
 * - `Client-Id` bukan milik platform, tanda tangan (atas path & badan mentah) tidak sah, atau gerbang belum
 *   dikonfigurasi = 401. DOKU akan mengulang, yang memang diinginkan bila penyebabnya kredensial yang belum terpasang.
 * - Isi notifikasi tidak dipercaya sendirian: statusnya dikonfirmasi ke API status DOKU. DOKU tidak bisa dihubungi atau
 *   statusnya belum sama = 503 supaya DOKU mengulang (rekonsiliasi 15 menit menjadi jaring pengaman).
 * - Nomor pesanan yang sah tetapi tidak dikenal dijawab 200 `{Diterima: false}` supaya gerbang berhenti mengulang.
 */
final class WebhookBillingKontroler extends Kontroler
{
    public function Terima(Request $permintaan, PenyediaTagihanPlatform $penyedia, TerimaNotifikasiBillingLangganan $terima): JsonResponse
    {
        if (! $penyedia->CekTandaTanganSah($permintaan)) {
            return GalatApi::Buat('TandaTanganTidakSah', 'Tanda tangan notifikasi tidak sah.', 401);
        }

        $notifikasi = $penyedia->UraiNotifikasi($permintaan);

        if ($notifikasi === null) {
            return response()->json(['Diterima' => false]);
        }

        $terkonfirmasi = $penyedia->Konfirmasi($notifikasi);

        if ($terkonfirmasi === null) {
            return GalatApi::Buat('StatusBelumTerkonfirmasi', 'Status pembayaran belum bisa dikonfirmasi ke penyedia pembayaran. Coba kirim ulang.', 503);
        }

        return response()->json(['Diterima' => $terima->Jalankan($terkonfirmasi)]);
    }
}
