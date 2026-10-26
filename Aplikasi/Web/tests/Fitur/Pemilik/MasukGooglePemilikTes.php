<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\TokenAksesPengguna;
use App\Domain\Tenant\Enum\StatusLangganan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanGoogle;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-57 Masuk dengan Google di Aplikasi Owner: aplikasi mengirim token ID Google (google_sign_in) ke
 * `POST /api/pemilik/v1/masuk/google`. Token diverifikasi server; masuk lewat Google menggantikan 2FA, termasuk
 * kewajiban 2FA paket Bisnis di `IdentifikasiTenantPemilik`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanGoogle::Aktifkan();
});

function MasukGooglePemilikUji(object $tes, string $token, string $perangkat = 'Pixel 8 Rina'): TestResponse
{
    return $tes->withHeaders(['X-Versi-Aplikasi' => '1.0.0'])->postJson('/api/pemilik/v1/masuk/google', ['IdToken' => $token, 'NamaPerangkat' => $perangkat]);
}

function SiapkanPemilikGoogleUji(string $paket = 'PRO'): array
{
    $t = BantuanOrganisasi::BuatTenant('Kopi Senja Solo', $paket);
    $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();

    return $t;
}

describe('Konfigurasi untuk aplikasi', function (): void {
    it('aktif: mengembalikan Client ID web sebagai serverClientId', function (): void {
        $this->getJson('/api/pemilik/v1/masuk/google/konfigurasi')->assertOk()->assertExactJson(['Aktif' => true, 'ClientId' => BantuanGoogle::CLIENT_ID]);
    });

    it('belum diaktifkan di konsol: Aktif=false dan Client ID tidak dibocorkan', function (): void {
        config(['integrasi.LoginSosial' => null]);

        $this->getJson('/api/pemilik/v1/masuk/google/konfigurasi')->assertOk()->assertExactJson(['Aktif' => false, 'ClientId' => null]);
    });
});

describe('POST /masuk/google', function (): void {
    it('akun ada: token Owner terbit (ditandai MasukGoogle), 2FA tidak ditanya, audit mencatat metode Google', function (): void {
        $t = SiapkanPemilikGoogleUji();
        BantuanAutentikasi::AktifkanDuaFaktor($t['Pemilik']);

        $respons = MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email, 'aud' => BantuanGoogle::CLIENT_ID_ANDROID]))
            ->assertOk()
            ->assertJsonMissingPath('PerluDuaFaktor')
            ->assertJsonPath('Pengguna.Uuid', $t['Pemilik']->Uuid);
        $token = TokenAksesPengguna::query()->sole();

        expect($token->MasukGoogle)->toBeTrue()
            ->and($token->HashToken)->toBe(hash('sha256', $respons->json('Token')))
            ->and($t['Pemilik']->refresh()->GoogleSub)->toBe('110248495921238986420');
        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.masuk')->sole();
        expect($log->NilaiBaru['Metode'])->toBe('Google');
    });

    it('token yang diterbitkan berfungsi untuk API Owner', function (): void {
        $t = SiapkanPemilikGoogleUji();
        $token = MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email]))->assertOk()->json('Token');

        $this->withToken($token)->getJson('/api/pemilik/v1/profil')->assertOk()->assertJsonPath('Pengguna.Email', $t['Pemilik']->Email);
    });

    it('akun belum terdaftar: 404 AkunGoogleBelumTerdaftar (daftar hanya di web), tidak ada token', function (): void {
        MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => 'asing@gmail.com']))
            ->assertNotFound()
            ->assertJsonPath('Galat.Kode', 'AkunGoogleBelumTerdaftar');

        expect(TokenAksesPengguna::query()->count())->toBe(0);
    });

    it('token palsu, kedaluwarsa, atau untuk aplikasi lain: 401 GoogleTidakSah', function (array $klaim): void {
        $t = SiapkanPemilikGoogleUji();

        MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email, ...$klaim]))
            ->assertUnauthorized()
            ->assertJsonPath('Galat.Kode', 'GoogleTidakSah');
        expect(TokenAksesPengguna::query()->count())->toBe(0)->and($t['Pemilik']->refresh()->GoogleSub)->toBeNull();
    })->with([
        'kedaluwarsa' => [['exp' => time() - 3600]],
        'audiens lain' => [['aud' => 'lain.apps.googleusercontent.com']],
        'email belum terverifikasi' => [['email_verified' => false]],
    ]);

    it('ditandatangani kunci lain: 401', function (): void {
        $t = SiapkanPemilikGoogleUji();
        $token = BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email], kunci: BantuanGoogle::BuatKunciAsing());

        MasukGooglePemilikUji($this, $token)->assertUnauthorized();
    });

    it('integrasi belum aktif: token apa pun ditolak', function (): void {
        $t = SiapkanPemilikGoogleUji();
        $token = BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email]);
        config(['integrasi.LoginSosial' => null]);

        MasukGooglePemilikUji($this, $token)->assertUnauthorized();
    });

    it('validasi: IdToken & NamaPerangkat wajib', function (): void {
        $this->postJson('/api/pemilik/v1/masuk/google', [])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
    });

    it('kata sandi awal buatan admin: akun tertaut lewat email, kata sandi lama dibatalkan, lalu masuk', function (): void {
        $t = SiapkanPemilikGoogleUji();
        $anggota = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Admin);
        $anggota->forceFill(['EmailDiverifikasiPada' => now(), 'WajibGantiKataSandi' => true])->save();

        // Menautkan Google membuktikan pemilik email; kata sandi awal yang dikenal admin dibatalkan dulu.
        MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => $anggota->Email]))->assertOk();

        expect($anggota->refresh()->WajibGantiKataSandi)->toBeFalse()->and($anggota->KataSandiOtomatis)->toBeTrue();
    });

    it('batas percobaan per IP: banyak token ditolak berturut-turut memicu 429', function (): void {
        for ($i = 0; $i < 20; $i++) {
            MasukGooglePemilikUji($this, 'token-rusak')->assertUnauthorized();
        }

        MasukGooglePemilikUji($this, 'token-rusak')->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPercobaan');
    });
});

describe('Google menggantikan 2FA wajib paket Bisnis di API', function (): void {
    it('Owner Bisnis tanpa 2FA: masuk kata sandi → 403 DuaFaktorWajib. Masuk Google → data terbuka', function (): void {
        $t = SiapkanPemilikGoogleUji('BISNIS');
        DB::table('Langganan')->where('IdTenant', $t['Tenant']->Id)->update(['Status' => StatusLangganan::Aktif->value]);

        $tokenSandi = $this->postJson('/api/pemilik/v1/masuk', ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP lama'])->assertOk()->json('Token');
        $this->withToken($tokenSandi)->withHeader('X-Tenant', $t['Tenant']->Uuid)->getJson('/api/pemilik/v1/pengumuman')
            ->assertForbidden()->assertJsonPath('Galat.Kode', 'DuaFaktorWajib');

        $tokenGoogle = MasukGooglePemilikUji($this, BantuanGoogle::BuatToken(['email' => $t['Pemilik']->Email]))->assertOk()->json('Token');
        $this->withToken($tokenGoogle)->withHeader('X-Tenant', $t['Tenant']->Uuid)->getJson('/api/pemilik/v1/pengumuman')->assertOk();
    });
});
