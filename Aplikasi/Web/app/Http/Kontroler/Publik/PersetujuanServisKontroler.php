<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bengkel\Aksi\PutuskanPersetujuanServis;
use App\Domain\Bengkel\Enum\LewatPersetujuan;
use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Kueri\DetailPerintahKerja;
use App\Domain\Bengkel\Layanan\TautanPersetujuanServis;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\UrlLogoPublik;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Persetujuan estimasi servis tanpa akun `/{slugTenant}/servis/{token}` (§9.10, "persetujuan pelanggan via WA link"):
 * pelanggan melihat jasa & sparepart beserta perkiraan totalnya, lalu menyetujui sebagian/semua baris atau menolak.
 * Tautan tidak dikenal, diganti, atau lewat 7 hari = 404 yang sama. Setelah diputuskan halaman tetap menampilkan
 * hasilnya (tanpa tombol) sampai tautannya kedaluwarsa. Data yang tampil sengaja minimal: tanpa nomor HP, alamat,
 * maupun nama mekanik.
 */
final class PersetujuanServisKontroler extends Kontroler
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
    ) {}

    public function Tampilkan(string $slugTenant, string $tokenServis, DetailPerintahKerja $detail): SymfonyResponse
    {
        return $this->DenganPerintahKerja($slugTenant, $tokenServis, function (PerintahKerja $pk, int $idTenant) use ($detail, $slugTenant, $tokenServis): SymfonyResponse {
            $d = $detail->Ambil($pk);

            return Inertia::render('Publik/PersetujuanServis', [
                'NamaToko' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
                'UrlLogo' => app(UrlLogoPublik::class)->Ambil($idTenant),
                'AlamatDasar' => TautanPersetujuanServis::Buat($slugTenant, $tokenServis),
                'PerintahKerja' => [
                    'Nomor' => $d['Nomor'],
                    'Status' => $d['Status'],
                    'LabelStatus' => $d['LabelStatus'],
                    'NamaPelanggan' => $d['Pelanggan']['Nama'],
                    'Kendaraan' => $d['Kendaraan'] === null ? null : ['Jenis' => $d['Kendaraan']['Jenis'], 'NomorPolisi' => $d['Kendaraan']['NomorPolisi'], 'Label' => $d['Kendaraan']['Label']],
                    'KmMasuk' => $d['KmMasuk'],
                    'Keluhan' => $d['Keluhan'],
                    'Diagnosis' => $d['Diagnosis'],
                    'EstimasiSelesaiPada' => $d['EstimasiSelesaiPada'],
                    'Subtotal' => $d['Subtotal'],
                    'Diskon' => $d['Diskon'],
                    'Pajak' => $d['Pajak'],
                    'Total' => $d['Total'],
                    'TotalDisetujui' => $d['TotalDisetujui'],
                    'BolehDiputuskan' => $pk->Status === StatusPerintahKerja::MenungguPersetujuan,
                    'DiputuskanPada' => $d['Persetujuan']['DiputuskanPada'],
                    'CatatanPelanggan' => $d['Persetujuan']['CatatanPelanggan'],
                    'Baris' => array_map(fn (array $b): array => [
                        'Uuid' => $b['Uuid'],
                        'Jenis' => $b['Jenis'],
                        'NamaProduk' => $b['NamaProduk'],
                        'Jumlah' => $b['Jumlah'],
                        'SimbolSatuan' => $b['SimbolSatuan'],
                        'HargaSatuan' => $b['HargaSatuan'],
                        'Diskon' => $b['Diskon'],
                        'Subtotal' => $b['Subtotal'],
                        'Catatan' => $b['Catatan'],
                        'Disetujui' => $b['Disetujui'],
                    ], $d['Baris']),
                ],
            ])->toResponse(request());
        });
    }

    public function Setujui(Request $permintaan, string $slugTenant, string $tokenServis, PutuskanPersetujuanServis $putuskan): SymfonyResponse
    {
        $valid = $permintaan->validate([
            'Baris' => ['required', 'array', 'min:1', 'max:100'],
            'Baris.*' => ['string', 'ulid'],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], ['Baris.required' => 'Pilih minimal satu pekerjaan atau sparepart yang disetujui.', 'Baris.min' => 'Pilih minimal satu pekerjaan atau sparepart yang disetujui.']);

        return $this->DenganPerintahKerja($slugTenant, $tokenServis, function (PerintahKerja $pk) use ($putuskan, $valid, $permintaan): RedirectResponse {
            $putuskan->Jalankan($pk->Uuid, true, array_values(array_map('strval', (array) $valid['Baris'])), LewatPersetujuan::Tautan, null, TautanPersetujuanServis::HashIp($permintaan->ip()), isset($valid['Catatan']) ? (string) $valid['Catatan'] : null);

            return back()->with('Kilat', 'Terima kasih, persetujuan Anda sudah kami terima. Bengkel akan mulai mengerjakannya.');
        });
    }

    public function Tolak(Request $permintaan, string $slugTenant, string $tokenServis, PutuskanPersetujuanServis $putuskan): SymfonyResponse
    {
        $valid = $permintaan->validate(['Catatan' => ['nullable', 'string', 'max:255']]);

        return $this->DenganPerintahKerja($slugTenant, $tokenServis, function (PerintahKerja $pk) use ($putuskan, $valid, $permintaan): RedirectResponse {
            $putuskan->Jalankan($pk->Uuid, false, [], LewatPersetujuan::Tautan, null, TautanPersetujuanServis::HashIp($permintaan->ip()), isset($valid['Catatan']) ? (string) $valid['Catatan'] : null);

            return back()->with('Kilat', 'Penolakan Anda sudah kami terima. Bengkel akan menghubungi Anda.');
        });
    }

    /** @param Closure(PerintahKerja, int): SymfonyResponse $kerja */
    private function DenganPerintahKerja(string $slugTenant, string $token, Closure $kerja): SymfonyResponse
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);
        abort_if($idTenant === null || strlen($token) !== TautanPersetujuanServis::PANJANG_TOKEN, 404);
        $this->konteks->Atur($idTenant);

        try {
            $pk = PerintahKerja::query()->where('HashTokenPersetujuan', TautanPersetujuanServis::Hash($token))->first();
            abort_if(! $pk instanceof PerintahKerja || ! $pk->CekTautanBerlaku(), 404);

            return $kerja($pk, $idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
