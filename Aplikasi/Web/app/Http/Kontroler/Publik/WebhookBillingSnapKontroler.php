<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Snap\PenerbitTokenSnap;
use App\Domain\Tenant\Aksi\ProsesPemicuSnapLangganan;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-159: tujuan DOKU SNAP untuk akun induk Payoung. Dua hal di bawah satu awalan `/webhook/billing/snap/`:
 *
 * - **Token B2B** (jalur berakhiran `access-token/b2b`): DOKU meminta token lebih dulu sebelum mengirim notifikasi.
 * - **Notifikasi pembayaran** (jalur lain, misal `v1.0/transfer-va/payment`, `v1.0/qr/qr-mpm-notify`, `v1.0/debit/notify`):
 *   DOKU menambahkan jalur tetap ke URL notifikasi yang disetel di dasbor, jadi semua jalur ditampung di sini.
 *
 * Notifikasi tidak melunasi apa pun; ia hanya memicu pertanyaan status ke DOKU (`ProsesPemicuSnapLangganan`). Karena itu
 * jawabannya selalu 200 untuk badan yang masuk akal (supaya DOKU berhenti mengulang), termasuk untuk nomor pesanan yang
 * tidak dikenal.
 */
final class WebhookBillingSnapKontroler extends Kontroler
{
    /** Kunci badan yang bisa memuat nomor pesanan kita, urut dari yang paling spesifik. */
    private const KUNCI_NOMOR = ['originalPartnerReferenceNo', 'partnerReferenceNo', 'trxId', 'virtualAccountData.trxId', 'additionalInfo.trxId'];

    public function Terima(Request $permintaan, string $jalur, PenerbitTokenSnap $token, ProsesPemicuSnapLangganan $proses): JsonResponse
    {
        if (str_ends_with(rtrim($jalur, '/'), 'access-token/b2b')) {
            return response()->json($token->Terbitkan());
        }

        $badan = $permintaan->json()->all();
        $nomor = $this->CariNomorPesanan($badan);
        $hasil = $nomor !== null ? $proses->Jalankan($nomor) : ['Dikenal' => false, 'Metode' => null];

        return response()->json($this->BuatJawaban($badan, $hasil['Metode']));
    }

    /**
     * @param  array<string, mixed>  $badan
     */
    private function CariNomorPesanan(array $badan): ?string
    {
        foreach (self::KUNCI_NOMOR as $kunci) {
            $nilai = data_get($badan, $kunci);

            if (is_string($nilai) && NomorPesananBilling::Urai($nilai) !== null) {
                return $nilai;
            }
        }

        return null;
    }

    /**
     * Jawaban bentuk SNAP: `responseCode` = 200 + kode layanan + kode kasus. Notifikasi VA memuat `virtualAccountData`
     * yang dipantulkan; QRIS (kode layanan 52) dan e-wallet (56) cukup kode & pesan.
     *
     * @param  array<string, mixed>  $badan
     * @return array<string, mixed>
     */
    private function BuatJawaban(array $badan, ?string $metodeBayar): array
    {
        if (isset($badan['virtualAccountNo'])) {
            return [
                'responseCode' => '2002500',
                'responseMessage' => 'Successful',
                'virtualAccountData' => [
                    'partnerServiceId' => (string) ($badan['partnerServiceId'] ?? ''),
                    'customerNo' => (string) ($badan['customerNo'] ?? ''),
                    'virtualAccountNo' => (string) $badan['virtualAccountNo'],
                    'virtualAccountName' => (string) ($badan['virtualAccountName'] ?? ''),
                    'paymentRequestId' => (string) ($badan['paymentRequestId'] ?? ''),
                ],
            ];
        }

        $layanan = $metodeBayar === 'Qris' ? '52' : '56';

        return ['responseCode' => '200'.$layanan.'00', 'responseMessage' => 'Successful'];
    }
}
