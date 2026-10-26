<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Autentikasi;

use App\Domain\Organisasi\Aksi\KirimVerifikasiEmail;
use App\Domain\Organisasi\Aksi\TerapkanGantiEmail;
use App\Domain\Organisasi\Aksi\VerifikasiEmailPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Verifikasi email dari tautan dan kirim ulang tautan (BR-00.5).
 */
final class VerifikasiEmailKontroler extends Kontroler
{
    public function Verifikasi(Request $permintaan, Pengguna $pengguna, string $hash, VerifikasiEmailPengguna $verifikasi): RedirectResponse
    {
        $verifikasi->Jalankan($pengguna, $hash);

        return redirect()->route($permintaan->user('web') === null ? 'masuk' : 'kelola.beranda')
            ->with('Kilat', 'Email Anda sudah terverifikasi.');
    }

    /** BR-00.5: konfirmasi ganti email dari tautan yang dikirim ke alamat baru. */
    public function GantiEmail(Request $permintaan, Pengguna $pengguna, string $hash, string $email, TerapkanGantiEmail $terapkan): RedirectResponse
    {
        $terapkan->Jalankan($pengguna, $hash, $email);

        return redirect()->route($permintaan->user('web') === null ? 'masuk' : 'kelola.keamanan')
            ->with('Kilat', 'Email akun Anda sudah diganti dan terverifikasi.');
    }

    public function KirimUlang(Request $permintaan, KirimVerifikasiEmail $kirim): RedirectResponse
    {
        $pengguna = $permintaan->user('web');
        abort_unless($pengguna instanceof Pengguna, 403);
        $kunci = 'verifikasi-email:'.$pengguna->Id;

        if (RateLimiter::tooManyAttempts($kunci, 3)) {
            return back()->withErrors(['Umum' => 'Tautan sudah dikirim beberapa kali. Coba lagi dalam beberapa menit.']);
        }

        RateLimiter::hit($kunci, 600);

        try {
            // Jalankan() false = email sudah terverifikasi, jadi memang tidak ada yang dikirim.
            if (! $kirim->Jalankan($pengguna)) {
                return back()->with('Kilat', 'Email Anda sudah terverifikasi, jadi tautan tidak perlu dikirim lagi.');
            }
        } catch (Throwable $galat) {
            // Sebelumnya kegagalan pengiriman tetap dilaporkan sebagai berhasil, sehingga pengguna menunggu email
            // yang tidak pernah datang dan penyebabnya hanya terlihat di log.
            Log::error('Email verifikasi gagal dikirim ulang.', ['Pesan' => $galat->getMessage()]);

            return back()->withErrors(['Umum' => 'Email verifikasi gagal dikirim. Coba lagi beberapa saat. Bila tetap gagal, hubungi dukungan.']);
        }

        return back()->with('Kilat', "Tautan verifikasi dikirim ulang ke {$pengguna->Email}.");
    }
}
