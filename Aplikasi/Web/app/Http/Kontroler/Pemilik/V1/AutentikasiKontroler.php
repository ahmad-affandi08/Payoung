<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\MasukGoogle\KonfigurasiGoogle;
use App\Domain\Integrasi\MasukGoogle\PemverifikasiTokenGoogle;
use App\Domain\Integrasi\MasukGoogle\TokenGoogleTidakSah;
use App\Domain\Organisasi\Aksi\CabutTokenPengguna;
use App\Domain\Organisasi\Aksi\DaftarkanPerangkatPengguna;
use App\Domain\Organisasi\Aksi\KirimTautanAturUlangKataSandi;
use App\Domain\Organisasi\Aksi\MasukDenganGoogle;
use App\Domain\Organisasi\Aksi\TerbitkanTokenPengguna;
use App\Domain\Organisasi\Aksi\VerifikasiDuaFaktorPengguna;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\ProfilPemilik;
use App\Domain\Organisasi\Layanan\PenyimpanTantanganDuaFaktor;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Autentikasi\LupaKataSandiKontroler;
use App\Http\Kontroler\Autentikasi\SesiKontroler;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPemilik;
use App\Http\Permintaan\Autentikasi\LupaKataSandiPermintaan;
use App\Http\Permintaan\Pemilik\V1\MasukDuaFaktorPermintaan;
use App\Http\Permintaan\Pemilik\V1\MasukGooglePermintaan;
use App\Http\Permintaan\Pemilik\V1\MasukPermintaan;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Autentikasi Aplikasi Owner (OWN-01, PRD §16 "user token"): masuk dengan email + kata sandi, langkah kedua 2FA
 * (BR-00.8) lewat `TokenTantangan` sekali pakai, keluar (cabut token), dan profil (akun + tenant anggota aktif).
 * Batas percobaan sama dengan masuk back-office (`SesiKontroler`): 5 per menit per email+IP, 20 per menit per IP, dan
 * 5 kode 2FA per 5 menit per pengguna.
 */
final class AutentikasiKontroler extends Kontroler
{
    public function __construct(
        private readonly ProfilPemilik $profil,
        private readonly KeanggotaanPengguna $keanggotaan,
        private readonly TerbitkanTokenPengguna $terbitkan,
    ) {}

    public function Masuk(MasukPermintaan $permintaan, PenyimpanTantanganDuaFaktor $tantangan): JsonResponse
    {
        $email = mb_strtolower($permintaan->string('Email')->toString());
        $kunci = 'pemilik-masuk:'.$email.'|'.$permintaan->ip();
        $kunciIp = 'pemilik-masuk-ip:'.$permintaan->ip();

        if (RateLimiter::tooManyAttempts($kunci, SesiKontroler::BATAS_PERCOBAAN_MASUK)
            || RateLimiter::tooManyAttempts($kunciIp, SesiKontroler::BATAS_PERCOBAAN_MASUK_PER_IP)) {
            $detik = max(RateLimiter::availableIn($kunci), RateLimiter::availableIn($kunciIp));

            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.", 'Email', 429, ['Detik' => $detik]);
        }

        $penjaga = Auth::guard('web');
        abort_unless($penjaga instanceof SessionGuard, 500);
        // validate(), bukan attempt(): API ini tanpa sesi; yang diterbitkan adalah token akses.
        $berhasil = $penjaga->validate(['Email' => $email, 'password' => $permintaan->string('KataSandi')->toString()]);
        $pengguna = $penjaga->getLastAttempted();

        if (! $berhasil || ! $pengguna instanceof Pengguna) {
            RateLimiter::hit($kunci, 60);
            RateLimiter::hit($kunciIp, 60);

            throw new PelanggaranAturanBisnis('KredensialSalah', 'Email atau kata sandi salah.', 'Email');
        }

        RateLimiter::clear($kunci);
        $this->PastikanBolehMasuk($pengguna);

        if ($pengguna->CekDuaFaktorAktif()) {
            return response()->json(['PerluDuaFaktor' => true, 'TokenTantangan' => $tantangan->Buat($pengguna)]);
        }

        return $this->SelesaikanMasuk($permintaan, $pengguna);
    }

    public function MasukDuaFaktor(
        MasukDuaFaktorPermintaan $permintaan,
        PenyimpanTantanganDuaFaktor $tantangan,
        VerifikasiDuaFaktorPengguna $verifikasi,
    ): JsonResponse {
        $tokenTantangan = $permintaan->string('TokenTantangan')->toString();
        $pengguna = $tantangan->Ambil($tokenTantangan);

        if ($pengguna === null) {
            throw new PelanggaranAturanBisnis('TantanganTidakBerlaku', 'Waktu verifikasi habis. Masuk lagi dengan email dan kata sandi.', 'TokenTantangan');
        }

        // Kunci sama dengan masuk back-office: percobaan kode lewat web dan aplikasi dihitung bersama.
        $kunci = 'masuk-dua-faktor:'.$pengguna->Id;

        if (RateLimiter::tooManyAttempts($kunci, SesiKontroler::BATAS_PERCOBAAN_DUA_FAKTOR)) {
            $tantangan->Hapus($tokenTantangan);
            $detik = RateLimiter::availableIn($kunci);

            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.", 'Kode', 429, ['Detik' => $detik]);
        }

        RateLimiter::hit($kunci, 300);

        try {
            $verifikasi->Jalankan($pengguna, $permintaan->string('Kode')->toString());
        } catch (PelanggaranAturanBisnis $galat) {
            throw new PelanggaranAturanBisnis('KodeSalah', $galat->getMessage(), 'Kode');
        }

        RateLimiter::clear($kunci);
        $tantangan->Hapus($tokenTantangan);
        $this->PastikanBolehMasuk($pengguna);

        return $this->SelesaikanMasuk($permintaan, $pengguna);
    }

    /**
     * Lupa kata sandi dari Aplikasi Owner: tautan atur ulang dikirim ke email seperti di web (`LupaKataSandiKontroler`),
     * dengan batas per IP dan per email yang sama. Jawabannya selalu sama, terdaftar atau tidak (§25 no. 18).
     */
    public function LupaKataSandi(LupaKataSandiPermintaan $permintaan, KirimTautanAturUlangKataSandi $kirim): JsonResponse
    {
        $email = mb_strtolower(trim($permintaan->string('Email')->toString()));
        $kunciIp = 'lupa-kata-sandi-ip:'.$permintaan->ip();
        $kunciEmail = 'lupa-kata-sandi-email:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($kunciIp, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_IP_PER_JAM)) {
            $detik = RateLimiter::availableIn($kunciIp);

            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', 'Terlalu banyak permintaan dari jaringan ini. Coba lagi dalam '.max(1, intdiv($detik + 59, 60)).' menit.', 'Email', 429, ['Detik' => $detik]);
        }

        RateLimiter::hit($kunciIp, 3600);

        if (! RateLimiter::tooManyAttempts($kunciEmail, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM)) {
            RateLimiter::hit($kunciEmail, 3600);
            $kirim->Jalankan($email);
        }

        return response()->json(['Pesan' => LupaKataSandiKontroler::PESAN_TERKIRIM]);
    }

    /** D-57: aplikasi menanyakan apakah tombol Google ditampilkan, dan Client ID web yang dipakai sebagai `serverClientId`. */
    public function KonfigurasiGoogle(KonfigurasiGoogle $konfigurasi): JsonResponse
    {
        $aktif = $konfigurasi->CekAktif();

        return response()->json(['Aktif' => $aktif, 'ClientId' => $aktif ? $konfigurasi->ClientId() : null]);
    }

    /**
     * D-57: masuk dengan token ID Google yang didapat aplikasi (google_sign_in). Menggantikan 2FA. Akun harus sudah ada
     * (pendaftaran lewat Google hanya di web, karena butuh data usaha dan persetujuan S&K).
     */
    public function MasukGoogle(MasukGooglePermintaan $permintaan, PemverifikasiTokenGoogle $pemverifikasi, MasukDenganGoogle $masuk): JsonResponse
    {
        $kunciIp = 'pemilik-masuk-google-ip:'.$permintaan->ip();

        if (RateLimiter::tooManyAttempts($kunciIp, SesiKontroler::BATAS_PERCOBAAN_MASUK_PER_IP)) {
            $detik = RateLimiter::availableIn($kunciIp);

            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaan', "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.", 'IdToken', 429, ['Detik' => $detik]);
        }

        try {
            $identitas = $pemverifikasi->Verifikasi($permintaan->string('IdToken')->toString());
        } catch (TokenGoogleTidakSah) {
            RateLimiter::hit($kunciIp, 60);

            throw new PelanggaranAturanBisnis('GoogleTidakSah', 'Akun Google tidak bisa diverifikasi. Coba masuk lagi.', 'IdToken', 401);
        }

        $pengguna = $masuk->Jalankan($identitas);

        if (! $pengguna instanceof Pengguna) {
            throw new PelanggaranAturanBisnis('AkunGoogleBelumTerdaftar', 'Akun Google ini belum terdaftar. Daftar dulu di dashboard web Payoung, lalu masuk lagi di sini.', 'IdToken', 404);
        }

        RateLimiter::clear($kunciIp);

        if ($pengguna->WajibGantiKataSandi) {
            throw new PelanggaranAturanBisnis('WajibGantiKataSandi', 'Ganti kata sandi awal dari admin usaha Anda di dashboard web, lalu masuk lagi.', 'IdToken', 403);
        }

        return $this->SelesaikanMasuk($permintaan, $pengguna, masukGoogle: true);
    }

    public function Keluar(Request $permintaan, CabutTokenPengguna $cabut, DaftarkanPerangkatPengguna $perangkat): Response
    {
        $token = AutentikasiPemilik::AmbilToken($permintaan);
        $perangkat->NonaktifkanMilikTokenAkses($token);
        $cabut->Jalankan($token, AutentikasiPemilik::AmbilPengguna($permintaan));

        return response()->noContent();
    }

    public function Profil(Request $permintaan): JsonResponse
    {
        return response()->json($this->profil->Ambil(AutentikasiPemilik::AmbilPengguna($permintaan)));
    }

    /** Email wajib terverifikasi dan pengguna harus anggota aktif minimal satu tenant. */
    private function PastikanBolehMasuk(Pengguna $pengguna): void
    {
        if ($pengguna->EmailDiverifikasiPada === null) {
            throw new PelanggaranAturanBisnis('EmailBelumDiverifikasi', 'Email belum diverifikasi. Buka tautan verifikasi di email Anda, lalu masuk lagi.', 'Email', 403);
        }

        // D-22: kata sandi awal dari admin harus diganti dulu di dashboard web.
        if ($pengguna->WajibGantiKataSandi) {
            throw new PelanggaranAturanBisnis('WajibGantiKataSandi', 'Ganti kata sandi awal dari admin usaha Anda di dashboard web, lalu masuk lagi.', 'Email', 403);
        }

        if ($this->keanggotaan->AmbilIdTenant($pengguna->Id) === []) {
            throw new PelanggaranAturanBisnis('TanpaTenantAktif', 'Akun ini belum menjadi anggota aktif usaha mana pun.', 'Email', 403);
        }
    }

    private function SelesaikanMasuk(Request $permintaan, Pengguna $pengguna, bool $masukGoogle = false): JsonResponse
    {
        $token = $this->terbitkan->Jalankan($pengguna, $permintaan->string('NamaPerangkat')->toString(), $permintaan->ip(), $permintaan->userAgent(), masukGoogle: $masukGoogle);

        return response()->json(['Token' => $token, ...$this->profil->Ambil($pengguna)]);
    }
}
