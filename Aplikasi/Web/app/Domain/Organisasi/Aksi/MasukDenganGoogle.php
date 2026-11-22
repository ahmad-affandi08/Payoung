<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\MasukGoogle\IdentitasGoogle;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Layanan\PencatatAuditAkun;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TokenAksesPengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-57: mencocokkan identitas Google yang sudah terverifikasi dengan akun pengguna. Urutan: (1) `GoogleSub` yang sudah
 * ditautkan; (2) email yang sama dengan akun yang ada (Google menjamin email_verified) lalu ditautkan; (3) tidak ada
 * akun → `null`, pemanggil menawarkan pendaftaran. Masuk lewat Google menggantikan 2FA, jadi pemanggil tidak meminta
 * kode TOTP.
 *
 * Menautkan lewat email mengambil alih akun yang kata sandinya tidak tepercaya: email yang belum terverifikasi (bisa
 * didaftarkan orang lain dengan email korban) atau kata sandi awal buatan admin. Kata sandi itu diganti acak
 * (`KataSandiOtomatis`), token Owner dicabut, dan "ingat saya" direset; pemilik sah mengatur kata sandi baru lewat
 * "Lupa kata sandi" bila perlu.
 */
final class MasukDenganGoogle
{
    public function __construct(
        private readonly KeanggotaanPengguna $keanggotaan,
        private readonly PencatatAuditAkun $auditAkun,
    ) {}

    /**
     * @return Pengguna|null null = belum ada akun untuk identitas ini
     *
     * @throws PelanggaranAturanBisnis akun ada tetapi tidak boleh masuk
     */
    public function Jalankan(IdentitasGoogle $identitas): ?Pengguna
    {
        $pengguna = Pengguna::query()->where('GoogleSub', $identitas->sub)->first();

        if ($pengguna === null) {
            $pengguna = Pengguna::query()->where('Email', $identitas->email)->first();

            if ($pengguna instanceof Pengguna) {
                if ($pengguna->CekGoogleTertaut()) {
                    // Email ini sudah ditautkan ke akun Google lain; jangan menimpa tautan yang ada.
                    throw new PelanggaranAturanBisnis('GoogleBerbeda', 'Email ini sudah ditautkan ke akun Google lain. Masuk dengan akun Google tersebut atau dengan kata sandi.');
                }

                $this->Tautkan($pengguna, $identitas);
            }
        }

        if (! $pengguna instanceof Pengguna) {
            return null;
        }

        if ($this->keanggotaan->AmbilIdTenant($pengguna->Id) === []) {
            throw new PelanggaranAturanBisnis('TanpaTenantAktif', 'Akun ini belum menjadi anggota aktif usaha mana pun.');
        }

        return $pengguna;
    }

    /** Menautkan akun yang sedang masuk ke identitas Google (dari Keamanan akun). */
    public function TautkanKeAkun(Pengguna $pengguna, IdentitasGoogle $identitas): void
    {
        $dipakai = Pengguna::query()->where('GoogleSub', $identitas->sub)->where('Id', '!=', $pengguna->Id)->exists();

        if ($dipakai) {
            throw new PelanggaranAturanBisnis('GoogleSudahDipakai', 'Akun Google ini sudah ditautkan ke akun Payoung lain.');
        }

        if ($pengguna->CekGoogleTertaut()) {
            throw new PelanggaranAturanBisnis('GoogleSudahTertaut', 'Akun ini sudah ditautkan ke akun Google. Lepas tautan lama dulu.');
        }

        DB::transaction(function () use ($pengguna, $identitas): void {
            $pengguna->forceFill(['GoogleSub' => $identitas->sub, 'GoogleDitautkanPada' => now()])->save();
            $this->auditAkun->Catat('akun.google-tautkan', $pengguna, ['Sumber' => 'KeamananAkun', 'EmailGoogle' => $identitas->email]);
        });
    }

    /** Melepas tautan Google. Akun tanpa kata sandi buatan pengguna tidak boleh melepas (akan terkunci). */
    public function Lepas(Pengguna $pengguna): void
    {
        if (! $pengguna->CekGoogleTertaut()) {
            return;
        }

        if ($pengguna->KataSandiOtomatis) {
            throw new PelanggaranAturanBisnis('KataSandiBelumDiatur', 'Atur kata sandi dulu (menu Lupa kata sandi atau Ganti kata sandi) sebelum melepas Google, supaya Anda tetap bisa masuk.');
        }

        DB::transaction(function () use ($pengguna): void {
            $pengguna->forceFill(['GoogleSub' => null, 'GoogleDitautkanPada' => null])->save();
            $this->auditAkun->Catat('akun.google-lepas', $pengguna);
        });
    }

    private function Tautkan(Pengguna $pengguna, IdentitasGoogle $identitas): void
    {
        DB::transaction(function () use ($pengguna, $identitas): void {
            $tidakTepercaya = $pengguna->EmailDiverifikasiPada === null || $pengguna->WajibGantiKataSandi || $pengguna->EmailBelumDibuktikan;
            $perubahan = [
                'GoogleSub' => $identitas->sub,
                'GoogleDitautkanPada' => now(),
                'EmailDiverifikasiPada' => $pengguna->EmailDiverifikasiPada ?? now(),
                'EmailBelumDibuktikan' => false,
            ];

            if ($tidakTepercaya) {
                $perubahan += [
                    'KataSandi' => Str::random(48),
                    'KataSandiOtomatis' => true,
                    'WajibGantiKataSandi' => false,
                    'TokenIngat' => Str::random(60),
                ];
                TokenAksesPengguna::query()->where('IdPengguna', $pengguna->Id)->whereNull('DicabutPada')->update(['DicabutPada' => now()]);
            }

            $pengguna->forceFill($perubahan)->save();
            $this->auditAkun->Catat('akun.google-tautkan', $pengguna, ['Sumber' => 'MasukGoogle', 'KataSandiDiganti' => $tidakTepercaya]);
        });
    }
}
