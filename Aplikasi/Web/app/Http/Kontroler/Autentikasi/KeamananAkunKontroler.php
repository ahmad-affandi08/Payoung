<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Autentikasi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\MasukGoogle\KonfigurasiGoogle;
use App\Domain\Organisasi\Aksi\AktifkanDuaFaktorPengguna;
use App\Domain\Organisasi\Aksi\KirimTautanGantiEmail;
use App\Domain\Organisasi\Aksi\MasukDenganGoogle;
use App\Domain\Organisasi\Aksi\NonaktifkanDuaFaktorPengguna;
use App\Domain\Organisasi\Layanan\DuaFaktorPengguna;
use App\Domain\Organisasi\Layanan\PenentuWajibDuaFaktor;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\SesiAutentikasiTenant;
use App\Http\Permintaan\Autentikasi\GantiEmailPermintaan;
use App\Http\Permintaan\Autentikasi\KodeDuaFaktorPermintaan;
use App\Http\Permintaan\Autentikasi\NonaktifkanDuaFaktorPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Keamanan akun di back-office (`/kelola/keamanan`): aktivasi 2FA TOTP (QR + konfirmasi kode), kode pemulihan yang
 * ditampilkan sekali, dan nonaktifkan dengan konfirmasi kata sandi (§20.2, BR-00.8). 2FA milik akun pengguna, bukan
 * tenant; 2FA wajib bila salah satu tenant tempat pengguna menjadi anggota aktif mewajibkannya untuk perannya.
 */
final class KeamananAkunKontroler extends Kontroler
{
    private const MAKS_PERCOBAAN = 5;

    public function __construct(
        private readonly PenentuWajibDuaFaktor $penentuWajib,
        private readonly KonfigurasiGoogle $konfigurasiGoogle,
    ) {}

    public function Tampilkan(Request $permintaan, DuaFaktorPengguna $duaFaktor): Response
    {
        $pengguna = $this->PenggunaMasuk($permintaan);
        $sesi = $permintaan->session();
        $aktivasi = null;

        if (! $pengguna->CekDuaFaktorAktif()) {
            $rahasia = $sesi->get(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);

            if (! is_string($rahasia)) {
                $rahasia = $duaFaktor->BuatRahasia();
                $sesi->put(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA, $rahasia);
            }

            $aktivasi = [
                'QrSvg' => $duaFaktor->BuatQrSvg($duaFaktor->BuatUrlOtp((string) $pengguna->Email, $rahasia)),
                'Rahasia' => trim(chunk_split($rahasia, 4, ' ')),
            ];
        }

        $kodeBaru = $sesi->get(SesiAutentikasiTenant::KODE_PEMULIHAN_BARU);

        return Inertia::render('Autentikasi/KeamananAkun', [
            'Akun' => [
                'Email' => $pengguna->Email,
                'EmailTerverifikasi' => $pengguna->EmailDiverifikasiPada !== null,
                'EmailDiverifikasiPada' => $pengguna->EmailDiverifikasiPada?->toIso8601String(),
                // Akun buatan Google belum punya kata sandi: atur dulu agar konfirmasi ganti email punya jaminan.
                'BisaGantiEmail' => ! $pengguna->KataSandiOtomatis,
            ],
            'DuaFaktor' => [
                'Aktif' => $pengguna->CekDuaFaktorAktif(),
                'AktifPada' => $pengguna->DuaFaktorAktifPada?->toIso8601String(),
                'SisaKodePemulihan' => count($pengguna->KodePemulihan2fa ?? []),
                // D-57: sesi Masuk dengan Google sudah menggantikan 2FA, jadi kewajiban paket tidak mendesak lagi.
                'Wajib' => $this->CekWajib($pengguna) && $permintaan->session()->get(SesiAutentikasiTenant::MASUK_GOOGLE) !== true,
            ],
            'Google' => [
                'Tersedia' => $this->konfigurasiGoogle->CekAktif(),
                'Tertaut' => $pengguna->CekGoogleTertaut(),
                'TertautPada' => $pengguna->GoogleDitautkanPada?->toIso8601String(),
                'KataSandiOtomatis' => $pengguna->KataSandiOtomatis,
                'MasukDenganGoogle' => $permintaan->session()->get(SesiAutentikasiTenant::MASUK_GOOGLE) === true,
            ],
            'Aktivasi' => $aktivasi,
            'KodePemulihanBaru' => is_array($kodeBaru) ? array_values($kodeBaru) : null,
        ]);
    }

    public function AktifkanDuaFaktor(KodeDuaFaktorPermintaan $permintaan, AktifkanDuaFaktorPengguna $aktifkan): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk($permintaan);
        $this->BatasiPercobaan('keamanan-2fa-aktifkan:'.$pengguna->Id);
        $rahasia = $permintaan->session()->get(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);

        if (! is_string($rahasia)) {
            return redirect()->route('kelola.keamanan');
        }

        $kodePemulihan = $aktifkan->Jalankan($pengguna, $rahasia, $permintaan->string('Kode')->toString());

        $sesi = $permintaan->session();
        $sesi->forget(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);
        $sesi->migrate(true);
        $sesi->flash(SesiAutentikasiTenant::KODE_PEMULIHAN_BARU, $kodePemulihan);

        return redirect()->route('kelola.keamanan');
    }

    public function NonaktifkanDuaFaktor(NonaktifkanDuaFaktorPermintaan $permintaan, NonaktifkanDuaFaktorPengguna $nonaktifkan): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk($permintaan);
        $this->BatasiPercobaan('keamanan-2fa-nonaktifkan:'.$pengguna->Id, 'KataSandi');
        $nonaktifkan->Jalankan($pengguna, $permintaan->string('KataSandi')->toString());

        return redirect()->route('kelola.keamanan')->with('Kilat', 'Verifikasi dua langkah dinonaktifkan.');
    }

    /** BR-00.5: kirim tautan konfirmasi ke email baru; email akun berganti setelah tautan dibuka. */
    public function GantiEmail(GantiEmailPermintaan $permintaan, KirimTautanGantiEmail $kirim): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk($permintaan);
        $this->BatasiPercobaan('keamanan-email-ganti:'.$pengguna->Id, 'KataSandi');
        $email = $permintaan->string('Email')->toString();
        $kirim->Jalankan($pengguna, $email, $permintaan->string('KataSandi')->toString());

        return redirect()->route('kelola.keamanan')->with('Kilat', 'Jika alamat itu bisa dipakai, tautan konfirmasi sudah dikirim ke '.mb_strtolower(trim($email)).'. Email akun berganti setelah tautannya dibuka.');
    }

    public function LepasGoogle(Request $permintaan, MasukDenganGoogle $masuk): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk($permintaan);
        $masuk->Lepas($pengguna);
        $permintaan->session()->forget(SesiAutentikasiTenant::MASUK_GOOGLE);

        return redirect()->route('kelola.keamanan')->with('Kilat', 'Tautan akun Google dilepas. Masuk berikutnya memakai email dan kata sandi.');
    }

    private function PenggunaMasuk(Request $permintaan): Pengguna
    {
        $pengguna = $permintaan->user('web');
        abort_unless($pengguna instanceof Pengguna, 403);

        return $pengguna;
    }

    private function CekWajib(Pengguna $pengguna): bool
    {
        return $this->penentuWajib->AmbilTenantWajib($pengguna->Id) !== [];
    }

    private function BatasiPercobaan(string $kunci, string $bidang = 'Kode'): void
    {
        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.', $bidang);
        }

        RateLimiter::hit($kunci);
    }
}
