<?php

declare(strict_types=1);

use App\Domain\Integrasi\Layanan\PemeriksaCaptcha;
use App\Domain\Organisasi\Galat\IdentitasSudahTerdaftar;
use App\Domain\Organisasi\Layanan\PenandaVerifikasiEmail;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Surel\UpayaPendaftaranAkunTerdaftar;
use App\Domain\Organisasi\Surel\VerifikasiEmail;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Model\DokumenLegal;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\IdentifikasiTenantSesi;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianDaftarUji(array $ubah = []): array
{
    return [
        'Nama' => 'Rina Wulandari',
        'Email' => 'Rina@KopiNusantara.id',
        'NoHp' => '+6281234567890',
        'KataSandi' => 'kopisusu123',
        'KonfirmasiKataSandi' => 'kopisusu123',
        'NamaUsaha' => 'Kopi Nusantara',
        'Paket' => 'pro',
        'Setuju' => true,
        'TokenCaptcha' => 'token-uji',
        ...$ubah,
    ];
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('Registrasi lewat web (F-00)', function (): void {
    it('Given data valid When Daftar Then tenant terbentuk, Owner masuk, email verifikasi terkirim, dan diarahkan ke panduan awal', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertRedirect(route('kelola.panduan-awal'));

        $pengguna = Pengguna::query()->sole();
        $tenant = Tenant::query()->sole();
        expect($pengguna->Email)->toBe('rina@kopinusantara.id')
            ->and($pengguna->NoHp)->toBe('081234567890')
            ->and($tenant->Langganan?->Paket->Kode)->toBe('PRO');
        $this->assertAuthenticatedAs($pengguna, 'web');
        Mail::assertQueued(VerifikasiEmail::class, fn (VerifikasiEmail $surel) => $surel->hasTo('rina@kopinusantara.id'));

        $this->get('/kelola/panduan-awal')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Indeks')
                ->where('TenantAktif.Nama', 'Kopi Nusantara')
                ->where('Pengguna.EmailTerverifikasi', false));
    });

    it('menolak isian tidak lengkap, tanpa persetujuan, dan nomor WhatsApp tidak valid', function (array $ubah, string $bidang): void {
        $this->post('/daftar', IsianDaftarUji($ubah))->assertSessionHasErrors($bidang);
        expect(Tenant::query()->count())->toBe(0);
    })->with([
        'tanpa persetujuan' => [['Setuju' => false], 'Setuju'],
        'nomor tidak valid' => [['NoHp' => '12345'], 'NoHp'],
        'kata sandi lemah' => [['KataSandi' => 'pendek', 'KonfirmasiKataSandi' => 'pendek'], 'KataSandi'],
        'konfirmasi beda' => [['KonfirmasiKataSandi' => 'lain12345'], 'KonfirmasiKataSandi'],
        'nama usaha kosong' => [['NamaUsaha' => ''], 'NamaUsaha'],
    ]);

    it('BR-00.1: email yang sudah terdaftar ditolak walau hurufnya berbeda', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        $this->post('/keluar');

        $this->post('/daftar', IsianDaftarUji(['Email' => 'RINA@kopinusantara.id', 'NoHp' => '081299998888']))->assertSessionHasErrors('Email');
        expect(Tenant::query()->count())->toBe(1);
    });

    it('§25 no. 18: email atau nomor terdaftar mendapat pesan umum yang sama, dan pemilik akun diberi tahu', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        $this->post('/keluar');

        $this->post('/daftar', IsianDaftarUji(['NoHp' => '081299998888']))
            ->assertSessionHasErrors(['Email' => IdentitasSudahTerdaftar::PESAN_UMUM])
            ->assertSessionDoesntHaveErrors('NoHp');
        $this->post('/daftar', IsianDaftarUji(['Email' => 'lain@contoh.id']))
            ->assertSessionHasErrors(['Email' => IdentitasSudahTerdaftar::PESAN_UMUM])
            ->assertSessionDoesntHaveErrors('NoHp');

        $this->assertGuest('web');
        expect(Tenant::query()->count())->toBe(1)->and(Pengguna::query()->count())->toBe(1);
        Mail::assertQueued(UpayaPendaftaranAkunTerdaftar::class, fn (UpayaPendaftaranAkunTerdaftar $surel) => $surel->hasTo('rina@kopinusantara.id'));
    });

    it('BR-00.6: paket pilihan yang tidak tersedia jatuh ke paket bawaan, bukan paket pertama', function (): void {
        $this->get('/daftar?paket=enterprise')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PaketTerpilih', 'PRO'));
        $this->get('/daftar?paket=salahketik')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PaketTerpilih', 'PRO'));
        $this->get('/daftar?paket=starter')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PaketTerpilih', 'STARTER'));
    });

    it('BR-P06.2: halaman daftar tertutup dan kiriman ditolak bila S&K belum berlaku', function (): void {
        DokumenLegal::query()->where('Jenis', 'SyaratKetentuan')->delete();

        $this->get('/daftar')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Autentikasi/Daftar')->where('Dibuka', false));
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasErrors('Umum');
        expect(Tenant::query()->count())->toBe(0);
    });
});

describe('CAPTCHA & rate limit (BR-00.4)', function (): void {
    it('token CAPTCHA wajib valid bila Turnstile aktif', function (): void {
        config(['integrasi.Turnstile.KunciSitus' => '0x4AAAuji', 'integrasi.Turnstile.KunciRahasia' => '0x4AAArahasia']);
        Http::fake([PemeriksaCaptcha::URL_VERIFIKASI => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get('/daftar')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('KunciSitusCaptcha', '0x4AAAuji'));
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasErrors('TokenCaptcha');
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();

        expect(Tenant::query()->count())->toBe(1);
    });

    it('di produksi, pendaftaran ditutup bila CAPTCHA belum aktif', function (): void {
        app()->detectEnvironment(fn () => 'production');
        // Di luar lingkungan testing CSRF kembali aktif; yang diuji di sini aturan CAPTCHA, bukan CSRF.
        $this->withoutMiddleware(PreventRequestForgery::class);

        expect(app(PemeriksaCaptcha::class)->Periksa('apa-saja', '127.0.0.1'))->toBeFalse();
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasErrors('Umum');
        expect(Tenant::query()->count())->toBe(0);
    });

    it('di produksi, pendaftaran ditutup bila email transaksional belum aktif walau CAPTCHA aktif', function (): void {
        app()->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['integrasi.Turnstile.KunciSitus' => '0x4AAAuji', 'integrasi.Turnstile.KunciRahasia' => '0x4AAArahasia']);
        Http::fake([PemeriksaCaptcha::URL_VERIFIKASI => Http::response(['success' => true])]);

        $this->post('/daftar', IsianDaftarUji())->assertSessionHasErrors('Umum');
        config(['integrasi.EmailAktif' => true]);
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        expect(Tenant::query()->count())->toBe(1);
    });

    it('membatasi percobaan registrasi per IP per jam', function (): void {
        config(['tenant.BatasRegistrasiPerJam' => 2]);

        $this->post('/daftar', IsianDaftarUji(['Setuju' => false]));
        $this->post('/daftar', IsianDaftarUji(['Setuju' => false]));
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasErrors('Umum');

        expect(Tenant::query()->count())->toBe(0);
    });
});

describe('Verifikasi email (BR-00.5)', function (): void {
    it('tautan bertanda tangan memverifikasi email; tautan kedaluwarsa atau dirusak ditolak', function (): void {
        $pengguna = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Pengguna'];
        $parameter = ['pengguna' => $pengguna->Uuid, 'hash' => app(PenandaVerifikasiEmail::class)->BuatHash($pengguna)];

        // Tanda tangan relatif (D-20): jalur yang ditandatangani, bukan skema & host.
        $this->get(URL::temporarySignedRoute('verifikasi-email', now()->subMinute(), $parameter, false))
            ->assertRedirect(route('masuk'));
        $this->get(URL::temporarySignedRoute('verifikasi-email', now()->addHour(), [...$parameter, 'hash' => 'salah'], false))
            ->assertSessionHasErrors('Umum');
        expect($pengguna->refresh()->EmailDiverifikasiPada)->toBeNull();

        $this->get(URL::temporarySignedRoute('verifikasi-email', now()->addHour(), $parameter, false))->assertRedirect(route('masuk'));
        expect($pengguna->refresh()->EmailDiverifikasiPada)->not->toBeNull();
    });

    it('tautan kedaluwarsa memberi pesan yang bisa ditindaklanjuti, bukan 403 mentah', function (): void {
        $pengguna = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Pengguna'];
        $parameter = ['pengguna' => $pengguna->Uuid, 'hash' => app(PenandaVerifikasiEmail::class)->BuatHash($pengguna)];

        $this->get(URL::temporarySignedRoute('verifikasi-email', now()->subMinute(), $parameter, false))
            ->assertRedirect(route('masuk'))
            ->assertSessionHasErrors('Umum');
    });

    it('tautan tetap sah walau dibuka di host lain (D-20: payoung.id dialihkan ke domain tenant)', function (): void {
        // Regresi: tanda tangan absolut ikut menghitung skema & host, sehingga tautan yang ditandatangani di satu
        // domain menjadi "Invalid signature" begitu ArahkanDomainAplikasi mengalihkannya ke domain tenant.
        $pengguna = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Pengguna'];
        $parameter = ['pengguna' => $pengguna->Uuid, 'hash' => app(PenandaVerifikasiEmail::class)->BuatHash($pengguna)];
        $relatif = URL::temporarySignedRoute('verifikasi-email', now()->addHour(), $parameter, false);

        $this->get('https://host-lain.test'.$relatif)->assertRedirect(route('masuk'));

        expect($pengguna->refresh()->EmailDiverifikasiPada)->not->toBeNull();
    });

    it('tautan di email berlaku sesuai konfigurasi (24 jam), bukan lebih', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        $tautan = '';
        Mail::assertQueued(VerifikasiEmail::class, function (VerifikasiEmail $surel) use (&$tautan): bool {
            $tautan = $surel->tautan;

            return true;
        });
        $this->post('/keluar');

        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        // Lewat masa berlaku: dialihkan dengan pesan yang bisa ditindaklanjuti, bukan 403 mentah.
        $this->get($tautan)->assertRedirect(route('masuk'))->assertSessionHasErrors('Umum');
    });

    it('kirim ulang tautan dibatasi', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();

        foreach (range(1, 3) as $_) {
            $this->post('/verifikasi-email/kirim-ulang')->assertSessionHasNoErrors();
        }
        $this->post('/verifikasi-email/kirim-ulang')->assertSessionHasErrors('Umum');
        Mail::assertQueued(VerifikasiEmail::class, 4);
    });

    it('kirim ulang untuk email yang sudah terverifikasi tidak mengaku mengirim', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        Pengguna::query()->sole()->forceFill(['EmailDiverifikasiPada' => now()])->save();
        // Lapisan test Laravel tidak pernah memanggil `forgetGuards()`, jadi guard `web` masih memegang instance
        // Pengguna hasil request `/daftar` dan tidak melihat perubahan di atas. Di produksi setiap request memuat
        // penggunanya ulang dari basis data, jadi guard-nya dilupakan dulu supaya skenarionya sama.
        $this->app['auth']->forgetGuards();
        Mail::fake();

        $this->post('/verifikasi-email/kirim-ulang')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Email Anda sudah terverifikasi, jadi tautan tidak perlu dikirim lagi.');

        Mail::assertNothingSent();
    });

    it('kirim ulang yang gagal dilaporkan sebagai masalah, bukan sukses', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP ditolak'));

        $this->post('/verifikasi-email/kirim-ulang')->assertSessionHasErrors('Umum');
    });

    it('pendaftaran yang gagal mengirim email tidak menyuruh pengguna mengecek email', function (): void {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP ditolak'));

        $this->post('/daftar', IsianDaftarUji())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', fn (string $pesan): bool => str_contains($pesan, 'belum berhasil dikirim'));
    });
});

describe('Masuk & pemilih tenant (BR-00.1, isolasi tenant)', function (): void {
    it('pengguna dengan satu tenant langsung masuk ke back-office', function (): void {
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertRedirect(route('kelola.beranda'));
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    });

    it('pengguna anggota beberapa tenant memilih tenant, dan tidak bisa memilih tenant orang lain', function (): void {
        $milikRina = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        $milikLain = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'))['Tenant'];
        $kedua = Tenant::query()->create(['Nama' => 'Kopi Nusantara Cabang', 'Slug' => 'kopi-nusantara-cabang']);
        TenantPengguna::query()->create(['IdTenant' => $kedua->Id, 'IdPengguna' => $milikRina['Pengguna']->Id]);

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])->assertRedirect(route('pilih-tenant'));
        $this->get('/kelola')->assertRedirect(route('pilih-tenant'));
        $this->get('/pilih-tenant')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Tenant', 2));

        $this->post('/pilih-tenant', ['Tenant' => $milikLain->Uuid])->assertNotFound();
        $this->post('/pilih-tenant', ['Tenant' => $kedua->Uuid])->assertRedirect(route('kelola.beranda'));
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('TenantAktif.Nama', 'Kopi Nusantara Cabang'));
    });

    it('sesi berisi tenant yang bukan milik pengguna tidak membuka back-office', function (): void {
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        $lain = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'))['Tenant'];
        $rina = Pengguna::query()->where('Email', 'rina@kopinusantara.id')->sole();

        $this->actingAs($rina, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $lain->Id])
            ->get('/kelola')->assertRedirect(route('pilih-tenant'));
    });

    it('kata sandi salah ditolak dan dibatasi 5 kali per menit', function (): void {
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());

        foreach (range(1, 5) as $_) {
            $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'salah'])->assertSessionHasErrors('Email');
        }
        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123'])->assertSessionHasErrors('Email');
        $this->assertGuest('web');
    });

    it('tamu diarahkan ke halaman masuk; keluar mengakhiri sesi', function (): void {
        $this->get('/kelola')->assertRedirect(route('masuk'));

        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => 'kata-sandi-kuat-123']);
        $this->post('/keluar')->assertRedirect(route('masuk'));
        $this->assertGuest('web');
    });
});

describe('Badan email verifikasi (D-26)', function (): void {
    // Regresi: badan `text/plain` dirender Blade dengan `{{ }}`, yang meng-escape `&` menjadi `&amp;`. PHP lalu
    // membaca `&amp;signature=` sebagai parameter bernama `amp;signature`, `signature` terbaca kosong, dan
    // tautan yang diklik dari email ditolak 403 "Invalid signature". Test verifikasi lain memakai properti
    // `$surel->tautan` langsung, jadi tidak pernah menyentuh templat dan tidak bisa menangkap ini.

    it('tautan di badan teks utuh dan bisa diklik apa adanya', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        $this->post('/keluar');

        $surel = null;
        Mail::assertQueued(VerifikasiEmail::class, function (VerifikasiEmail $dikirim) use (&$surel): bool {
            $surel = $dikirim;

            return true;
        });

        $teks = view('Surel.Tenant.VerifikasiEmail', [
            'Nama' => $surel->nama,
            'Tautan' => $surel->tautan,
            'JamBerlaku' => $surel->jamBerlaku,
        ])->render();

        expect($teks)->toContain($surel->tautan)
            ->and($teks)->not->toContain('&amp;');

        // Diklik persis seperti yang tertulis di badan email, bukan dari properti mailable.
        preg_match('#https?://\S+#', $teks, $cocok);
        $this->get($cocok[0] ?? '')->assertRedirect(route('masuk'))->assertSessionHasNoErrors();

        expect(Pengguna::query()->firstOrFail()->EmailDiverifikasiPada)->not->toBeNull();
    });

    it('tautan di badan HTML kembali utuh setelah entitas dipulihkan peramban', function (): void {
        $this->post('/daftar', IsianDaftarUji())->assertSessionHasNoErrors();
        $this->post('/keluar');

        $surel = null;
        Mail::assertQueued(VerifikasiEmail::class, function (VerifikasiEmail $dikirim) use (&$surel): bool {
            $surel = $dikirim;

            return true;
        });

        // Di badan HTML `&amp;` justru benar: peramban memulihkannya menjadi `&` saat tautan diklik.
        preg_match('#href="(https?://[^"]*verifikasi-email[^"]*)"#', $surel->render(), $cocok);
        $tautan = html_entity_decode($cocok[1] ?? '', ENT_QUOTES, 'UTF-8');

        expect($tautan)->toBe($surel->tautan);
        $this->get($tautan)->assertRedirect(route('masuk'))->assertSessionHasNoErrors();

        expect(Pengguna::query()->firstOrFail()->EmailDiverifikasiPada)->not->toBeNull();
    });

    it('karakter yang biasanya di-escape tampil apa adanya di badan teks', function (): void {
        // Bukan cuma `&` di tautan: `'`, `"`, `<`, dan `>` pada nama usaha juga rusak kalau di-escape
        // (`&#039;`, `&quot;`, `&lt;`), dan badan teks tidak pernah dirender sebagai HTML.
        $nama = 'Toko A & B "Jaya" <Pusat> milik Ani\'s';
        $teks = view('Surel.Tenant.VerifikasiEmail', [
            'Nama' => $nama,
            'Tautan' => 'https://contoh.test/verifikasi-email/a/b?expires=1&signature=abc',
            'JamBerlaku' => 24,
        ])->render();

        expect($teks)->toContain($nama)
            ->and($teks)->toContain('?expires=1&signature=abc')
            ->and($teks)->not->toContain('&amp;')
            ->and($teks)->not->toContain('&#039;')
            ->and($teks)->not->toContain('&quot;')
            ->and($teks)->not->toContain('&lt;');
    });
});

describe('Dokumen legal publik', function (): void {
    it('menampilkan versi yang berlaku; jenis tidak dikenal 404', function (): void {
        $this->get('/legal/syarat-ketentuan')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Situs/DokumenLegal')->where('Dokumen.Versi', 1));
        $this->get('/legal/tidak-ada')->assertNotFound();
    });
});
