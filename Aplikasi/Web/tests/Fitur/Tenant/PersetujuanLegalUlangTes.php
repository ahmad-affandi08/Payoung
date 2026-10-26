<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Enum\JenisDokumenLegal;
use App\Domain\Tenant\Model\PengumumanDokumenLegal;
use App\Domain\Tenant\Model\PersetujuanDokumenLegal;
use App\Domain\Tenant\Surel\PengumumanDokumenLegal as SurelPengumumanDokumenLegal;
use App\Http\Perantara\IdentifikasiTenantSesi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    $hasil = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
    $this->tenant = $hasil['Tenant'];
    $this->pemilik = $hasil['Pengguna'];
});

describe('Persetujuan ulang versi materiil (BR-P06.5)', function (): void {
    it('Owner diminta menyetujui versi materiil setelah berlaku, lalu persetujuannya tercatat dengan IP', function (): void {
        $versiBaru = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23');
        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id]);

        // Masa pengumuman: back-office tetap terbuka dan banner tampil.
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Beranda')
            ->has('PengumumanLegal', 1)
            ->where('PengumumanLegal.0.Label', 'Syarat & Ketentuan')
            ->where('PengumumanLegal.0.BerlakuMulai', '2026-10-23')
            ->where('PengumumanLegal.0.Tautan', route('legal.tampil', ['jenis' => 'syarat-ketentuan', 'versi' => 2])));

        $this->travelTo(Carbon::parse('2026-10-23 07:30:00', 'Asia/Jakarta'));
        $this->get('/kelola')->assertRedirect(route('kelola.persetujuan-legal'));
        $this->get('/kelola/panduan-awal')->assertRedirect(route('kelola.persetujuan-legal'));
        $this->get('/kelola/persetujuan-legal')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Autentikasi/PersetujuanLegal')
            ->has('Dokumen', 1)
            ->where('Dokumen.0.Uuid', $versiBaru->Uuid)
            ->where('Dokumen.0.Versi', 2)
            ->where('PengumumanLegal', []));

        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$versiBaru->Uuid], 'Setuju' => false])->assertSessionHasErrors('Setuju');
        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$versiBaru->Uuid], 'Setuju' => true], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertRedirect(route('kelola.panduan-awal'));

        $persetujuan = PersetujuanDokumenLegal::query()->where('IdDokumenLegal', $versiBaru->Id)->sole();
        expect($persetujuan->IdTenant)->toBe($this->tenant->Id)
            ->and($persetujuan->IdPengguna)->toBe($this->pemilik->Id)
            ->and($persetujuan->Ip)->toBe('198.51.100.7');
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    });

    it('persetujuan tercatat di log audit tenant (legal.setujui) dengan pelaku, versi, dan IP', function (): void {
        $versiBaru = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::KebijakanPrivasi, 3, '2026-10-23');
        $this->travelTo(Carbon::parse('2026-10-23 07:30:00', 'Asia/Jakarta'));
        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id]);

        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$versiBaru->Uuid], 'Setuju' => true], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertSessionHasNoErrors();

        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'legal.setujui')->sole();
        expect($log->IdTenant)->toBe($this->tenant->Id)
            ->and($log->IdPengguna)->toBe($this->pemilik->Id)
            ->and($log->Ip)->toBe('198.51.100.7')
            ->and($log->NilaiBaru)->toBe(['Dokumen' => ['KebijakanPrivasi v3']]);
    });

    it('versi tidak materiil tidak meminta persetujuan ulang dan tidak diumumkan', function (): void {
        BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::KebijakanPrivasi, 2, '2026-09-24', materiil: false);
        $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'Asia/Jakarta'));

        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id])
            ->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda')->where('PengumumanLegal', []));
    });

    it('menyetujui versi terbaru menutup versi materiil yang terlewat. Versi tak materiil sesudahnya ikut disetujui', function (): void {
        BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23');
        $terbaru = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 3, '2026-11-01', materiil: false);
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id]);

        $this->get('/kelola/persetujuan-legal')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Dokumen.0.Versi', 3));
        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$terbaru->Uuid], 'Setuju' => true])->assertSessionHasNoErrors();
        $this->get('/kelola')->assertOk();
    });

    it('Perjanjian Pemrosesan Data materiil yang terbit setelah registrasi juga wajib disetujui, yang berlaku sebelum registrasi tidak', function (): void {
        // PPD v1 sudah disetujui saat registrasi (wajib sejak v1.26); v2 materiil terbit setelahnya.
        $ppd = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::PerjanjianPemrosesanData, 2, '2026-10-23');
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id])
            ->get('/kelola/persetujuan-legal')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Dokumen.0.Uuid', $ppd->Uuid));

        // Owner yang mendaftar setelah versi itu berlaku menyetujuinya saat registrasi, jadi tidak diminta ulang.
        $baru = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'));
        $this->flushSession();
        $this->actingAs($baru['Pengguna'], 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $baru['Tenant']->Id])
            ->get('/kelola')->assertOk();
    });

    it('anggota bukan Owner tidak diminta menyetujui. Halaman persetujuan menolak kirimannya', function (): void {
        $versiBaru = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23');
        $anggota = Pengguna::factory()->create();
        TenantPengguna::query()->create(['IdTenant' => $this->tenant->Id, 'IdPengguna' => $anggota->Id]);
        $this->travelTo(Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta'));

        $this->actingAs($anggota, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id]);
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda')->where('PengumumanLegal', []));
        $this->get('/kelola/persetujuan-legal')->assertRedirect(route('kelola.beranda'));
        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$versiBaru->Uuid], 'Setuju' => true])->assertForbidden();
        expect(PersetujuanDokumenLegal::query()->where('IdDokumenLegal', $versiBaru->Id)->exists())->toBeFalse();
    });

    it('isolasi tenant: persetujuan dicatat per tenant; Owner dua usaha menyetujui di masing-masing', function (): void {
        $kedua = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'))['Tenant'];
        TenantPengguna::query()->create(['IdTenant' => $kedua->Id, 'IdPengguna' => $this->pemilik->Id, 'Pemilik' => true]);
        $versiBaru = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23');
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));

        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id]);
        $this->post('/kelola/persetujuan-legal', ['Dokumen' => [$versiBaru->Uuid], 'Setuju' => true])->assertSessionHasNoErrors();
        $this->get('/kelola')->assertOk();

        $this->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $kedua->Id])->get('/kelola')->assertRedirect(route('kelola.persetujuan-legal'));
        expect(PersetujuanDokumenLegal::query()->where('IdDokumenLegal', $versiBaru->Id)->pluck('IdTenant')->all())->toBe([$this->tenant->Id]);
    });

    it('versi yang disetujui harus persis yang ditampilkan', function (): void {
        $sk = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23');
        BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::KebijakanPrivasi, 2, '2026-10-23');
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));

        $this->actingAs($this->pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $this->tenant->Id])
            ->post('/kelola/persetujuan-legal', ['Dokumen' => [$sk->Uuid], 'Setuju' => true])->assertSessionHasErrors('Umum');
        expect(PersetujuanDokumenLegal::query()->where('IdDokumenLegal', $sk->Id)->exists())->toBeFalse();
    });
});

describe('Pengumuman versi materiil ke Owner (BR-P06.5)', function (): void {
    it('perintah harian mengirim email sekali per versi per Owner, termasuk Owner yang bergabung belakangan', function (): void {
        $kedua = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'))['Tenant'];
        // Rina Owner di dua tenant, tetapi hanya menerima satu email per versi.
        TenantPengguna::query()->create(['IdTenant' => $kedua->Id, 'IdPengguna' => $this->pemilik->Id, 'Pemilik' => true]);
        $anggota = Pengguna::factory()->create(['Email' => 'kasir@kopinusantara.id']);
        TenantPengguna::query()->create(['IdTenant' => $this->tenant->Id, 'IdPengguna' => $anggota->Id]);

        $materiil = BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::KebijakanPrivasi, 2, '2026-10-23');
        BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::SyaratKetentuan, 2, '2026-10-23', materiil: false);

        $this->artisan('tenant:umumkan-dokumen-legal')->expectsOutputToContain('2 email')->assertSuccessful();
        Mail::assertSent(SurelPengumumanDokumenLegal::class, 2);
        Mail::assertSent(SurelPengumumanDokumenLegal::class, fn (SurelPengumumanDokumenLegal $surel) => $surel->hasTo('rina@kopinusantara.id')
            && $surel->versi === 2
            && $surel->labelDokumen === 'Kebijakan Privasi'
            && $surel->berlakuMulai === '23 Okt 2026'
            && $surel->tautan === route('legal.tampil', ['jenis' => 'kebijakan-privasi', 'versi' => 2]));
        Mail::assertNotSent(SurelPengumumanDokumenLegal::class, fn (SurelPengumumanDokumenLegal $surel) => $surel->hasTo('kasir@kopinusantara.id'));

        $this->artisan('tenant:umumkan-dokumen-legal')->expectsOutputToContain('0 email')->assertSuccessful();

        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('sari@warung.id', '081200000088', namaUsaha: 'Warung Sari'));
        $this->artisan('tenant:umumkan-dokumen-legal')->expectsOutputToContain('1 email')->assertSuccessful();
        expect(PengumumanDokumenLegal::query()->where('IdDokumenLegal', $materiil->Id)->count())->toBe(3);

        // Setelah berlaku, pengumuman berhenti (berganti menjadi permintaan persetujuan).
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('dewi@toko.id', '081200000099', namaUsaha: 'Toko Dewi'));
        $this->artisan('tenant:umumkan-dokumen-legal')->expectsOutputToContain('0 email')->assertSuccessful();
    });

    it('versi terjadwal bisa dibaca publik lewat ?versi, draf tidak', function (): void {
        BantuanAutentikasi::TerbitkanVersi(JenisDokumenLegal::KebijakanPrivasi, 2, '2026-10-23');

        $this->get('/legal/kebijakan-privasi?versi=2')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Dokumen.Versi', 2)
            ->where('Dokumen.Terjadwal', true));
        $this->get('/legal/kebijakan-privasi')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Dokumen.Versi', 1)
            ->where('Dokumen.Terjadwal', false));
        $this->get('/legal/kebijakan-privasi?versi=9')->assertNotFound();
    });

    it('perintah terdaftar di jadwal harian', function (): void {
        $this->artisan('schedule:list')->expectsOutputToContain('tenant:umumkan-dokumen-legal')->assertSuccessful();
    });
});
