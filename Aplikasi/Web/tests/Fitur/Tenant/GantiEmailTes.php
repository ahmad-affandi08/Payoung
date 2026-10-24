<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Layanan\PemeriksaAlamatEmail;
use App\Domain\Organisasi\Layanan\PenandaVerifikasiEmail;
use App\Domain\Organisasi\Layanan\SandiEmailBaru;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Surel\GantiEmail;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\IdentifikasiTenantSesi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * BR-00.5: email asal-asalan tidak bisa dipakai mendaftar, dan email akun bisa diganti lewat Keamanan akun dengan
 * tautan konfirmasi yang dikirim ke alamat baru.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * @return array{Tenant: Tenant, Pengguna: Pengguna}
 */
function MasukSebagaiOwnerGantiEmail(object $uji): array
{
    $hasil = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
    $uji->actingAs($hasil['Pengguna'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $hasil['Tenant']->Id]);

    return $hasil;
}

/** Akun lain (tenant sendiri) yang memegang [$email]. */
function BuatPenggunaLainGantiEmail(string $email): void
{
    app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data($email, '081300000001', namaUsaha: 'Toko Lain'));
}

/** Tautan konfirmasi yang dikirim ke [$alamat]. */
function AmbilTautanGantiEmail(string $alamat): string
{
    $tautan = '';
    Mail::assertQueued(GantiEmail::class, function (GantiEmail $surel) use ($alamat, &$tautan): bool {
        if (! $surel->hasTo($alamat)) {
            return false;
        }
        $tautan = $surel->tautan;

        return true;
    });

    return $tautan;
}

describe('Filter email asal-asalan', function (): void {
    it('mengenali salah ketik penyedia populer, domain contoh, dan email sekali pakai', function (): void {
        config(['tenant.PeriksaDnsEmail' => false]);
        $pemeriksa = app(PemeriksaAlamatEmail::class);

        expect($pemeriksa->AmbilAlasanTolak('rina@gmial.com'))->toContain('gmail.com')
            ->and($pemeriksa->AmbilAlasanTolak('rina@example.com'))->toContain('email asli')
            ->and($pemeriksa->AmbilAlasanTolak('rina@Mailinator.com'))->toContain('sekali pakai')
            ->and($pemeriksa->AmbilAlasanTolak('rina@tanpatitik'))->toContain('Penulisan email')
            ->and($pemeriksa->AmbilAlasanTolak('rina@kopinusantara.id'))->toBeNull();
    });

    it('menolak domain yang tidak punya server surat dan menerima yang punya', function (): void {
        config(['tenant.PeriksaDnsEmail' => true]);
        $ditanya = [];
        $pemeriksa = new PemeriksaAlamatEmail(function (string $domain) use (&$ditanya): bool {
            $ditanya[] = $domain;

            return $domain === 'kopinusantara.id';
        });

        expect($pemeriksa->AmbilAlasanTolak('rina@kopinusantara.id'))->toBeNull()
            ->and($pemeriksa->AmbilAlasanTolak('rina@qwertyuiop-asdf.com'))->toContain('tidak bisa menerima pesan')
            ->and($ditanya)->toBe(['kopinusantara.id', 'qwertyuiop-asdf.com']);
    });

    it('Daftar menolak email salah ketik tanpa membuat tenant', function (): void {
        config(['tenant.PeriksaDnsEmail' => false]);

        $this->post('/daftar', [
            'Nama' => 'Rina Wulandari', 'Email' => 'rina@gmial.com', 'NoHp' => '081234567890',
            'KataSandi' => 'kopisusu123', 'KonfirmasiKataSandi' => 'kopisusu123', 'NamaUsaha' => 'Kopi Nusantara',
            'Setuju' => true, 'TokenCaptcha' => 'token-uji',
        ])->assertSessionHasErrors('Email');

        expect(Pengguna::query()->count())->toBe(0);
    });
});

describe('Ganti email di Keamanan akun (BR-00.5)', function (): void {
    it('halaman Keamanan akun memuat status email', function (): void {
        MasukSebagaiOwnerGantiEmail($this);

        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Autentikasi/KeamananAkun')
            ->where('Akun.Email', 'rina@kopinusantara.id')
            ->where('Akun.EmailTerverifikasi', false)
            ->where('Akun.BisaGantiEmail', true));
    });

    it('mengirim tautan ke alamat baru; email akun baru berganti dan terverifikasi setelah tautan dibuka', function (): void {
        ['Pengguna' => $pengguna] = MasukSebagaiOwnerGantiEmail($this);

        $this->post('/kelola/keamanan/email', ['Email' => 'Rina.Baru@KopiNusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat');

        expect($pengguna->refresh()->Email)->toBe('rina@kopinusantara.id', 'Email lama dipakai sampai tautan dibuka.');

        $this->get(AmbilTautanGantiEmail('rina.baru@kopinusantara.id'))
            ->assertRedirect(route('kelola.keamanan'));

        $pengguna->refresh();
        expect($pengguna->Email)->toBe('rina.baru@kopinusantara.id')
            ->and($pengguna->EmailDiverifikasiPada)->not->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'akun.email-diganti')->exists())->toBeTrue();
    });

    it('kata sandi salah ditolak dan tidak ada email terkirim', function (): void {
        MasukSebagaiOwnerGantiEmail($this);

        $this->post('/kelola/keamanan/email', ['Email' => 'baru@kopinusantara.id', 'KataSandi' => 'salah-total'])
            ->assertSessionHasErrors('KataSandi');

        Mail::assertNothingQueued();
    });

    it('email asal-asalan ditolak dengan alasan yang bisa ditindaklanjuti', function (): void {
        MasukSebagaiOwnerGantiEmail($this);

        $this->post('/kelola/keamanan/email', ['Email' => 'rina@gmial.com', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasErrors(['Email' => 'Maksud Anda ...@gmail.com? Periksa penulisan email Anda.']);
        $this->post('/kelola/keamanan/email', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasErrors('Email');

        Mail::assertNothingQueued();
    });

    it('alamat yang sudah dipakai akun lain dijawab sama dengan yang berhasil, tanpa email terkirim (§25 no. 18)', function (): void {
        MasukSebagaiOwnerGantiEmail($this);
        BuatPenggunaLainGantiEmail('sudah@dipakai.id');

        $this->post('/kelola/keamanan/email', ['Email' => 'sudah@dipakai.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat');

        Mail::assertNothingQueued();
    });

    it('tautan yang sudah dipakai, kedaluwarsa, atau dirusak ditolak', function (): void {
        ['Pengguna' => $pengguna] = MasukSebagaiOwnerGantiEmail($this);
        $this->post('/kelola/keamanan/email', ['Email' => 'baru@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123']);
        $tautan = AmbilTautanGantiEmail('baru@kopinusantara.id');
        $penanda = app(PenandaVerifikasiEmail::class);
        $email = app(SandiEmailBaru::class)->Sandikan('lain@kopinusantara.id');

        // Tanda tangan dirusak: dialihkan dengan pesan yang bisa ditindaklanjuti, bukan 403 mentah.
        $this->get($tautan.'x')->assertRedirect()->assertSessionHasErrors('Umum');

        // Kedaluwarsa.
        $kedaluwarsa = URL::temporarySignedRoute('ganti-email', now()->subMinute(), [
            'pengguna' => $pengguna->Uuid, 'hash' => $penanda->BuatHash($pengguna), 'email' => $email,
        ], false);
        $this->get($kedaluwarsa)->assertRedirect()->assertSessionHasErrors('Umum');

        // Hash email lain: tautan milik email yang sudah tidak dipakai.
        $hashSalah = URL::temporarySignedRoute('ganti-email', now()->addHour(), [
            'pengguna' => $pengguna->Uuid, 'hash' => 'salah', 'email' => $email,
        ], false);
        $this->get($hashSalah)->assertRedirect()->assertSessionHasErrors('Umum');
        expect($pengguna->refresh()->Email)->toBe('rina@kopinusantara.id');

        // Dipakai sekali: tautan yang sama tidak berlaku lagi setelah email berganti.
        $this->get($tautan)->assertRedirect(route('kelola.keamanan'));
        $this->get($tautan)->assertRedirect()->assertSessionHasErrors('Umum');
        expect($pengguna->refresh()->Email)->toBe('baru@kopinusantara.id');
    });

    it('alamat yang diambil akun lain sebelum tautan dibuka tidak menimpa akun itu', function (): void {
        ['Pengguna' => $pengguna] = MasukSebagaiOwnerGantiEmail($this);
        $this->post('/kelola/keamanan/email', ['Email' => 'rebutan@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123']);
        $tautan = AmbilTautanGantiEmail('rebutan@kopinusantara.id');
        BuatPenggunaLainGantiEmail('rebutan@kopinusantara.id');

        $this->get($tautan)->assertRedirect()->assertSessionHasErrors('Umum');

        expect($pengguna->refresh()->Email)->toBe('rina@kopinusantara.id');
    });

    it('permintaan ganti email dibatasi per akun', function (): void {
        MasukSebagaiOwnerGantiEmail($this);

        foreach (range(1, 5) as $_) {
            $this->post('/kelola/keamanan/email', ['Email' => 'rina@gmial.com', 'KataSandi' => 'salah']);
        }

        $this->post('/kelola/keamanan/email', ['Email' => 'baru@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasErrors('KataSandi');
        Mail::assertNothingQueued();
    });

    it('akun yang dibuat lewat Google harus mengatur kata sandi dulu', function (): void {
        ['Pengguna' => $pengguna] = MasukSebagaiOwnerGantiEmail($this);
        $pengguna->forceFill(['KataSandiOtomatis' => true])->save();

        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Akun.BisaGantiEmail', false));
        $this->post('/kelola/keamanan/email', ['Email' => 'baru@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertSessionHasErrors('KataSandi');
        Mail::assertNothingQueued();
    });
});
