<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Layanan\PencatatAuditAkun;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TokenAksesPengguna;
use App\Domain\Organisasi\Surel\KataSandiDiubah;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mengganti kata sandi dari tautan lupa kata sandi (F-00, BR-00.9). Token sekali pakai dan dihapus broker setelah
 * berhasil. Token "ingat saya" diganti, dan sesi lain berakhir karena hash kata sandi di sesi tidak cocok lagi
 * (perantara `AuthenticateSession` di rute ber-auth). Tautan dari email membuktikan kepemilikan email, sehingga email
 * yang belum terverifikasi ikut ditandai terverifikasi (BR-00.5). Token Aplikasi Owner dicabut (OWN-01). Dicatat di `LogAudit` setiap tenant anggota.
 */
final class AturUlangKataSandi
{
    public function __construct(private readonly PencatatAuditAkun $auditAkun) {}

    public function Jalankan(string $email, string $token, string $kataSandiBaru): void
    {
        $diubah = null;

        // Broker menyimpan kata sandi & menghapus token; audit ikut dalam transaksi yang sama.
        $status = DB::transaction(function () use ($email, $token, $kataSandiBaru, &$diubah): string {
            return Password::broker('users')->reset(
                ['Email' => mb_strtolower(trim($email)), 'password' => $kataSandiBaru, 'token' => $token],
                function (CanResetPassword $pengguna, string $kataSandi) use (&$diubah): void {
                    if (! $pengguna instanceof Pengguna) {
                        return;
                    }

                    $pengguna->forceFill([
                        'KataSandi' => $kataSandi,
                        'TokenIngat' => Str::random(60),
                        'KataSandiOtomatis' => false,
                        'EmailDiverifikasiPada' => $pengguna->EmailDiverifikasiPada ?? now(),
                        'EmailBelumDibuktikan' => false,
                    ])->save();
                    // OWN-01 (PRD §16): token Aplikasi Owner ikut dicabut saat kata sandi diganti.
                    TokenAksesPengguna::query()->where('IdPengguna', $pengguna->Id)->whereNull('DicabutPada')->update(['DicabutPada' => now()]);
                    $this->auditAkun->Catat('akun.kata-sandi-atur-ulang', $pengguna);
                    $diubah = $pengguna;
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET || ! $diubah instanceof Pengguna) {
            throw new PelanggaranAturanBisnis('TautanTidakValid', 'Tautan atur ulang tidak valid atau sudah kedaluwarsa. Minta tautan baru.');
        }

        try {
            Mail::to($diubah->Email)->queue(new KataSandiDiubah($diubah->Nama));
        } catch (Throwable $galat) {
            // Kata sandi sudah terganti; pemberitahuan bersifat tambahan.
            Log::error('Email pemberitahuan kata sandi diubah gagal dikirim.', ['Pesan' => $galat->getMessage()]);
        }
    }
}
