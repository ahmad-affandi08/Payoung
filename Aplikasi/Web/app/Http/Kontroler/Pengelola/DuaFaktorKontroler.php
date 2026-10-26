<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola;

use App\Domain\Pengelola\TimInternal\Aksi\AktifkanDuaFaktor;
use App\Domain\Pengelola\TimInternal\Aksi\VerifikasiDuaFaktor;
use App\Domain\Pengelola\TimInternal\Layanan\DuaFaktorPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PenjagaPerangkatTepercaya;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\Pengelola\SesiPengelola;
use App\Http\Permintaan\Pengelola\KodeDuaFaktorPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aktivasi & verifikasi 2FA wajib (P-01 langkah 4, BR-P01.2, AC P-01).
 * D-42: saat verifikasi, anggota boleh mempercayai browser-nya 90 hari; aksi berbahaya meminta konfirmasi kode lagi
 * bila kode terakhir dimasukkan lebih dari 15 menit lalu (lihat WajibDuaFaktorBaru).
 */
final class DuaFaktorKontroler extends Kontroler
{
    private const MAKS_PERCOBAAN = 5;

    public function TampilkanAktivasi(Request $permintaan, DuaFaktorPengelola $duaFaktor): Response|RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk();

        if ($pengguna->CekDuaFaktorAktif()) {
            return redirect()->route('pengelola.beranda');
        }

        $rahasia = $permintaan->session()->get(SesiPengelola::RAHASIA_2FA_SEMENTARA);

        if (! is_string($rahasia)) {
            $rahasia = $duaFaktor->BuatRahasia();
            $permintaan->session()->put(SesiPengelola::RAHASIA_2FA_SEMENTARA, $rahasia);
        }

        return Inertia::render('Pengelola/DuaFaktor/Aktifkan', [
            'QrSvg' => $duaFaktor->BuatQrSvg($duaFaktor->BuatUrlOtp($pengguna->Email, $rahasia)),
            'Rahasia' => trim(chunk_split($rahasia, 4, ' ')),
        ]);
    }

    public function Aktifkan(KodeDuaFaktorPermintaan $permintaan, AktifkanDuaFaktor $aktifkan): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk();
        $this->BatasiPercobaan('pengelola-2fa-aktifkan:'.$pengguna->Id);

        $rahasia = $permintaan->session()->get(SesiPengelola::RAHASIA_2FA_SEMENTARA);

        if (! is_string($rahasia)) {
            return redirect()->route('pengelola.dua-faktor.aktifkan');
        }

        $kodePemulihan = $aktifkan->Jalankan($pengguna, $rahasia, $permintaan->string('Kode')->toString());

        $sesi = $permintaan->session();
        $sesi->forget(SesiPengelola::RAHASIA_2FA_SEMENTARA);
        $sesi->migrate(true);
        $sesi->put(SesiPengelola::DUA_FAKTOR_TERVERIFIKASI, true);
        $sesi->put(SesiPengelola::KODE_DUA_FAKTOR_PADA, now()->getTimestamp());
        $sesi->flash(SesiPengelola::KODE_PEMULIHAN_BARU, $kodePemulihan);

        return redirect()->route('pengelola.dua-faktor.kode-pemulihan');
    }

    public function TampilkanKodePemulihan(Request $permintaan): Response|RedirectResponse
    {
        $kode = $permintaan->session()->get(SesiPengelola::KODE_PEMULIHAN_BARU);

        if (! is_array($kode)) {
            return redirect()->route('pengelola.beranda');
        }

        return Inertia::render('Pengelola/DuaFaktor/KodePemulihan', ['KodePemulihan' => array_values($kode)]);
    }

    public function TampilkanVerifikasi(Request $permintaan): Response|RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk();

        if (! $pengguna->CekDuaFaktorAktif()) {
            return redirect()->route('pengelola.dua-faktor.aktifkan');
        }

        if ($permintaan->session()->get(SesiPengelola::DUA_FAKTOR_TERVERIFIKASI) === true) {
            return redirect()->route('pengelola.beranda');
        }

        return Inertia::render('Pengelola/DuaFaktor/Verifikasi', [
            'HariPerangkatTepercaya' => PenjagaPerangkatTepercaya::HariBerlaku(),
        ]);
    }

    public function Verifikasi(
        KodeDuaFaktorPermintaan $permintaan,
        VerifikasiDuaFaktor $verifikasi,
        PenjagaPerangkatTepercaya $perangkatTepercaya,
    ): RedirectResponse {
        $pengguna = $this->PenggunaMasuk();
        $kunciBatas = 'pengelola-2fa-verifikasi:'.$pengguna->Id;
        $this->BatasiPercobaan($kunciBatas);

        $verifikasi->Jalankan($pengguna, $permintaan->string('Kode')->toString());

        RateLimiter::clear($kunciBatas);
        $sesi = $permintaan->session();
        $sesi->migrate(true);
        $sesi->put(SesiPengelola::DUA_FAKTOR_TERVERIFIKASI, true);
        $sesi->put(SesiPengelola::KODE_DUA_FAKTOR_PADA, now()->getTimestamp());

        if ($permintaan->boolean('PercayaiPerangkat')) {
            $nilai = $perangkatTepercaya->Terbitkan($pengguna, $permintaan->userAgent(), $permintaan->ip());
            Cookie::queue(Cookie::make(
                (string) config('pengelola.CookiePerangkatTepercaya'),
                $nilai,
                PenjagaPerangkatTepercaya::HariBerlaku() * 24 * 60,
                '/',
                null,
                (bool) config('session.secure'),
                true,
                false,
                'lax',
            ));
        }

        return redirect()->intended(route('pengelola.beranda'));
    }

    /** D-42: konfirmasi kode 2FA sebelum aksi berbahaya (sudo mode), meski masuk lewat perangkat tepercaya. */
    public function TampilkanKonfirmasi(): Response
    {
        return Inertia::render('Pengelola/DuaFaktor/Verifikasi', [
            'Konfirmasi' => true,
            'MenitKonfirmasi' => (int) config('pengelola.MenitKonfirmasiDuaFaktor'),
        ]);
    }

    public function Konfirmasi(KodeDuaFaktorPermintaan $permintaan, VerifikasiDuaFaktor $verifikasi): RedirectResponse|JsonResponse
    {
        $pengguna = $this->PenggunaMasuk();
        $kunciBatas = 'pengelola-2fa-verifikasi:'.$pengguna->Id;
        $this->BatasiPercobaan($kunciBatas);

        $verifikasi->Jalankan($pengguna, $permintaan->string('Kode')->toString(), konfirmasiAksi: true);

        RateLimiter::clear($kunciBatas);
        $sesi = $permintaan->session();
        $sesi->put(SesiPengelola::KODE_DUA_FAKTOR_PADA, now()->getTimestamp());
        $kembali = $sesi->pull(SesiPengelola::KEMBALI_SETELAH_KONFIRMASI);

        // Dialog konfirmasi di tempat (fetch JSON): cukup kabar berhasil, halaman asal tidak ditinggalkan.
        if ($permintaan->expectsJson() && $permintaan->header('X-Inertia') === null) {
            return response()->json(['Berhasil' => true]);
        }

        // Alamat kembali bisa berasal dari Referer: hanya host konsol ini yang diikuti (tanpa open redirect).
        $aman = is_string($kembali) && parse_url($kembali, PHP_URL_HOST) === $permintaan->getHost();

        return redirect()->to($aman ? $kembali : route('pengelola.beranda'))->with(
            'Kilat',
            'Kode diterima. Ulangi aksi tadi. Selama '.(int) config('pengelola.MenitKonfirmasiDuaFaktor').' menit ke depan kode tidak diminta lagi.',
        );
    }

    private function PenggunaMasuk(): PenggunaPengelola
    {
        $pengguna = Auth::guard(SesiPengelola::GUARD)->user();
        abort_unless($pengguna instanceof PenggunaPengelola, 403);

        return $pengguna;
    }

    private function BatasiPercobaan(string $kunci): void
    {
        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            throw ValidationException::withMessages([
                'Kode' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
            ]);
        }

        RateLimiter::hit($kunci);
    }
}
