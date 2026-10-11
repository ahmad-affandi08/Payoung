<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Autentikasi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\MasukGoogle\KonfigurasiGoogle;
use App\Domain\Organisasi\Aksi\VerifikasiDuaFaktorPengguna;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Tenant\Kueri\PaketPublik;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\IdentifikasiTenantSesi;
use App\Http\Perantara\SesiAutentikasiTenant;
use App\Http\Permintaan\Autentikasi\KodeDuaFaktorPermintaan;
use App\Http\Permintaan\Autentikasi\MasukPermintaan;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Masuk, keluar, dan pemilih tenant back-office (BR-00.1). Percobaan masuk dibatasi 5 kali per menit per email+IP.
 * Akun ber-2FA masuk dua langkah (BR-00.8): kata sandi benar → "masuk tertunda" → kode TOTP/pemulihan → masuk.
 */
final class SesiKontroler extends Kontroler
{
    public const BATAS_PERCOBAAN_MASUK = 5;

    /** Batas per IP untuk menahan penyemprotan kata sandi ke banyak email (§20.2). */
    public const BATAS_PERCOBAAN_MASUK_PER_IP = 20;

    /** BR-00.8: percobaan kode 2FA per masuk tertunda, dengan jeda 5 menit. */
    public const BATAS_PERCOBAAN_DUA_FAKTOR = 5;

    public function TampilkanMasuk(KonfigurasiGoogle $google, PaketPublik $paketPublik): Response
    {
        return Inertia::render('Autentikasi/Masuk', [
            'MasukGoogle' => $google->CekAktif(),
            // D-87: iklan di panel samping (harga & diskon peluncuran asli dari katalog).
            'Penawaran' => $paketPublik->AmbilPenawaranTermurah(),
        ]);
    }

    public function Masuk(MasukPermintaan $permintaan, KeanggotaanPengguna $keanggotaan): RedirectResponse
    {
        $email = mb_strtolower($permintaan->string('Email')->toString());
        $kunci = 'masuk:'.$email.'|'.$permintaan->ip();
        $kunciIp = 'masuk-ip:'.$permintaan->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::BATAS_PERCOBAAN_MASUK)
            || RateLimiter::tooManyAttempts($kunciIp, self::BATAS_PERCOBAAN_MASUK_PER_IP)) {
            $detik = max(RateLimiter::availableIn($kunci), RateLimiter::availableIn($kunciIp));

            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.", 'Email');
        }

        $penjaga = Auth::guard('web');
        abort_unless($penjaga instanceof SessionGuard, 500);
        // validate(), bukan attempt(): akun ber-2FA belum boleh dianggap masuk sebelum kodenya terverifikasi.
        $berhasil = $penjaga->validate(['Email' => $email, 'password' => $permintaan->string('KataSandi')->toString()]);
        $pengguna = $penjaga->getLastAttempted();

        if (! $berhasil || ! $pengguna instanceof Pengguna) {
            RateLimiter::hit($kunci, 60);
            RateLimiter::hit($kunciIp, 60);

            throw new PelanggaranAturanBisnis('KredensialSalah', 'Email atau kata sandi salah.', 'Email');
        }

        RateLimiter::clear($kunci);

        if ($pengguna->CekDuaFaktorAktif()) {
            // BR-00.8: belum masuk; sesi hanya mengingat siapa yang sedang menyelesaikan langkah kedua.
            $sesi = $permintaan->session();
            $sesi->regenerate();
            $sesi->put([
                SesiAutentikasiTenant::MASUK_TERTUNDA_ID => $pengguna->Id,
                SesiAutentikasiTenant::MASUK_TERTUNDA_INGAT => $permintaan->boolean('Ingat'),
                SesiAutentikasiTenant::MASUK_TERTUNDA_SAMPAI => now()->addMinutes(SesiAutentikasiTenant::MENIT_MASUK_TERTUNDA)->getTimestamp(),
            ]);

            return redirect()->route('masuk.dua-faktor');
        }

        return $this->SelesaikanMasuk($permintaan, $pengguna, $permintaan->boolean('Ingat'), $keanggotaan);
    }

    public function TampilkanDuaFaktor(Request $permintaan): Response|RedirectResponse
    {
        if ($this->AmbilMasukTertunda($permintaan) === null) {
            return redirect()->route('masuk');
        }

        return Inertia::render('Autentikasi/VerifikasiDuaFaktor');
    }

    public function VerifikasiDuaFaktor(
        KodeDuaFaktorPermintaan $permintaan,
        VerifikasiDuaFaktorPengguna $verifikasi,
        KeanggotaanPengguna $keanggotaan,
    ): RedirectResponse {
        $pengguna = $this->AmbilMasukTertunda($permintaan);

        if ($pengguna === null) {
            return redirect()->route('masuk')->with('Kilat', 'Waktu verifikasi habis. Masuk lagi dengan email dan kata sandi.');
        }

        $kunci = 'masuk-dua-faktor:'.$pengguna->Id;

        if (RateLimiter::tooManyAttempts($kunci, self::BATAS_PERCOBAAN_DUA_FAKTOR)) {
            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.', 'Kode');
        }

        RateLimiter::hit($kunci, 300);
        $verifikasi->Jalankan($pengguna, $permintaan->string('Kode')->toString());
        RateLimiter::clear($kunci);

        $ingat = $permintaan->session()->get(SesiAutentikasiTenant::MASUK_TERTUNDA_INGAT) === true;
        $this->LupakanMasukTertunda($permintaan);

        return $this->SelesaikanMasuk($permintaan, $pengguna, $ingat, $keanggotaan);
    }

    public function TampilkanPilihTenant(Request $permintaan, KeanggotaanPengguna $keanggotaan, RingkasanTenant $ringkasan): Response
    {
        $pengguna = $permintaan->user('web');
        $daftar = $pengguna instanceof Pengguna ? $ringkasan->Ambil($keanggotaan->AmbilIdTenant($pengguna->Id)) : [];

        return Inertia::render('Autentikasi/PilihTenant', [
            'Tenant' => array_map(fn (array $baris) => ['Uuid' => $baris['Uuid'], 'Nama' => $baris['Nama']], $daftar),
        ]);
    }

    public function PilihTenant(Request $permintaan, KeanggotaanPengguna $keanggotaan, RingkasanTenant $ringkasan): RedirectResponse
    {
        $pengguna = $permintaan->user('web');
        $uuid = $permintaan->string('Tenant')->toString();
        $daftar = $pengguna instanceof Pengguna ? $ringkasan->Ambil($keanggotaan->AmbilIdTenant($pengguna->Id)) : [];
        $terpilih = array_values(array_filter($daftar, fn (array $baris) => $baris['Uuid'] === $uuid))[0] ?? null;

        // Tenant yang bukan milik pengguna diperlakukan sama dengan yang tidak ada (isolasi tenant).
        abort_if($terpilih === null, 404);

        $permintaan->session()->put(IdentifikasiTenantSesi::KUNCI_SESI, $terpilih['Id']);
        // F-02: log audit sesi (§25 no. 17).
        app(PencatatAudit::class)->CatatSesi('sesi.pilih-tenant', $terpilih['Id'], Auth::guard('web')->id(), $permintaan->ip(), $permintaan->userAgent());

        return redirect()->route('kelola.beranda');
    }

    public function Keluar(Request $permintaan): RedirectResponse
    {
        // F-02: log audit sesi (§25 no. 17).
        app(PencatatAudit::class)->CatatSesi('sesi.keluar', $permintaan->session()->get(IdentifikasiTenantSesi::KUNCI_SESI), Auth::guard('web')->id(), $permintaan->ip(), $permintaan->userAgent());
        Auth::guard('web')->logout();
        $permintaan->session()->invalidate();
        $permintaan->session()->regenerateToken();

        return redirect()->route('masuk');
    }

    private function SelesaikanMasuk(Request $permintaan, Pengguna $pengguna, bool $ingat, KeanggotaanPengguna $keanggotaan): RedirectResponse
    {
        Auth::guard('web')->login($pengguna, $ingat);
        $permintaan->session()->regenerate();

        $daftarTenant = $keanggotaan->AmbilIdTenant($pengguna->Id);

        if (count($daftarTenant) === 1) {
            $permintaan->session()->put(IdentifikasiTenantSesi::KUNCI_SESI, $daftarTenant[0]);
            // F-02: log audit sesi (§25 no. 17); multi-tenant tercatat saat memilih tenant.
            app(PencatatAudit::class)->CatatSesi('sesi.masuk', $daftarTenant[0], $pengguna->Id, $permintaan->ip(), $permintaan->userAgent());

            return redirect()->intended(route('kelola.beranda'));
        }

        return redirect()->route('pilih-tenant');
    }

    private function AmbilMasukTertunda(Request $permintaan): ?Pengguna
    {
        $sesi = $permintaan->session();
        $id = $sesi->get(SesiAutentikasiTenant::MASUK_TERTUNDA_ID);
        $sampai = $sesi->get(SesiAutentikasiTenant::MASUK_TERTUNDA_SAMPAI);

        if (! is_int($id) || ! is_int($sampai) || $sampai < now()->getTimestamp()) {
            $this->LupakanMasukTertunda($permintaan);

            return null;
        }

        $pengguna = Pengguna::query()->find($id);

        return $pengguna instanceof Pengguna && $pengguna->CekDuaFaktorAktif() ? $pengguna : null;
    }

    private function LupakanMasukTertunda(Request $permintaan): void
    {
        $permintaan->session()->forget([
            SesiAutentikasiTenant::MASUK_TERTUNDA_ID,
            SesiAutentikasiTenant::MASUK_TERTUNDA_INGAT,
            SesiAutentikasiTenant::MASUK_TERTUNDA_SAMPAI,
        ]);
    }
}
