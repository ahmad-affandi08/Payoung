<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Aksi\AturUlangKataSandi;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\TokenAksesPengguna;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant uji dengan pemilik yang emailnya sudah terverifikasi (kata sandi `BantuanAutentikasi::KATA_SANDI`).
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna}
 */
function SiapkanPemilikUji(string $namaUsaha = 'Kopi Senja Solo'): array
{
    $t = BantuanOrganisasi::BuatTenant($namaUsaha);
    $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();

    return $t;
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $header
 */
function KirimMasukPemilik(object $tes, array $data, array $header = []): TestResponse
{
    return $tes->withHeaders(['X-Versi-Aplikasi' => '1.0.0', ...$header])->postJson('/api/pemilik/v1/masuk', $data);
}

describe('OWN-01 masuk Aplikasi Owner', function (): void {
    it('masuk dengan email & kata sandi: token sekali tampil (hanya hash tersimpan, berlaku 30 hari), profil & tenant, audit pemilik.masuk', function (): void {
        $t = SiapkanPemilikUji();

        $respons = KirimMasukPemilik($this, ['Email' => strtoupper($t['Pemilik']->Email), 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'iPhone 15 Rina'])
            ->assertOk()
            ->assertJsonPath('Pengguna', ['Uuid' => $t['Pemilik']->Uuid, 'Nama' => 'Rina Wulandari', 'Email' => $t['Pemilik']->Email])
            ->assertJsonPath('Tenant', [['Uuid' => $t['Tenant']->Uuid, 'Nama' => 'Kopi Senja Solo', 'Pemilik' => true]]);
        $token = $respons->json('Token');

        expect($token)->toBeString()->and(strlen($token))->toBeGreaterThanOrEqual(40);
        $baris = TokenAksesPengguna::query()->sole();
        expect($baris->HashToken)->toBe(hash('sha256', $token))
            ->and($baris->HashToken)->not->toBe($token)
            ->and($baris->IdPengguna)->toBe($t['Pemilik']->Id)
            ->and($baris->Nama)->toBe('iPhone 15 Rina')
            ->and($baris->Kemampuan)->toBe('pemilik')
            ->and($baris->KedaluwarsaPada->toDateString())->toBe(now()->addDays(30)->toDateString());

        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.masuk')->sole();
        expect($log->IdTenant)->toBe($t['Tenant']->Id)
            ->and($log->IdPengguna)->toBe($t['Pemilik']->Id)
            ->and($log->NilaiBaru['NamaPerangkat'])->toBe('iPhone 15 Rina');

        $this->withToken($token)->getJson('/api/pemilik/v1/profil')
            ->assertOk()
            ->assertExactJson([
                'Pengguna' => ['Uuid' => $t['Pemilik']->Uuid, 'Nama' => 'Rina Wulandari', 'Email' => $t['Pemilik']->Email],
                'Tenant' => [['Uuid' => $t['Tenant']->Uuid, 'Nama' => 'Kopi Senja Solo', 'Pemilik' => true]],
            ]);
    });

    it('multi tenant: hanya keanggotaan aktif, urut nama, tanda pemilik per tenant. Audit di setiap tenant aktif', function (): void {
        $a = SiapkanPemilikUji('Warung Bakso Pak Kumis');
        $b = BantuanOrganisasi::BuatTenant('Apotek Sehat Sentosa');
        $c = BantuanOrganisasi::BuatTenant('Toko Bangunan Makmur Jaya');
        TenantPengguna::query()->create(['IdTenant' => $b['Tenant']->Id, 'IdPengguna' => $a['Pemilik']->Id, 'Pemilik' => false, 'IdPeran' => BantuanOrganisasi::Peran($b['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id, 'SemuaOutlet' => true]);
        TenantPengguna::query()->create(['IdTenant' => $c['Tenant']->Id, 'IdPengguna' => $a['Pemilik']->Id, 'Pemilik' => false, 'IdPeran' => BantuanOrganisasi::Peran($c['Tenant']->Id, PeranTenantBawaan::Kasir)->Id, 'SemuaOutlet' => true, 'Status' => StatusKeanggotaan::Nonaktif]);

        KirimMasukPemilik($this, ['Email' => $a['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'Samsung A55'])
            ->assertOk()
            ->assertJsonPath('Tenant', [
                ['Uuid' => $b['Tenant']->Uuid, 'Nama' => 'Apotek Sehat Sentosa', 'Pemilik' => false],
                ['Uuid' => $a['Tenant']->Uuid, 'Nama' => 'Warung Bakso Pak Kumis', 'Pemilik' => true],
            ]);

        expect(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.masuk')->orderBy('IdTenant')->pluck('IdTenant')->all())
            ->toBe([$a['Tenant']->Id, $b['Tenant']->Id]);
    });

    it('kata sandi salah atau email tidak dikenal → 422 KredensialSalah tanpa token. Email belum diverifikasi → 403. Validasi 422', function (): void {
        $t = SiapkanPemilikUji();

        foreach ([[$t['Pemilik']->Email, 'kata-sandi-salah'], ['tidak.ada@contoh.id', BantuanAutentikasi::KATA_SANDI]] as [$email, $sandi]) {
            KirimMasukPemilik($this, ['Email' => $email, 'KataSandi' => $sandi, 'NamaPerangkat' => 'HP Rina'])
                ->assertStatus(422)
                ->assertExactJson(['Galat' => ['Kode' => 'KredensialSalah', 'Pesan' => 'Email atau kata sandi salah.', 'Detail' => []]]);
        }

        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => null])->save();
        KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP Rina'])
            ->assertStatus(403)
            ->assertJsonPath('Galat.Kode', 'EmailBelumDiverifikasi');

        KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'ValidasiGagal');

        expect(TokenAksesPengguna::query()->count())->toBe(0);
    });

    it('pengguna tanpa keanggotaan aktif → 403 TanpaTenantAktif', function (): void {
        $pengguna = Pengguna::factory()->create(['KataSandi' => BantuanAutentikasi::KATA_SANDI]);

        KirimMasukPemilik($this, ['Email' => $pengguna->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP'])
            ->assertStatus(403)
            ->assertJsonPath('Galat.Kode', 'TanpaTenantAktif');
    });

    it('dibatasi 5 percobaan per menit per email+IP: percobaan ke-6 ditolak 429 walau kata sandinya benar, pulih setelah semenit', function (): void {
        $t = SiapkanPemilikUji();
        $data = ['Email' => $t['Pemilik']->Email, 'KataSandi' => 'salah-terus', 'NamaPerangkat' => 'HP Rina'];

        for ($i = 0; $i < 5; $i++) {
            KirimMasukPemilik($this, $data)->assertStatus(422);
        }

        KirimMasukPemilik($this, [...$data, 'KataSandi' => BantuanAutentikasi::KATA_SANDI])
            ->assertStatus(429)
            ->assertJsonPath('Galat.Kode', 'TerlaluBanyakPercobaan');

        $this->travel(61)->seconds();
        KirimMasukPemilik($this, [...$data, 'KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertOk();
    });
});

describe('OWN-01 + BR-00.8 masuk dengan verifikasi dua langkah', function (): void {
    it('akun ber-2FA menerima TokenTantangan (bukan token akses). Kode salah 422 KodeSalah; TOTP benar → token. Tantangan sekali pakai', function (): void {
        $t = SiapkanPemilikUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($t['Pemilik']);

        $respons = KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'iPhone Rina'])
            ->assertOk()
            ->assertJsonPath('PerluDuaFaktor', true)
            ->assertJsonMissingPath('Token');
        $tantangan = $respons->json('TokenTantangan');
        expect($tantangan)->toBeString()->and(TokenAksesPengguna::query()->count())->toBe(0)
            ->and(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.masuk')->count())->toBe(0);

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSalah($rahasia), 'NamaPerangkat' => 'iPhone Rina'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'KodeSalah');

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSaatIni($rahasia), 'NamaPerangkat' => 'iPhone Rina'])
            ->assertOk()
            ->assertJsonPath('Pengguna.Uuid', $t['Pemilik']->Uuid)
            ->assertJsonPath('Tenant.0.Uuid', $t['Tenant']->Uuid)
            ->assertJsonStructure(['Token', 'Pengguna' => ['Uuid', 'Nama', 'Email'], 'Tenant']);
        expect(TokenAksesPengguna::query()->count())->toBe(1)
            ->and(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.masuk')->count())->toBe(1);

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSaatIni($rahasia), 'NamaPerangkat' => 'iPhone Rina'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'TantanganTidakBerlaku');
    });

    it('kode pemulihan diterima sekali. Tantangan kedaluwarsa setelah 5 menit; 5 kode salah menghanguskan tantangan', function (): void {
        $t = SiapkanPemilikUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($t['Pemilik']);
        $masuk = fn (): string => KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'iPad'])->json('TokenTantangan');

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $masuk(), 'Kode' => 'aaaaa-bbbbb', 'NamaPerangkat' => 'iPad'])->assertOk();
        expect($t['Pemilik']->fresh()?->KodePemulihan2fa)->toBe(['CCCCC-DDDDD']);

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $masuk(), 'Kode' => 'AAAAA-BBBBB', 'NamaPerangkat' => 'iPad'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'KodeSalah');

        $kedaluwarsa = $masuk();
        $this->travel(6)->minutes();
        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $kedaluwarsa, 'Kode' => BantuanAutentikasi::KodeSaatIni($rahasia), 'NamaPerangkat' => 'iPad'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'TantanganTidakBerlaku');

        // Percobaan salah (kode pemulihan terpakai) sudah lewat jendela 5 menit → hitungan dimulai bersih.
        $tantangan = $masuk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSalah($rahasia), 'NamaPerangkat' => 'iPad'])->assertStatus(422);
        }

        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSaatIni($rahasia), 'NamaPerangkat' => 'iPad'])
            ->assertStatus(429)
            ->assertJsonPath('Galat.Kode', 'TerlaluBanyakPercobaan');
        $this->postJson('/api/pemilik/v1/masuk/dua-faktor', ['TokenTantangan' => $tantangan, 'Kode' => BantuanAutentikasi::KodeSaatIni($rahasia), 'NamaPerangkat' => 'iPad'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'TantanganTidakBerlaku');
    });
});

describe('OWN-01 token akses: keluar, kedaluwarsa, dicabut', function (): void {
    it('tanpa token, token acak, atau token kedaluwarsa (30 hari) → 401 TokenTidakValid', function (): void {
        $t = SiapkanPemilikUji();
        $token = KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP'])->json('Token');

        $this->getJson('/api/pemilik/v1/profil')->assertStatus(401)->assertJsonPath('Galat.Kode', 'TokenTidakValid');
        $this->withToken(str_repeat('x', 43))->getJson('/api/pemilik/v1/profil')->assertStatus(401);
        $this->withToken($token)->getJson('/api/pemilik/v1/profil')->assertOk();

        $this->travel(31)->days();
        $this->withToken($token)->getJson('/api/pemilik/v1/profil')->assertStatus(401)->assertJsonPath('Galat.Kode', 'TokenTidakValid');
    });

    it('keluar → 204, token itu dicabut (401), token perangkat lain tetap berlaku. Audit pemilik.keluar', function (): void {
        $t = SiapkanPemilikUji();
        $data = ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI];
        $tokenHp = KirimMasukPemilik($this, [...$data, 'NamaPerangkat' => 'HP'])->json('Token');
        $tokenTablet = KirimMasukPemilik($this, [...$data, 'NamaPerangkat' => 'Tablet'])->json('Token');

        $this->withToken($tokenHp)->postJson('/api/pemilik/v1/keluar')->assertNoContent();
        $this->withToken($tokenHp)->getJson('/api/pemilik/v1/profil')->assertStatus(401);
        $this->withToken($tokenHp)->postJson('/api/pemilik/v1/keluar')->assertStatus(401);
        $this->withToken($tokenTablet)->getJson('/api/pemilik/v1/profil')->assertOk();

        expect(TokenAksesPengguna::query()->where('Nama', 'HP')->sole()->DicabutPada)->not->toBeNull()
            ->and(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'pemilik.keluar')->where('IdTenant', $t['Tenant']->Id)->count())->toBe(1);
    });

    it('atur ulang kata sandi mencabut semua token Aplikasi Owner (PRD §16)', function (): void {
        $t = SiapkanPemilikUji();
        $token = KirimMasukPemilik($this, ['Email' => $t['Pemilik']->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP'])->json('Token');
        $tokenAturUlang = Password::broker('users')->createToken($t['Pemilik']);

        app(AturUlangKataSandi::class)->Jalankan($t['Pemilik']->Email, $tokenAturUlang, 'kata-sandi-baru-789');

        $this->withToken($token)->getJson('/api/pemilik/v1/profil')->assertStatus(401);
    });

    it('anggota yang dinonaktifkan tidak lagi melihat tenant itu di profil', function (): void {
        $t = SiapkanPemilikUji();
        $manajer = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $token = KirimMasukPemilik($this, ['Email' => $manajer->Email, 'KataSandi' => 'kata-sandi-uji', 'NamaPerangkat' => 'HP'])->assertOk()->json('Token');

        TenantPengguna::query()->where('IdPengguna', $manajer->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);

        $this->withToken($token)->getJson('/api/pemilik/v1/profil')->assertOk()->assertJsonPath('Tenant', []);
        $this->withToken($token)->withHeader('X-Tenant', $t['Tenant']->Uuid)->getJson('/api/pemilik/v1/perangkat')
            ->assertStatus(403)
            ->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
    });
});
