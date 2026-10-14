<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Dukungan\Aksi\TerimaPesanMasukDukungan;
use App\Domain\Dukungan\Data\DataPesanMasuk;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Http\Kontroler\Kontroler;
use App\Http\Respons\GalatApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Pintu masuk pesan email/WhatsApp ke tiket dukungan (P-09, PGL-15): `POST /webhook/dukungan/masuk`, tanpa sesi/CSRF dan
 * dibatasi laju. Keaslian lewat tanda tangan HMAC (`VerifikasiTandaTanganDukunganMasuk`). Bentuk badannya seragam untuk
 * semua penyedia; adaptor penyedia (mis. Mailgun/SendGrid/BSP WhatsApp) cukup menerjemahkan payload mereka ke bentuk ini.
 *
 * Pesan yang valid tetapi tidak bisa dijadikan tiket (pengirim tak dikenal, usaha ambigu, tiket tertutup, …) dijawab
 * 200 dengan `Hasil: Ditolak` dan kodenya, bukan galat, supaya penyedia berhenti mengulang pesan yang memang tidak akan
 * pernah berhasil. Hanya badan yang cacat (422) atau tanda tangan yang salah (401) yang berstatus galat.
 */
final class WebhookDukunganMasukKontroler extends Kontroler
{
    public function Terima(Request $permintaan, TerimaPesanMasukDukungan $terima): JsonResponse
    {
        $validator = Validator::make($permintaan->json()->all(), [
            'Kanal' => ['required', 'string', 'in:Email,WhatsApp'],
            'Dari' => ['required', 'string', 'max:254'],
            'IdPesan' => ['required', 'string', 'max:190'],
            'Subjek' => ['nullable', 'string', 'max:998'],
            'Isi' => ['required', 'string', 'max:100000'],
            'NomorTiket' => ['nullable', 'string', 'regex:/^TKT-\d{4}-\d{6}$/'],
            'Terverifikasi' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return GalatApi::Buat('ValidasiGagal', $validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $hasil = $terima->Jalankan(new DataPesanMasuk(
            kanal: KanalTiketDukungan::from((string) $data['Kanal']),
            dari: (string) $data['Dari'],
            idPesan: (string) $data['IdPesan'],
            subjek: (string) ($data['Subjek'] ?? ''),
            isi: (string) $data['Isi'],
            terverifikasi: (bool) ($data['Terverifikasi'] ?? false),
            nomorTiket: isset($data['NomorTiket']) ? (string) $data['NomorTiket'] : null,
        ));

        return response()->json($hasil->KeLarik());
    }
}
