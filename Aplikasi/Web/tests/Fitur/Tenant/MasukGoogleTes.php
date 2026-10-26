<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Surel\VerifikasiEmail;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\IdentifikasiTenantSesi;
use App\Http\Perantara\SesiAutentikasiTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanGoogle;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-57 Masuk dengan Google di dashboard: alur pengalihan OAuth, tautan akun lewat email, pendaftaran lewat Google,
 * dan penggantian 2FA (termasuk kewajiban 2FA paket Bisnis).
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    BantuanGoogle::Aktifkan();
});

/** Memulai alur dan menyelesaikan panggilan balik seolah Google mengembalikan [$klaim]. */
function MasukLewatGoogleUji(object $tes, array $klaim = [], string $tujuan = 'masuk', ?string $state = null): TestResponse
{
    $tes->get('/masuk/google?tujuan='.$tujuan)->assertRedirect();
    $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);
    BantuanGoogle::PalsukanTukarKode(BantuanGoogle::BuatToken(['nonce' => $alur['Nonce'], ...$klaim]));

    return $tes->get('/masuk/google/panggilan-balik?code=kode-uji&state='.($state ?? $alur['State']));
}

describe('Memulai alur', function (): void {
    it('mengalihkan ke Google dengan state & nonce acak yang disimpan di sesi', function (): void {
        $respons = $this->get('/masuk/google');
        $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);

        $respons->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth?');
        parse_str((string) parse_url($respons->headers->get('Location'), PHP_URL_QUERY), $kueri);
        expect($kueri['client_id'])->toBe(BantuanGoogle::CLIENT_ID)
            ->and($kueri['response_type'])->toBe('code')
            ->and($kueri['scope'])->toBe('openid email profile')
            ->and($kueri['state'])->toBe($alur['State'])->and(strlen($alur['State']))->toBeGreaterThanOrEqual(40)
            ->and($kueri['nonce'])->toBe($alur['Nonce'])
            ->and($kueri['redirect_uri'])->toEndWith('/masuk/google/panggilan-balik');
    });

    it('integrasi belum aktif: kembali ke halaman masuk dengan pesan, tanpa mengalihkan ke Google', function (): void {
        config(['integrasi.LoginSosial' => null]);

        $this->get('/masuk/google')->assertRedirect(route('masuk'))->assertSessionHas('Kilat');
    });

    it('halaman masuk & daftar memberi tahu apakah tombol Google ditampilkan', function (): void {
        $this->get('/masuk')->assertInertia(fn (AssertableInertia $h) => $h->where('MasukGoogle', true));
        $this->get('/daftar')->assertInertia(fn (AssertableInertia $h) => $h->where('MasukGoogle', true));

        config(['integrasi.LoginSosial' => null]);
        $this->get('/masuk')->assertInertia(fn (AssertableInertia $h) => $h->where('MasukGoogle', false));
    });
});

describe('Masuk akun yang sudah ada', function (): void {
    it('email sama dengan akun yang ada: tertaut, masuk tanpa 2FA, sesi ditandai Google, audit mencatat metode', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $pemilik = $t['Pemilik'];
        $pemilik->forceFill(['EmailDiverifikasiPada' => now()])->save();
        BantuanAutentikasi::AktifkanDuaFaktor($pemilik);

        MasukLewatGoogleUji($this, ['email' => $pemilik->Email])->assertRedirect(route('kelola.beranda'));

        $this->assertAuthenticatedAs($pemilik, 'web');
        expect(session(SesiAutentikasiTenant::MASUK_GOOGLE))->toBeTrue()
            ->and(session(IdentifikasiTenantSesi::KUNCI_SESI))->toBe($t['Tenant']->Id);
        $pemilik->refresh();
        expect($pemilik->GoogleSub)->toBe('110248495921238986420')
            ->and($pemilik->GoogleDitautkanPada)->not->toBeNull()
            // Email terverifikasi & kata sandi lama tepercaya: tidak diganti.
            ->and($pemilik->KataSandiOtomatis)->toBeFalse();
        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'sesi.masuk')->latest('Id')->first();
        expect($log?->NilaiBaru['Metode'] ?? null)->toBe('Google');
        expect(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'akun.google-tautkan')->exists())->toBeTrue();
    });

    it('masuk berikutnya memakai GoogleSub, bukan email: email Google boleh berubah', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now(), 'GoogleSub' => '110248495921238986420', 'GoogleDitautkanPada' => now()])->save();

        MasukLewatGoogleUji($this, ['email' => 'email-baru@gmail.com'])->assertRedirect(route('kelola.beranda'));

        $this->assertAuthenticatedAs($t['Pemilik'], 'web');
    });

    it('email akun belum terverifikasi: tautan mengambil alih — kata sandi lama diganti acak, token Owner dicabut', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $pemilik = $t['Pemilik'];
        $lama = $pemilik->KataSandi;
        expect($pemilik->EmailDiverifikasiPada)->toBeNull();

        MasukLewatGoogleUji($this, ['email' => $pemilik->Email])->assertRedirect();

        $pemilik->refresh();
        expect($pemilik->EmailDiverifikasiPada)->not->toBeNull()
            ->and($pemilik->KataSandiOtomatis)->toBeTrue()
            ->and($pemilik->KataSandi)->not->toBe($lama);
        // Pelaku yang mendaftarkan email korban tidak bisa lagi masuk dengan kata sandi lamanya.
        $this->post('/keluar');
        $this->post('/masuk', ['Email' => $pemilik->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasErrors('Email');
    });

    it('kata sandi awal buatan admin tidak lagi berlaku setelah pemilik email menautkan Google', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $anggota = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Admin);
        $anggota->forceFill(['EmailDiverifikasiPada' => now(), 'WajibGantiKataSandi' => true])->save();

        MasukLewatGoogleUji($this, ['email' => $anggota->Email])->assertRedirect();

        $anggota->refresh();
        expect($anggota->WajibGantiKataSandi)->toBeFalse()->and($anggota->KataSandiOtomatis)->toBeTrue();
    });

    it('state tidak cocok atau alur kedaluwarsa: tidak ada yang masuk', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');

        MasukLewatGoogleUji($this, ['email' => $t['Pemilik']->Email], state: 'state-palsu')->assertRedirect(route('masuk'))->assertSessionHas('Kilat');
        $this->assertGuest('web');
        expect($t['Pemilik']->refresh()->GoogleSub)->toBeNull();

        $this->get('/masuk/google')->assertRedirect();
        $this->travel(11)->minutes();
        $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);
        $this->get('/masuk/google/panggilan-balik?code=x&state='.$alur['State'])->assertRedirect(route('masuk'));
        $this->assertGuest('web');
    });

    it('nonce palsu atau token ditandatangani kunci lain: ditolak', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $this->get('/masuk/google');
        $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);
        BantuanGoogle::PalsukanTukarKode(BantuanGoogle::BuatToken(['nonce' => 'nonce-lain', 'email' => $t['Pemilik']->Email]));

        $this->get('/masuk/google/panggilan-balik?code=x&state='.$alur['State'])->assertRedirect(route('masuk'));
        $this->assertGuest('web');

        $this->get('/masuk/google');
        $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);
        BantuanGoogle::PalsukanTukarKode(BantuanGoogle::BuatToken(['nonce' => $alur['Nonce'], 'email' => $t['Pemilik']->Email], kunci: BantuanGoogle::BuatKunciAsing()));
        $this->get('/masuk/google/panggilan-balik?code=x&state='.$alur['State'])->assertRedirect(route('masuk'));
        $this->assertGuest('web');
    });

    it('pengguna membatalkan di layar Google (error=access_denied): kembali tanpa masuk', function (): void {
        $this->get('/masuk/google');
        $alur = session(SesiAutentikasiTenant::GOOGLE_ALUR);

        $this->get('/masuk/google/panggilan-balik?error=access_denied&state='.$alur['State'])->assertRedirect(route('masuk'))->assertSessionHas('Kilat', 'Masuk dengan Google dibatalkan.');
        $this->assertGuest('web');
    });

    it('email sudah ditautkan ke akun Google lain: ditolak, tautan lama tidak ditimpa', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now(), 'GoogleSub' => 'sub-pemilik-asli', 'GoogleDitautkanPada' => now()])->save();

        MasukLewatGoogleUji($this, ['email' => $t['Pemilik']->Email, 'sub' => 'sub-penyusup'])->assertRedirect(route('masuk'))->assertSessionHas('Kilat');

        $this->assertGuest('web');
        expect($t['Pemilik']->refresh()->GoogleSub)->toBe('sub-pemilik-asli');
    });

    it('akun tanpa keanggotaan aktif tidak bisa masuk', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        TenantPengguna::query()->where('IdPengguna', $t['Pemilik']->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);

        MasukLewatGoogleUji($this, ['email' => $t['Pemilik']->Email])->assertRedirect(route('masuk'));

        $this->assertGuest('web');
    });

    it('banyak usaha: diarahkan ke pilih tenant', function (): void {
        $a = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $b = BantuanOrganisasi::BuatTenant('Toko Buku Senja');
        TenantPengguna::query()->create(['IdTenant' => $b['Tenant']->Id, 'IdPengguna' => $a['Pemilik']->Id, 'Pemilik' => false, 'IdPeran' => BantuanOrganisasi::Peran($b['Tenant']->Id, PeranTenantBawaan::Kasir)->Id, 'SemuaOutlet' => true]);

        MasukLewatGoogleUji($this, ['email' => $a['Pemilik']->Email])->assertRedirect(route('pilih-tenant'));
    });
});

describe('Google menggantikan 2FA', function (): void {
    it('Owner tenant Bisnis tanpa 2FA: masuk biasa dialihkan ke Keamanan akun, masuk Google langsung membuka menu', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Toko Bisnis', 'BISNIS');
        DB::table('Langganan')->where('IdTenant', $t['Tenant']->Id)->update(['Status' => StatusLangganan::Aktif->value]);
        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();

        // Tanpa Google: kewajiban 2FA berlaku.
        $this->actingAs($t['Pemilik'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $t['Tenant']->Id]);
        $this->get('/kelola')->assertRedirect(route('kelola.keamanan'));

        // Dengan Google: sesi ditandai, kewajiban terpenuhi.
        $this->flushSession();
        $this->actingAs($t['Pemilik'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $t['Tenant']->Id, SesiAutentikasiTenant::MASUK_GOOGLE => true]);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda')->where('PengingatDuaFaktor', false));
        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $h) => $h->where('DuaFaktor.Wajib', false)->where('Google.MasukDenganGoogle', true));
    });

    it('akun ber-2FA yang masuk lewat Google tidak ditanya kode', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();
        BantuanAutentikasi::AktifkanDuaFaktor($t['Pemilik']);

        MasukLewatGoogleUji($this, ['email' => $t['Pemilik']->Email])->assertRedirect(route('kelola.beranda'));

        expect(session(SesiAutentikasiTenant::MASUK_TERTUNDA_ID))->toBeNull();
        $this->assertAuthenticatedAs($t['Pemilik'], 'web');
    });
});

describe('Daftar dengan Google', function (): void {
    it('akun belum ada: lanjut ke formulir lengkapi, lalu tenant terbentuk dengan email terverifikasi & tanpa email verifikasi', function (): void {
        MasukLewatGoogleUji($this, ['email' => 'Baru@Gmail.com', 'name' => 'Sinta Maharani', 'sub' => 'sub-sinta'], tujuan: 'daftar')->assertRedirect(route('daftar.google'));
        $this->assertGuest('web');

        $this->get('/daftar/google')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Autentikasi/LengkapiGoogle')
            ->where('Akun', ['Nama' => 'Sinta Maharani', 'Email' => 'baru@gmail.com'])
            ->where('Dibuka', true));

        $this->post('/daftar/google', ['Nama' => 'Sinta Maharani', 'NoHp' => '+6281299998888', 'NamaUsaha' => 'Warung Sinta', 'Paket' => 'pro', 'Setuju' => true])
            ->assertRedirect(route('kelola.panduan-awal'));

        $pengguna = Pengguna::query()->where('Email', 'baru@gmail.com')->sole();
        $tenant = Tenant::query()->sole();
        expect($pengguna->GoogleSub)->toBe('sub-sinta')
            ->and($pengguna->EmailDiverifikasiPada)->not->toBeNull()
            ->and($pengguna->KataSandiOtomatis)->toBeTrue()
            ->and($pengguna->NoHp)->toBe('081299998888')
            ->and($tenant->Nama)->toBe('Warung Sinta')
            ->and($tenant->Langganan?->Paket->Kode)->toBe('PRO');
        $this->assertAuthenticatedAs($pengguna, 'web');
        expect(session(SesiAutentikasiTenant::MASUK_GOOGLE))->toBeTrue();
        Mail::assertNotQueued(VerifikasiEmail::class);
        expect(session(SesiAutentikasiTenant::GOOGLE_PENDAFTARAN))->toBeNull();
    });

    it('wajib setuju S&K, nomor WhatsApp valid, dan nama usaha. Akun belum dibuat bila validasi gagal', function (): void {
        MasukLewatGoogleUji($this, ['email' => 'baru@gmail.com'], tujuan: 'daftar');

        $this->post('/daftar/google', ['Nama' => 'Sinta', 'NoHp' => '123', 'NamaUsaha' => 'W', 'Setuju' => false])
            ->assertSessionHasErrors(['NoHp', 'NamaUsaha', 'Setuju']);
        expect(Pengguna::query()->count())->toBe(0);
    });

    it('tanpa identitas Google di sesi, formulir lengkapi tidak bisa dibuka atau dikirim', function (): void {
        $this->get('/daftar/google')->assertRedirect(route('daftar'));
        $this->post('/daftar/google', ['Nama' => 'Sinta', 'NoHp' => '081299998888', 'NamaUsaha' => 'Warung Sinta', 'Setuju' => true])->assertRedirect(route('daftar'));

        expect(Tenant::query()->count())->toBe(0);
    });

    it('nomor WhatsApp yang sudah terdaftar ditolak dengan pesan umum (BR-00.1)', function (): void {
        $a = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        MasukLewatGoogleUji($this, ['email' => 'baru@gmail.com'], tujuan: 'daftar');

        $this->post('/daftar/google', ['Nama' => 'Sinta', 'NoHp' => $a['Pemilik']->NoHp, 'NamaUsaha' => 'Warung Sinta', 'Setuju' => true])->assertSessionHasErrors('Email');
        expect(Pengguna::query()->where('Email', 'baru@gmail.com')->exists())->toBeFalse();
    });

    it('tujuan daftar dengan akun yang sudah ada: langsung masuk dengan pemberitahuan', function (): void {
        $t = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $t['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();

        MasukLewatGoogleUji($this, ['email' => $t['Pemilik']->Email], tujuan: 'daftar')->assertRedirect(route('kelola.beranda'))->assertSessionHas('Kilat');
        $this->assertAuthenticatedAs($t['Pemilik'], 'web');
    });
});

describe('Keamanan akun: tautkan & lepas', function (): void {
    it('menautkan akun Google dari Keamanan akun; Google yang sudah dipakai akun lain ditolak', function (): void {
        $a = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $a['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();
        $this->actingAs($a['Pemilik'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $a['Tenant']->Id]);

        MasukLewatGoogleUji($this, ['email' => 'lain@gmail.com', 'sub' => 'sub-a'], tujuan: 'tautkan')->assertRedirect(route('kelola.keamanan'))->assertSessionHas('Kilat', 'Akun Google berhasil ditautkan.');
        expect($a['Pemilik']->refresh()->GoogleSub)->toBe('sub-a');

        $b = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->flushSession();
        $this->actingAs($b, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $a['Tenant']->Id]);
        MasukLewatGoogleUji($this, ['sub' => 'sub-a'], tujuan: 'tautkan')->assertRedirect(route('kelola.keamanan'))->assertSessionHas('Kilat', 'Akun Google ini sudah ditautkan ke akun Payoung lain.');
        expect($b->refresh()->GoogleSub)->toBeNull();
    });

    it('tujuan tautkan tanpa masuk: ditolak', function (): void {
        $this->get('/masuk/google?tujuan=tautkan')->assertForbidden();
    });

    it('melepas Google: boleh bila kata sandi buatan pengguna, ditolak bila kata sandi masih otomatis', function (): void {
        $a = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $a['Pemilik']->forceFill(['EmailDiverifikasiPada' => now(), 'GoogleSub' => 'sub-a', 'GoogleDitautkanPada' => now(), 'KataSandiOtomatis' => true])->save();
        $this->actingAs($a['Pemilik'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $a['Tenant']->Id]);

        $this->delete('/kelola/keamanan/google')->assertSessionHasErrors('Umum');
        expect($a['Pemilik']->refresh()->GoogleSub)->toBe('sub-a');

        $a['Pemilik']->forceFill(['KataSandiOtomatis' => false])->save();
        $this->delete('/kelola/keamanan/google')->assertRedirect(route('kelola.keamanan'));
        expect($a['Pemilik']->refresh()->GoogleSub)->toBeNull()
            ->and(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'akun.google-lepas')->exists())->toBeTrue();
    });

    it('akun pendaftar Google mengatur kata sandi tanpa kata sandi lama. Setelah itu boleh melepas Google', function (): void {
        MasukLewatGoogleUji($this, ['email' => 'baru@gmail.com'], tujuan: 'daftar');
        $this->post('/daftar/google', ['Nama' => 'Sinta', 'NoHp' => '081299998888', 'NamaUsaha' => 'Warung Sinta', 'Setuju' => true]);
        $pengguna = Pengguna::query()->where('Email', 'baru@gmail.com')->sole();

        $this->get('/ganti-kata-sandi')->assertInertia(fn (AssertableInertia $h) => $h->where('TanpaKataSandiLama', true));
        $this->post('/ganti-kata-sandi', ['KataSandi' => 'sandibaru123', 'KonfirmasiKataSandi' => 'sandibaru123'])->assertRedirect(route('kelola.beranda'));

        expect($pengguna->refresh()->KataSandiOtomatis)->toBeFalse()
            ->and(Hash::check('sandibaru123', $pengguna->KataSandi))->toBeTrue();
    });
});
