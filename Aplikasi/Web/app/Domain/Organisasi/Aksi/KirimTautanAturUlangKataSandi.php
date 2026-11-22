<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Surel\TautanAturUlangKataSandi;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Lupa kata sandi (F-00, BR-00.9): mengirim tautan atur ulang lewat broker kata sandi Laravel (token di-hash di
 * `password_reset_tokens`, berlaku `auth.passwords.users.expire` menit). Hasilnya sengaja tidak dikembalikan:
 * pemanggil selalu menampilkan pesan yang sama agar keberadaan akun tidak terbuka (§25 no. 18). Broker memakai
 * timebox sehingga waktu respons juga tidak membedakan email terdaftar atau tidak.
 */
final class KirimTautanAturUlangKataSandi
{
    public function Jalankan(string $email): void
    {
        Password::broker('users')->sendResetLink(
            ['Email' => mb_strtolower(trim($email))],
            function (CanResetPassword $pengguna, string $token): void {
                if (! $pengguna instanceof Pengguna) {
                    return;
                }

                // Alamat dasar dari konfigurasi, bukan header Host permintaan: host yang dipalsukan penyerang tidak boleh sampai ke
                // tautan resmi berisi token atur ulang (host-header poisoning).
                $tautan = AlamatDomain::BuatUrlAbsolutTenant(route('atur-ulang-kata-sandi', ['token' => $token, 'email' => $pengguna->Email], false));
                $menit = (int) config('auth.passwords.users.expire');

                try {
                    Mail::to($pengguna->Email)->queue(new TautanAturUlangKataSandi($pengguna->Nama, $tautan, $menit));
                } catch (Throwable $galat) {
                    // Galat pengiriman tidak boleh terlihat berbeda dari email yang tidak terdaftar (§25 no. 18).
                    Log::error('Email atur ulang kata sandi gagal dikirim.', ['Pesan' => $galat->getMessage()]);
                }
            },
        );
    }
}
