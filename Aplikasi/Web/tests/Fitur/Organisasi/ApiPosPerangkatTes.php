<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Tenant\Enum\StatusLangganan;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('POST /api/pos/v1/perangkat/aktivasi (F-02 langkah 5)', function (): void {
    it('menukar kode menjadi token perangkat + kode perangkat + info outlet & tenant (key PascalCase, waktu UTC)', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Perangkat' => $perangkat, 'Kode' => $kode] = BantuanPerangkat::BuatPerangkat($tenant->Id);

        $respons = $this->postJson('/api/pos/v1/perangkat/aktivasi', [
            'Kode' => strtolower(substr($kode, 0, 4)).'-'.substr($kode, 4),
            'Platform' => 'Android',
            'VersiAplikasi' => '1.2.0+45',
            'VersiOs' => 'Android 14',
        ])->assertCreated();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->findOrFail($perangkat->IdOutlet);
        $respons->assertJsonPath('Perangkat.Uuid', $perangkat->Uuid)
            ->assertJsonPath('Perangkat.Kode', "{$outlet->Kode}-K01")
            ->assertJsonPath('Perangkat.Jenis', 'Kasir')
            ->assertJsonPath('Perangkat.Platform', 'Android')
            ->assertJsonPath('Outlet.Uuid', $outlet->Uuid)
            ->assertJsonPath('Outlet.Kode', $outlet->Kode)
            ->assertJsonPath('Tenant.Uuid', $tenant->Uuid)
            ->assertJsonPath('Tenant.Nama', 'Kopi Nusantara')
            ->assertJsonPath('Langganan.Status', 'Trial')
            ->assertJsonPath('Langganan.BolehBertransaksi', true);
        expect($respons->json('Perangkat.DiaktifkanPada'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

        $token = (string) $respons->json('TokenPerangkat');
        [$idTenant, $rahasia] = explode('|', $token);
        $perangkat->refresh();
        expect((int) $idTenant)->toBe($tenant->Id)
            ->and(strlen($rahasia))->toBeGreaterThanOrEqual(80)
            ->and($perangkat->HashToken)->toBe(hash('sha256', $rahasia))
            ->and($perangkat->AmbilStatus())->toBe('Aktif')
            ->and($perangkat->VersiAplikasi)->toBe('1.2.0+45')
            ->and($perangkat->VersiOs)->toBe('Android 14');

        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'perangkat.aktivasi')->sole();
        expect($log->IdPerangkat)->toBe($perangkat->Id)->and($log->IdPengguna)->toBeNull()
            ->and(json_encode($log->NilaiBaru))->not->toContain($rahasia);
    });

    it('kode sekali pakai: penukaran kedua ditolak dengan masalah seragam', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kode = BantuanPerangkat::BuatPerangkat($tenant->Id)['Kode'];
        BantuanPerangkat::Aktifkan($this, $kode);

        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => $kode, 'Platform' => 'Ios'])
            ->assertStatus(422)
            ->assertExactJson(['Galat' => [
                'Kode' => 'KodeAktivasiTidakBerlaku',
                'Pesan' => 'Kode aktivasi salah, kedaluwarsa, atau sudah dipakai. Minta kode baru di back-office menu Perangkat.',
                'Detail' => [],
            ]]);
    });

    it('kode kedaluwarsa setelah 15 menit dan kode yang salah ditolak dengan masalah yang sama', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kode = BantuanPerangkat::BuatPerangkat($tenant->Id)['Kode'];

        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => 'ZZZZ2222', 'Platform' => 'Android'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'KodeAktivasiTidakBerlaku');
        $this->travel(16)->minutes();
        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => $kode, 'Platform' => 'Android'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'KodeAktivasiTidakBerlaku');
    });

    it('kode lama tidak berlaku setelah kode baru dibuat; aktivasi ulang mengganti token sehingga instalasi lama keluar', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Token' => $tokenLama] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/kode-aktivasi");
        $kodeBaru = session('KodeAktivasiBaru')['Kode'];
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/kode-aktivasi");
        $kodeTerbaru = session('KodeAktivasiBaru')['Kode'];

        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => $kodeBaru, 'Platform' => 'Windows'])->assertStatus(422);
        $tokenBaru = BantuanPerangkat::Aktifkan($this, $kodeTerbaru, 'Windows');

        $this->withToken($tokenLama)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertUnauthorized()->assertJsonPath('Galat.Kode', 'TokenPerangkatTidakValid');
        $this->withToken($tokenBaru)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('Perangkat.Platform', 'Windows');
    });

    it('validasi & rate limit memakai format masalah seragam', function (): void {
        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => 'ABCD2345', 'Platform' => 'Symbian'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'ValidasiGagal')
            ->assertJsonStructure(['Galat' => ['Kode', 'Pesan', 'Detail' => ['Platform']]]);

        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => 'ABCD2345', 'Platform' => 'Android'])->assertStatus(422);
        }

        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => 'ABCD2345', 'Platform' => 'Android'])
            ->assertStatus(429)
            ->assertJsonPath('Galat.Kode', 'TerlaluBanyakPermintaan');
        $this->getJson('/api/pos/v1/tidak-ada')->assertNotFound()->assertJsonPath('Galat.Kode', 'TidakDitemukan');
    });

    it('tenant yang langganannya ditangguhkan tidak bisa mengaktifkan perangkat', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kode = BantuanPerangkat::BuatPerangkat($tenant->Id)['Kode'];
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Ditangguhkan);

        $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => $kode, 'Platform' => 'Android'])->assertForbidden()->assertJsonPath('Galat.Kode', 'LanggananTidakAktif');

        // Kode tidak hangus karena penolakan; setelah turun ke paket Gratis aktivasi berhasil.
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Gratis);
        BantuanPerangkat::Aktifkan($this, $kode);
    });
});

describe('Device token untuk /api/pos/v1 (AutentikasiPerangkat)', function (): void {
    it('tanpa token, token rusak, atau token asal ditolak 401', function (string $token): void {
        $permintaan = $token === '' ? $this : $this->withToken($token);
        $permintaan->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertUnauthorized()
            ->assertJsonPath('Galat.Kode', 'TokenPerangkatTidakValid');
    })->with([
        'tanpa token' => [''],
        'format salah' => ['bukan-token'],
        'rahasia asal' => ['1|'.str_repeat('a', 86)],
    ]);

    it('BR-02.3: perangkat yang dicabut langsung ditolak 403 PerangkatDicabut', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/cabut")->assertSessionHasNoErrors();

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertForbidden()
            ->assertJsonPath('Galat.Kode', 'PerangkatDicabut')
            ->assertJsonStructure(['Galat' => ['Kode', 'Pesan', 'Detail' => ['DicabutPada']]]);
        $this->withToken($token)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $pemilik->Uuid, 'Pin' => '482915'])
            ->assertForbidden()
            ->assertJsonPath('Galat.Kode', 'PerangkatDicabut');
    });

    it('isolasi tenant: token tenant A dengan IdTenant diganti B tidak membuka tenant B, dan hanya membaca outletnya sendiri', function (): void {
        ['Tenant' => $tenantA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        ['Token' => $tokenA] = BantuanPerangkat::BuatDanAktifkan($this, $tenantA->Id);
        BantuanPerangkat::BuatDanAktifkan($this, $tenantB->Id);
        [, $rahasiaA] = explode('|', $tokenA);

        $this->withToken("{$tenantB->Id}|{$rahasiaA}")->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertUnauthorized();

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        $outletA = Outlet::query()->orderBy('Id')->firstOrFail();
        $this->withToken($tokenA)->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertOk()
            ->assertJsonPath('Outlet.Uuid', $outletA->Uuid);
    });

    it('memperbarui TerakhirAktifPada & VersiAplikasi dari header X-Versi-Aplikasi', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        $this->travel(10)->minutes();

        $this->withToken($token)->withHeader('X-Versi-Aplikasi', '1.3.0')->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $perangkat = Perangkat::query()->findOrFail($perangkat->Id);
        expect($perangkat->VersiAplikasi)->toBe('1.3.0')
            ->and($perangkat->TerakhirAktifPada?->diffInSeconds(now(), true))->toBeLessThan(5);
    });
});

describe('GET /api/pos/v1/konfigurasi-aplikasi (§14.6)', function (): void {
    it('mengembalikan versi terbaru & minimal per platform dari konfigurasi dan menandai wajib perbarui', function (): void {
        config([
            'aplikasi.Pos.Android.VersiTerbaru' => '1.4.0',
            'aplikasi.Pos.Android.VersiMinimal' => '1.2.0',
            'aplikasi.Pos.Windows.VersiTerbaru' => '1.1.0',
        ]);
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        $this->withToken($token)->withHeader('X-Versi-Aplikasi', '1.1.9+77')->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertOk()
            ->assertJsonPath('Aplikasi.Platform', 'Android')
            ->assertJsonPath('Aplikasi.VersiTerbaru', '1.4.0')
            ->assertJsonPath('Aplikasi.VersiMinimal', '1.2.0')
            ->assertJsonPath('Aplikasi.AdaPembaruan', true)
            ->assertJsonPath('Aplikasi.WajibPembaruan', true)
            ->assertJsonPath('Aplikasi.PerPlatform.Windows.VersiTerbaru', '1.1.0')
            ->assertJsonPath('Langganan.BolehBertransaksi', true)
            ->assertJsonStructure(['Outlet' => ['Uuid', 'Kode', 'Nama', 'ZonaWaktu', 'JamTutupBuku'], 'Perangkat' => ['Uuid', 'Kode'], 'WaktuServer']);

        $this->withToken($token)->withHeader('X-Versi-Aplikasi', '1.4.0')->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertJsonPath('Aplikasi.AdaPembaruan', false)
            ->assertJsonPath('Aplikasi.WajibPembaruan', false);
    });

    it('tetap bisa dibuka saat langganan ditangguhkan, dengan BolehBertransaksi = false', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Ditangguhkan);

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')
            ->assertOk()
            ->assertJsonPath('Langganan.Status', 'Ditangguhkan')
            ->assertJsonPath('Langganan.BolehBertransaksi', false);
    });
});
