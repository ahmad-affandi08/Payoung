<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Integrasi\Layanan\PencariGerbangWebhook;
use App\Domain\Integrasi\Snap\PenerbitTokenSnap;
use App\Domain\Penjualan\Aksi\TerimaNotifikasiQris;
use App\Http\Kontroler\Kontroler;
use App\Http\Respons\GalatApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F-08 BR-08.5: notifikasi gerbang pembayaran (tanpa login/CSRF, dibatasi laju per IP).
 *
 * - v2.06 `POST /webhook/{penyedia}/{tokenWebhook}`: gerbang milik tenant. Token tidak dikenal, gerbang nonaktif, atau
 *   penyedia berbeda dari gerbang tenant = 404; isi notifikasi hanya pemicu: status ditanyakan sendiri ke DOKU SNAP (D-314), badan tanpa nomor pesanan = 400. Jalur
 *   `.../authorization/v1/access-token/b2b` menerbitkan token B2B untuk DOKU.
 *   Tagihan tidak dikenal/milik tenant lain dijawab 200 `{Diterima: false}` agar gerbang berhenti mengulang.
 * - `POST /webhook/{penyedia}` (lama, gerbang tingkat platform): sejak v2.06 tidak ada lagi gerbang platform, sehingga
 *   selalu 404 `PenyediaTidakAktif`. Rute dipertahankan agar penyedia yang masih mengirim ke alamat lama mendapat
 *   jawaban yang jelas.
 *
 * Hanya DOKU yang punya rute (batasan `where` di `routes/Webhook.php`); kode penyedia lain, termasuk penyedia lama yang
 * sudah dihapus, tidak cocok dengan rute mana pun sehingga dijawab 404.
 */
final class WebhookGerbangPembayaranKontroler extends Kontroler
{
    public function Terima(string $penyedia): JsonResponse
    {
        return GalatApi::Buat('PenyediaTidakAktif', 'Gerbang pembayaran ini tidak aktif. Pakai URL webhook dari back-office toko.', 404);
    }

    public function TerimaTenant(Request $permintaan, string $penyedia, string $tokenWebhook, PencariGerbangWebhook $pencari, TerimaNotifikasiQris $terima, PenerbitTokenSnap $penerbitToken, string $jalur = ''): JsonResponse
    {
        $gerbang = $pencari->Cari($penyedia, $tokenWebhook);

        if ($gerbang === null) {
            return GalatApi::Buat('PenyediaTidakAktif', 'Gerbang pembayaran ini tidak aktif.', 404);
        }

        // D-314: DOKU SNAP meminta token B2B ke alamat kita lebih dulu sebelum mengirim notifikasi.
        if (str_ends_with(rtrim($jalur, '/'), 'access-token/b2b')) {
            return response()->json($penerbitToken->Terbitkan());
        }

        $notifikasi = $gerbang->gerbang->UraiWebhook($permintaan);
        $pencari->CatatNotifikasi($notifikasi !== null);

        if ($notifikasi === null) {
            return GalatApi::Buat('NotifikasiTidakDikenal', 'Isi notifikasi tidak memuat nomor pesanan yang dikenal.', 400);
        }

        // Jawaban bentuk SNAP (kode 200 + layanan QRIS 52 + kasus 00) ditambah `Diterima` untuk pemakai kita sendiri.
        return response()->json([
            'responseCode' => '2005200',
            'responseMessage' => 'Successful',
            'Diterima' => $terima->Jalankan($notifikasi, $gerbang->gerbang->AmbilKode(), $gerbang->idTenant),
        ]);
    }
}
