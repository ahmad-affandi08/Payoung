<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Organisasi\Aksi\AktifkanPerangkat;
use App\Domain\Organisasi\Aksi\CatatLaporanGalatPerangkat;
use App\Domain\Organisasi\Aksi\SimpanProfilHardware;
use App\Domain\Organisasi\Aksi\TerimaLogPerangkat;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use App\Domain\Tenant\Kueri\StatusLanggananTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Pos\V1\AktivasiPerangkatPermintaan;
use App\Http\Respons\Pos\V1\PerangkatPosRespons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/pos/v1/perangkat/aktivasi` (F-02 langkah 5, §16.3): tukar kode aktivasi → token perangkat, kode perangkat
 * untuk penomoran offline, info outlet & tenant. Token hanya dikirim sekali; aplikasi menyimpannya di secure storage.
 * `POST /api/pos/v1/perangkat/profil-hardware` (v1.96): laporan profil hardware & hasil Wizard Uji Perangkat.
 * `POST /api/pos/v1/perangkat/galat` (K-21): kiriman log galat aplikasi (maks. 50 per kiriman) ke kanal log harian.
 */
final class PerangkatKontroler extends Kontroler
{
    public function Aktivasi(
        AktivasiPerangkatPermintaan $permintaan,
        AktifkanPerangkat $aktifkan,
        RingkasanTenant $ringkasanTenant,
        StatusLanggananTenant $statusLangganan,
        PencatatAudit $audit,
    ): JsonResponse {
        $audit->AturKonteks(null, $permintaan->ip(), $permintaan->userAgent());
        $hasil = $aktifkan->Jalankan($permintaan->AmbilData());
        $idTenant = $hasil['Perangkat']->IdTenant;
        $status = $statusLangganan->Ambil($idTenant);

        return response()->json([
            'TokenPerangkat' => $hasil['TokenPerangkat'],
            // F-06 (§25.2 no. 3): kunci pembungkus verifier PIN offline, dikirim sekali; aplikasi menyimpannya di
            // secure storage (Keystore/Keychain/DPAPI). Tambahan kontrak yang kompatibel mundur.
            'KunciPinOffline' => $hasil['Perangkat']->KunciPinOffline,
            'Perangkat' => PerangkatPosRespons::Perangkat($hasil['Perangkat']),
            'Outlet' => PerangkatPosRespons::Outlet($hasil['Outlet']),
            'Tenant' => PerangkatPosRespons::Tenant($ringkasanTenant->Ambil([$idTenant])[0] ?? null),
            'Langganan' => PerangkatPosRespons::Langganan($status, $statusLangganan->CekBolehBertransaksiPos($status)),
        ], 201);
    }

    public function SimpanProfilHardware(Request $permintaan, SimpanProfilHardware $simpan): JsonResponse
    {
        $hasilUji = ['nullable', Rule::in(['Lolos', 'Gagal', 'Dilewati'])];
        $data = $permintaan->validate([
            'Produsen' => ['nullable', 'string', 'max:100'],
            'Model' => ['nullable', 'string', 'max:100'],
            'Sistem' => ['nullable', 'string', 'max:100'],
            'Adaptor' => ['nullable', 'string', 'max:30'],
            'Printer' => ['nullable', 'array:Jenis,Nama,Lebar'],
            'Printer.Jenis' => ['nullable', 'string', 'max:30'],
            'Printer.Nama' => ['nullable', 'string', 'max:150'],
            'Printer.Lebar' => ['nullable', 'string', 'max:10'],
            'Uji' => ['nullable', 'array:Cetak,Potong,Laci,Pemindai'],
            'Uji.Cetak' => $hasilUji,
            'Uji.Potong' => $hasilUji,
            'Uji.Laci' => $hasilUji,
            'Uji.Pemindai' => $hasilUji,
            'DiujiPada' => ['nullable', 'date'],
        ]);
        $simpan->Jalankan(AutentikasiPerangkat::AmbilPerangkat($permintaan), $data);

        return response()->json(['Tersimpan' => true]);
    }

    public function LaporGalat(Request $permintaan, CatatLaporanGalatPerangkat $catat): JsonResponse
    {
        $data = $permintaan->validate([
            'Galat' => ['required', 'array', 'min:1', 'max:50'],
            'Galat.*' => ['array:Waktu,Tingkat,Sumber,Pesan,Jejak'],
            'Galat.*.Waktu' => ['required', 'date'],
            'Galat.*.Tingkat' => ['required', Rule::in(['Galat', 'Peringatan'])],
            'Galat.*.Sumber' => ['required', 'string', 'max:60'],
            'Galat.*.Pesan' => ['required', 'string', 'max:500'],
            'Galat.*.Jejak' => ['nullable', 'string', 'max:4000'],
        ]);
        $versi = $permintaan->header('X-Versi-Aplikasi');

        /** @var list<array{Waktu: string, Tingkat: string, Sumber: string, Pesan: string, Jejak?: string|null}> $galat */
        $galat = array_values($data['Galat']);

        return response()->json(['Diterima' => $catat->Jalankan(
            AutentikasiPerangkat::AmbilPerangkat($permintaan),
            $galat,
            is_string($versi) ? mb_substr(trim($versi), 0, 40) : null,
        )]);
    }

    /**
     * `POST /api/pos/v1/perangkat/log` (PGL-16 bagian 2): log lokal yang diminta dukungan. Ditolak 409 `LogTidakDiminta` bila
     * tidak ada permintaan yang berlaku.
     */
    public function UnggahLog(Request $permintaan, TerimaLogPerangkat $terima): JsonResponse
    {
        $data = $permintaan->validate([
            'Baris' => ['required', 'array', 'min:1', 'max:'.TerimaLogPerangkat::MAKS_BARIS],
            'Baris.*' => ['required', 'string', 'max:2000'],
        ]);
        $versi = $permintaan->header('X-Versi-Aplikasi');

        /** @var list<string> $baris */
        $baris = array_values($data['Baris']);
        $log = $terima->Jalankan(
            AutentikasiPerangkat::AmbilPerangkat($permintaan),
            $baris,
            is_string($versi) ? mb_substr(trim($versi), 0, 30) : null,
        );

        return response()->json(['Diterima' => $log->JumlahBaris]);
    }
}
