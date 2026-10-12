<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Dukungan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Aksi\AkhiriSesiAksesDukungan;
use App\Domain\Dukungan\Aksi\TukarTokenAksesDukungan;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\IdentifikasiTenantSesi;
use App\Http\Perantara\PasangAksesDukungan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sisi tenant dari akses dukungan (PGL-16): menukar token dari konsol dengan sesi back-office, mengakhiri sesi, dan
 * halaman "sesi berakhir". Konsol dan back-office berbeda domain (cookie terpisah), jadi sesi tidak bisa dibagi; token
 * sekali pakai berumur 60 detik yang menjadi jembatannya.
 */
final class SesiDukunganKontroler extends Kontroler
{
    public function Masuk(Request $permintaan, int $idTenant, string $token, KonteksTenant $konteks, TukarTokenAksesDukungan $tukar): RedirectResponse
    {
        $konteks->Atur($idTenant);

        try {
            ['Sesi' => $sesi, 'IdPengguna' => $idPengguna] = $tukar->Jalankan($token, $permintaan->ip(), $permintaan->userAgent());
        } catch (PelanggaranAturanBisnis) {
            return redirect()->route('dukungan.berakhir', ['alasan' => 'TokenTidakSah']);
        }

        $pengguna = Pengguna::query()->findOrFail($idPengguna);
        Auth::guard('web')->login($pengguna);
        $permintaan->session()->regenerate();
        $permintaan->session()->put(IdentifikasiTenantSesi::KUNCI_SESI, $idTenant);
        $permintaan->session()->put(PasangAksesDukungan::KUNCI_SESI, $sesi->Id);

        return redirect()->route('kelola.beranda');
    }

    /** Petugas menekan "Akhiri sesi": sesi ditutup, login dilepas, sesi peramban dibuang. */
    public function Akhiri(Request $permintaan, AkhiriSesiAksesDukungan $akhiri): RedirectResponse
    {
        $idSesi = $permintaan->session()->get(PasangAksesDukungan::KUNCI_SESI);
        $sesi = is_int($idSesi) ? SesiAksesDukungan::query()->whereKey($idSesi)->first() : null;

        if ($sesi instanceof SesiAksesDukungan) {
            $akhiri->Jalankan($sesi, AlasanAkhirSesiDukungan::Selesai);
        }

        Auth::guard('web')->logout();
        $permintaan->session()->invalidate();
        $permintaan->session()->regenerateToken();

        return redirect()->route('dukungan.berakhir', ['alasan' => AlasanAkhirSesiDukungan::Selesai->value]);
    }

    public function Berakhir(Request $permintaan): Response
    {
        $alasan = (string) $permintaan->query('alasan', 'Selesai');
        $pesan = match ($alasan) {
            'Dicabut' => 'Owner mencabut izin akses dukungan, atau akun Anda dinonaktifkan. Sesi ditutup.',
            'Kedaluwarsa' => 'Waktu akses dukungan habis. Minta tautan masuk baru dari konsol bila izinnya masih berlaku.',
            'Digantikan' => 'Sesi ini digantikan sesi dukungan yang lebih baru.',
            'TokenTidakSah' => 'Tautan akses dukungan tidak berlaku atau sudah dipakai. Minta tautan baru dari konsol.',
            default => 'Sesi akses dukungan sudah berakhir.',
        };

        return Inertia::render('Autentikasi/AksesDukunganBerakhir', ['Pesan' => $pesan]);
    }
}
