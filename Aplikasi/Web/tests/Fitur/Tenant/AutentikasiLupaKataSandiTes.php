<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Aksi\AturUlangKataSandi;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Surel\KataSandiDiubah;
use App\Domain\Organisasi\Surel\TautanAturUlangKataSandi;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Http\Kontroler\Autentikasi\LupaKataSandiKontroler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    $this->pengguna = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Pengguna'];
});

/** Token dari tautan email atur ulang terakhir yang terkirim. */
function AmbilTokenAturUlangUji(): string
{
    $tautan = '';
    Mail::assertQueued(TautanAturUlangKataSandi::class, function (TautanAturUlangKataSandi $surel) use (&$tautan): bool {
        $tautan = $surel->tautan;

        return true;
    });
    preg_match('#/atur-ulang-kata-sandi/([^?]+)#', $tautan, $cocok);

    return $cocok[1] ?? '';
}

describe('Minta tautan (BR-00.9, §25 no. 18)', function (): void {
    it('email terdaftar dan tidak terdaftar mendapat jawaban yang sama. Tautan hanya dikirim ke akun yang ada', function (): void {
        $this->get('/lupa-kata-sandi')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Autentikasi/LupaKataSandi')
            ->where('MenitBerlaku', 60));

        $terdaftar = $this->post('/lupa-kata-sandi', ['Email' => 'RINA@kopinusantara.id']);
        $tidakAda = $this->post('/lupa-kata-sandi', ['Email' => 'tidak-ada@contoh.id']);

        foreach ([$terdaftar, $tidakAda] as $respons) {
            $respons->assertRedirect(route('lupa-kata-sandi'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('Kilat', LupaKataSandiKontroler::PESAN_TERKIRIM);
        }

        Mail::assertQueued(TautanAturUlangKataSandi::class, 1);
        Mail::assertQueued(TautanAturUlangKataSandi::class, fn (TautanAturUlangKataSandi $surel) => $surel->hasTo('rina@kopinusantara.id') && $surel->menitBerlaku === 60);
        expect(DB::table('password_reset_tokens')->where('email', 'rina@kopinusantara.id')->value('token'))
            ->not->toBe(AmbilTokenAturUlangUji());
    });

    it('permintaan dibatasi per IP. Batas per email tidak membuka apa pun', function (): void {
        foreach (range(1, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM + 1) as $_) {
            $this->post('/lupa-kata-sandi', ['Email' => 'rina@kopinusantara.id'])->assertSessionHasNoErrors();
            $this->travel(2)->minutes();
        }
        Mail::assertQueued(TautanAturUlangKataSandi::class, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM);

        foreach (range(1, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_IP_PER_JAM - LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM - 1) as $urutan) {
            $this->post('/lupa-kata-sandi', ['Email' => "acak{$urutan}@contoh.id"])->assertSessionHasNoErrors();
        }
        $this->post('/lupa-kata-sandi', ['Email' => 'lain@contoh.id'])->assertSessionHasErrors('Email');
    });
});

describe('Atur ulang kata sandi (BR-00.9)', function (): void {
    it('tautan valid mengganti kata sandi, mengganti token ingat, sekali pakai, dan mengirim pemberitahuan', function (): void {
        $this->pengguna->forceFill(['TokenIngat' => 'token-ingat-lama'])->save();
        $this->post('/lupa-kata-sandi', ['Email' => 'rina@kopinusantara.id']);
        $token = AmbilTokenAturUlangUji();

        $this->get("/atur-ulang-kata-sandi/{$token}?email=rina%40kopinusantara.id")
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Autentikasi/AturUlangKataSandi')
                ->where('Token', $token)
                ->where('Email', 'rina@kopinusantara.id'));

        $isian = ['Token' => $token, 'Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kopi-baru-2026', 'KonfirmasiKataSandi' => 'kopi-baru-2026'];
        $this->post('/atur-ulang-kata-sandi', $isian)->assertRedirect(route('masuk'))->assertSessionHasNoErrors();

        $pengguna = $this->pengguna->refresh();
        expect(Hash::check('kopi-baru-2026', $pengguna->KataSandi))->toBeTrue()
            ->and($pengguna->getRememberToken())->not->toBe('token-ingat-lama')
            ->and(DB::table('password_reset_tokens')->count())->toBe(0);
        Mail::assertQueued(KataSandiDiubah::class, fn (KataSandiDiubah $surel) => $surel->hasTo('rina@kopinusantara.id'));

        $this->post('/atur-ulang-kata-sandi', [...$isian, 'KataSandi' => 'lagi-lagi-99', 'KonfirmasiKataSandi' => 'lagi-lagi-99'])
            ->assertSessionHasErrors('Umum');
        expect(Hash::check('kopi-baru-2026', $pengguna->refresh()->KataSandi))->toBeTrue();

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasErrors('Email');
        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kopi-baru-2026'])->assertRedirect(route('kelola.beranda'));
    });

    it('atur ulang kata sandi tercatat di log audit tenant tempat pengguna menjadi anggota, dengan IP', function (): void {
        $this->post('/lupa-kata-sandi', ['Email' => 'rina@kopinusantara.id']);
        $token = AmbilTokenAturUlangUji();

        $this->post('/atur-ulang-kata-sandi', [
            'Token' => $token, 'Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kopi-baru-2026', 'KonfirmasiKataSandi' => 'kopi-baru-2026',
        ], ['REMOTE_ADDR' => '203.0.113.9'])->assertSessionHasNoErrors();

        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'akun.kata-sandi-atur-ulang')->sole();
        expect($log->IdPengguna)->toBe($this->pengguna->Id)
            ->and($log->IdTenant)->toBe(TenantPengguna::query()->where('IdPengguna', $this->pengguna->Id)->value('IdTenant'))
            ->and($log->Ip)->toBe('203.0.113.9');
    });

    it('tautan berlaku 60 menit. Token salah, email lain, dan kata sandi lemah ditolak', function (): void {
        $this->post('/lupa-kata-sandi', ['Email' => 'rina@kopinusantara.id']);
        $token = AmbilTokenAturUlangUji();
        $isian = ['Token' => $token, 'Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kopi-baru-2026', 'KonfirmasiKataSandi' => 'kopi-baru-2026'];

        $this->post('/atur-ulang-kata-sandi', [...$isian, 'Token' => str_repeat('a', 64)])->assertSessionHasErrors('Umum');
        $this->post('/atur-ulang-kata-sandi', [...$isian, 'Email' => 'tidak-ada@contoh.id'])->assertSessionHasErrors('Umum');
        $this->post('/atur-ulang-kata-sandi', [...$isian, 'KataSandi' => 'pendek', 'KonfirmasiKataSandi' => 'pendek'])->assertSessionHasErrors('KataSandi');

        $this->travel(61)->minutes();
        $this->post('/atur-ulang-kata-sandi', $isian)->assertSessionHasErrors('Umum');
        expect(Hash::check(BantuanAutentikasi::KATA_SANDI, $this->pengguna->refresh()->KataSandi))->toBeTrue();
        Mail::assertNotQueued(KataSandiDiubah::class);
    });

    it('sesi lain yang sedang masuk berakhir setelah kata sandi diatur ulang', function (): void {
        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->get('/kelola')->assertOk();

        // Diatur ulang dari perangkat lain.
        app(AturUlangKataSandi::class)->Jalankan(
            'rina@kopinusantara.id',
            app('auth.password.broker')->createToken(Pengguna::query()->sole()),
            'kopi-baru-2026',
        );

        // Request berikutnya memuat ulang pengguna dari database, seperti di produksi.
        app('auth')->forgetGuards();
        $this->get('/kelola')->assertRedirect(route('masuk'));
        $this->assertGuest('web');
    });

    it('atur ulang dari email ikut menandai email terverifikasi', function (): void {
        expect($this->pengguna->EmailDiverifikasiPada)->toBeNull();

        app(AturUlangKataSandi::class)->Jalankan('rina@kopinusantara.id', app('auth.password.broker')->createToken($this->pengguna), 'kopi-baru-2026');

        expect($this->pengguna->refresh()->EmailDiverifikasiPada)->not->toBeNull();
    });
});
