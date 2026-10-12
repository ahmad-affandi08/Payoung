<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Lisensi\Aksi\PasangLisensi;
use App\Domain\Lisensi\Galat\LisensiTidakSah;
use App\Domain\Lisensi\Kueri\LisensiBerlaku;
use App\Domain\Lisensi\Model\LisensiTerpasang;
use App\Domain\Organisasi\Data\DataPemilikBaru;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Layanan\PembuatQrKodeAktivasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Referensi\Model\HariLibur;
use App\Domain\Referensi\Model\SatuanStandar;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\EvaluatorFitur;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Lisensi\BantuanLisensi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanDaftarPengaturan;
use Tests\TestCase;

/*
 * D-35 edisi Lisensi: dashboard dipasang pembeli di server & domainnya sendiri. Aplikasi test dibuat dengan
 * EDISI=Lisensi (rute & jadwal didaftarkan saat aplikasi dibuat), lalu dikembalikan ke SaaS setelah berkas ini.
 */
beforeAll(function (): void {
    TestCase::$edisiUji = 'Lisensi';
});

afterAll(function (): void {
    TestCase::$edisiUji = null;
});

beforeEach(function (): void {
    LisensiBerlaku::Lupakan();
    BantuanLisensi::PasangKunciPublik();
    app(SiapkanKatalogBawaan::class)->Jalankan();
});

function PemilikLisensiUji(): DataPemilikBaru
{
    return new DataPemilikBaru(nama: 'Rina Wulandari', email: 'rina@kopinusantara.id', noHp: '081234567890', kataSandi: 'kata-sandi-kuat-123');
}

/**
 * Usaha edisi Lisensi yang sudah melewati panduan awal dan Owner-nya ber-2FA (BR-00.8), supaya halaman Pengaturan
 * terbuka langsung.
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna}
 */
function PasangUntukPemilikSiap(): array
{
    app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
    $tenant = Tenant::query()->sole();
    $pemilik = Pengguna::query()->sole();
    BantuanAutentikasi::AktifkanDuaFaktor($pemilik);
    BantuanOrganisasi::AturKonteks($tenant->Id);
    ProgresPanduanAwal::query()->update(['SelesaiPada' => now()]);

    return ['Tenant' => $tenant, 'Pemilik' => $pemilik];
}

describe('Edisi Lisensi (D-35)', function (): void {
    it('tanpa lisensi terpasang, back-office & API dijawab 503 dengan petunjuk pasang; /sehat tetap hidup', function (): void {
        $this->get('/masuk')->assertStatus(503)->assertSee('lisensi:pasang');
        $this->getJson('/api/pos/v1/data-awal')->assertStatus(503)->assertJsonPath('Galat.Kode', 'LisensiBelumTerpasang');
        $this->get('/sehat')->assertOk();
    });

    it('pemasangan pertama membuat satu usaha, Owner terverifikasi, langganan LISENSI Aktif tanpa akhir, panduan awal wajib', function (): void {
        $data = app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());

        $tenant = Tenant::query()->sole();
        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        $pengguna = Pengguna::query()->where('Email', 'rina@kopinusantara.id')->sole();

        expect($data->nomor)->toBe('PAYOUNG-L-2026-0001')
            ->and($tenant->Nama)->toBe('Kopi Nusantara')
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->Paket->Kode)->toBe('LISENSI')
            ->and($langganan->PeriodeSelesai)->toBeNull()
            ->and($langganan->TrialBerakhirPada)->toBeNull()
            ->and($pengguna->EmailDiverifikasiPada)->not->toBeNull()
            ->and(TenantPengguna::query()->where('IdTenant', $tenant->Id)->sole()->Pemilik)->toBeTrue()
            ->and(LisensiTerpasang::query()->count())->toBe(1);

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect(Outlet::query()->count())->toBe(1)
            ->and(ProgresPanduanAwal::query()->sole()->Wajib)->toBeTrue();
    });

    it('data master rilis langsung terbit tanpa konsol: tarif PPN, template sektor, satuan; idempoten', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());

        $ppn = TarifPajak::query()->whereHas('JenisPajak', fn ($k) => $k->where('Kode', 'Ppn'))->sole();
        $templateTerbit = TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->count();

        expect($ppn->Status)->toBe(StatusDataMaster::Terbit)
            ->and($templateTerbit)->toBeGreaterThan(0)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Draf->value)->count())->toBe(0)
            ->and(SatuanStandar::query()->exists())->toBeTrue();

        $this->artisan('lisensi:siapkan-data')->expectsOutputToContain('0 tarif pajak & 0 template sektor baru')->assertSuccessful();
        expect(TarifPajak::query()->count())->toBe(1)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->count())->toBe($templateTerbit);
    });

    it('impor data master Payoung: tarif sama dilewati, tarif baru mengakhiri yang lama, hari libur & pembatalan; idempoten', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        $paket = [
            'Format' => 1,
            'DibuatPada' => '2026-10-03T00:00:00Z',
            'TarifPajak' => [
                ['KodeJenisPajak' => 'Ppn', 'Tarif' => '12.000000', 'PengaliDppPembilang' => 11, 'PengaliDppPenyebut' => 12, 'KodeWilayah' => null, 'BiayaLayananMasukDpp' => false, 'BerlakuMulai' => '2025-01-01', 'NomorDasarHukum' => 'PMK 131 Tahun 2024', 'TautanDasarHukum' => null],
                ['KodeJenisPajak' => 'Ppn', 'Tarif' => '13', 'PengaliDppPembilang' => 1, 'PengaliDppPenyebut' => 1, 'KodeWilayah' => null, 'BiayaLayananMasukDpp' => false, 'BerlakuMulai' => '2030-01-01', 'NomorDasarHukum' => 'PMK uji', 'TautanDasarHukum' => null],
            ],
            'HariLibur' => [
                ['Tanggal' => '2026-08-17', 'Nama' => 'Hari Kemerdekaan RI', 'Jenis' => 'Nasional', 'NomorDasarHukum' => null, 'Dibatalkan' => false],
                ['Tanggal' => '2026-12-24', 'Nama' => 'Cuti Bersama Natal', 'Jenis' => 'CutiBersama', 'NomorDasarHukum' => null, 'Dibatalkan' => false],
            ],
        ];
        $berkas = tempnam(sys_get_temp_dir(), 'data');
        file_put_contents($berkas, json_encode($paket));

        $this->artisan('lisensi:impor-data-master', ['berkas' => $berkas])
            ->expectsOutputToContain('Tarif pajak baru: 1 (sudah ada/dilewati: 1). Hari libur baru: 2, dibatalkan: 0.')
            ->assertSuccessful();

        $tarif = TarifPajak::query()->orderBy('BerlakuMulai')->get();
        expect($tarif)->toHaveCount(2)
            ->and($tarif[0]->BerlakuSampai?->toDateString())->toBe('2029-12-31')
            ->and($tarif[1]->Status)->toBe(StatusDataMaster::Terbit);

        $paket['HariLibur'][1]['Dibatalkan'] = true;
        file_put_contents($berkas, json_encode($paket));
        $this->artisan('lisensi:impor-data-master', ['berkas' => $berkas])
            ->expectsOutputToContain('Tarif pajak baru: 0 (sudah ada/dilewati: 2). Hari libur baru: 0, dibatalkan: 1.')
            ->assertSuccessful();

        file_put_contents($berkas, '{"Format": 9}');
        $this->artisan('lisensi:impor-data-master', ['berkas' => $berkas])->expectsOutputToContain('formatnya tidak dikenal')->assertFailed();
        unlink($berkas);

        expect(HariLibur::query()->where('Nama', 'Cuti Bersama Natal')->sole()->Status)->toBe(StatusDataMaster::Dibatalkan)
            ->and(TarifPajak::query()->count())->toBe(2);
    });

    it('D-36: berkas data master yang dibuat setelah masa pembaruan lisensi ditolak; di dalam masa pembaruan diterima', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(BantuanLisensi::Data(pembaruanSampai: '2027-10-02')), 'Kopi Nusantara', PemilikLisensiUji());
        $paket = ['Format' => 1, 'DibuatPada' => '2027-10-02T16:59:59Z', 'TarifPajak' => [], 'HariLibur' => [
            ['Tanggal' => '2027-08-17', 'Nama' => 'Hari Kemerdekaan RI', 'Jenis' => 'Nasional', 'NomorDasarHukum' => null, 'Dibatalkan' => false],
        ]];
        $berkas = tempnam(sys_get_temp_dir(), 'data');
        file_put_contents($berkas, json_encode($paket));
        // 2027-10-02 23.59.59 WIB masih di dalam masa pembaruan.
        $this->artisan('lisensi:impor-data-master', ['berkas' => $berkas])->assertSuccessful();

        $paket['DibuatPada'] = '2027-10-02T17:00:00Z';
        $paket['HariLibur'][0] = ['Tanggal' => '2027-12-25', 'Nama' => 'Hari Raya Natal', 'Jenis' => 'Nasional', 'NomorDasarHukum' => null, 'Dibatalkan' => false];
        file_put_contents($berkas, json_encode($paket));
        $this->artisan('lisensi:impor-data-master', ['berkas' => $berkas])
            ->expectsOutputToContain('setelah masa pembaruan lisensi PAYOUNG-L-2026-0001 berakhir (2027-10-02)')
            ->assertFailed();
        unlink($berkas);

        expect(HariLibur::query()->where('Nama', 'Hari Raya Natal')->exists())->toBeFalse();
    });

    it('D-36: lisensi:terbitkan memberi masa pembaruan 1 tahun bila tidak diisi', function (): void {
        $folder = sys_get_temp_dir().'/lisensi-'.bin2hex(random_bytes(4));
        mkdir($folder, 0700);
        file_put_contents("{$folder}/payoung.kunci", BantuanLisensi::Kunci()['KunciPrivat']);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Jakarta'));

        $this->artisan('lisensi:terbitkan', ['--nomor' => 'PAYOUNG-L-2026-0009', '--pemegang' => 'CV Toko Uji', '--domain' => 'kasir.tokouji.id', '--kunci-privat' => "{$folder}/payoung.kunci", '--keluaran' => "{$folder}/uji.lisensi"])
            ->expectsOutputToContain('Pembaruan & dukungan sampai 2027-10-02')
            ->assertSuccessful();

        expect(json_decode((string) file_get_contents("{$folder}/uji.lisensi"), true)['Data']['PembaruanSampai'])->toBe('2027-10-02');
        array_map('unlink', glob("{$folder}/*") ?: []);
        rmdir($folder);
    });

    it('fitur: semua fitur katalog aktif, batas outlet/perangkat/pengguna dari lisensi, batas lain tak terbatas', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        $idTenant = Tenant::query()->sole()->Id;

        $sumber = app(SumberFiturTenant::class)->Ambil($idTenant);
        $evaluator = app(EvaluatorFitur::class);
        $batas = $evaluator->HitungBatasEfektif($sumber);

        expect($sumber->fiturPaket)->toBe(Fitur::query()->orderBy('Kunci')->pluck('Kunci')->all())
            ->and($sumber->fiturPaket)->not->toBeEmpty()
            ->and($evaluator->CekFiturAktif($sumber, $sumber->fiturPaket[0]))->toBeTrue()
            ->and($batas['BatasOutlet'])->toBe(2)
            ->and($batas['BatasPerangkatPerOutlet'])->toBe(3)
            ->and($batas['BatasPengguna'])->toBeNull()
            ->and($batas['BatasSku'])->toBeNull()
            ->and($evaluator->CekMasihDalamBatas($sumber, 'BatasOutlet', 1))->toBeTrue()
            ->and($evaluator->CekMasihDalamBatas($sumber, 'BatasOutlet', 2))->toBeFalse();
    });

    it('ganti lisensi (tambah outlet) tidak membuat usaha baru; satu lisensi tetap satu usaha', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(BantuanLisensi::Data(batasOutlet: 5, nomor: 'PAYOUNG-L-2026-0001-B')));

        $sumber = app(SumberFiturTenant::class)->Ambil(Tenant::query()->sole()->Id);

        expect(Tenant::query()->count())->toBe(1)
            ->and(LisensiTerpasang::query()->count())->toBe(2)
            ->and(app(LisensiBerlaku::class)->Ambil()?->nomor)->toBe('PAYOUNG-L-2026-0001-B')
            ->and(app(EvaluatorFitur::class)->HitungBatasEfektif($sumber)['BatasOutlet'])->toBe(5);
    });

    it('override batas yang disisipkan langsung ke basis data tidak menambah batas lisensi', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        $tenant = Tenant::query()->sole();
        OverrideTenant::query()->create([
            'IdTenant' => $tenant->Id,
            'Jenis' => JenisOverride::Batas,
            'Kunci' => 'BatasOutlet',
            'Nilai' => null,
            'BerakhirPada' => now()->addYear(),
            'Alasan' => 'Disisipkan langsung',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);

        $sumber = app(SumberFiturTenant::class)->Ambil($tenant->Id);

        expect(app(EvaluatorFitur::class)->HitungBatasEfektif($sumber)['BatasOutlet'])->toBe(2);
    });

    it('berkas palsu ditolak sebelum apa pun tersimpan; pemasangan pertama wajib menyebut usaha & Owner', function (): void {
        $palsu = json_decode(BantuanLisensi::Berkas(), true);
        $palsu['Data']['BatasOutlet'] = null;

        expect(fn () => app(PasangLisensi::class)->Jalankan((string) json_encode($palsu), 'Kopi Nusantara', PemilikLisensiUji()))
            ->toThrow(LisensiTidakSah::class)
            ->and(fn () => app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas()))
            ->toThrow(PelanggaranAturanBisnis::class, 'nama usaha')
            ->and(Tenant::query()->count())->toBe(0)
            ->and(LisensiTerpasang::query()->count())->toBe(0);
    });

    it('lisensi yang diubah langsung di basis data tidak lagi diakui (503)', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        $baris = LisensiTerpasang::query()->sole();
        $baris->update(['IsiBerkas' => str_replace('"BatasOutlet": 2', '"BatasOutlet": 50', $baris->IsiBerkas)]);
        LisensiBerlaku::Lupakan();

        $this->get('/masuk')->assertStatus(503);
    });

    it('hanya dilayani di domain lisensi; alamat utama ke halaman masuk tanpa tautan daftar', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());

        $this->get('/')->assertRedirect('/masuk');
        $this->get('/masuk')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Edisi', 'Lisensi'));
        // Terakhir: host permintaan absolut ini dipakai ulang oleh permintaan relatif berikutnya di test yang sama.
        $this->get('http://kasir.tokolain.id/masuk')->assertStatus(503)->assertSee('localhost');
    });

    it('tanpa konsol pengelola, situs pemasaran, pendaftaran, langganan, maupun tiket bantuan', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());

        expect(Route::has('pengelola.masuk'))->toBeFalse()
            ->and(Route::has('situs.blog.daftar'))->toBeFalse()
            ->and(Route::has('daftar'))->toBeFalse()
            ->and(Route::has('kelola.langganan.tampil'))->toBeFalse()
            ->and(Route::has('kelola.bantuan.daftar'))->toBeFalse()
            ->and(Route::has('kelola.beranda'))->toBeTrue()
            ->and(Route::has('publik.toko-online'))->toBeTrue();

        $this->get('/daftar')->assertNotFound();
        $this->get('/blog')->assertNotFound();
    });

    it('menu Langganan, Bantuan & Akses dukungan dimatikan juga untuk Pemilik lewat IzinNonaktif', function (): void {
        app(PasangLisensi::class)->Jalankan(BantuanLisensi::Berkas(), 'Kopi Nusantara', PemilikLisensiUji());
        $pengguna = Pengguna::query()->sole();

        $this->actingAs($pengguna)
            ->withSession(['IdTenantAktif' => Tenant::query()->sole()->Id])
            // Halaman keamanan akun terbuka sebelum 2FA wajib Owner diaktifkan (BR-00.8).
            ->get('/kelola/keamanan')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Akses.IzinNonaktif', ['langganan.kelola', 'bantuan.tiket.lihat', 'bantuan.tiket.kelola', 'dukungan.akses.kelola'])
                ->where('Akses.Pemilik', true));
    });

    it('jadwal tagihan, trial, dan Platform Pengelola tidak berjalan; jadwal operasional toko tetap', function (): void {
        $acara = collect(app(Schedule::class)->events());
        $lolos = fn (string $perintah): bool => $acara->first(fn ($e) => str_contains((string) $e->command, $perintah))->filtersPass(app());

        expect($lolos('tagihan:terbitkan-perpanjangan'))->toBeFalse()
            ->and($lolos('tenant:akhiri-trial'))->toBeFalse()
            ->and($lolos('pengelola:detak'))->toBeFalse()
            ->and($lolos('kasir:tutup-harian-otomatis'))->toBeTrue();
    });

    it('lisensi:atur-integrasi: WhatsApp Fonnte disimpan terenkripsi, diuji, lalu aktif tanpa konsol', function (): void {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true, 'device_status' => 'connect'])]);

        $this->artisan('lisensi:atur-integrasi', ['jenis' => 'Whatsapp'])
            ->expectsChoice('Penyedia', 'Fonnte', array_map(fn (PenyediaIntegrasi $p): string => $p->value, array_values(array_filter(PenyediaIntegrasi::cases(), fn (PenyediaIntegrasi $p): bool => $p->AmbilJenis() === JenisIntegrasi::Whatsapp))))
            ->expectsQuestion('Token perangkat Fonnte', 'tok-fonnte-rahasia-1234')
            ->expectsOutputToContain('tersambung dan aktif')
            ->assertSuccessful();

        $konfigurasi = KonfigurasiIntegrasi::query()->sole();

        expect($konfigurasi->Aktif)->toBeTrue()
            ->and($konfigurasi->Penyedia)->toBe(PenyediaIntegrasi::Fonnte)
            ->and($konfigurasi->Kredensial['Token'])->toBe('tok-fonnte-rahasia-1234')
            ->and((string) $konfigurasi->getRawOriginal('Kredensial'))->not->toContain('tok-fonnte');
    });

    it('Owner mengatur WhatsApp server dari back-office: simpan, uji & aktif, nonaktifkan; kredensial tidak kembali ke halaman', function (): void {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true, 'device_status' => 'connect'])]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = PasangUntukPemilikSiap();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/integrasi-server', ['Jenis' => 'Whatsapp', 'Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tok-fonnte-rahasia-1234']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $konfigurasi = KonfigurasiIntegrasi::query()->sole();
        expect($konfigurasi->Aktif)->toBeFalse()
            ->and($konfigurasi->Kredensial['Token'])->toBe('tok-fonnte-rahasia-1234');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/integrasi-server/whatsapp/uji')
            ->assertSessionHasNoErrors();
        expect($konfigurasi->refresh()->Aktif)->toBeTrue();

        $halaman = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/pengaturan/integrasi-server');
        $halaman->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pengaturan/IntegrasiServer')
            ->has('Integrasi', 3)
            ->where('Integrasi.0.Jenis', 'Email')
            ->where('Integrasi.2.Jenis', 'Whatsapp')
            ->where('Integrasi.2.Konfigurasi.Aktif', true)
            ->missing('Integrasi.2.Konfigurasi.Kredensial'));
        expect($halaman->getContent())->not->toContain('tok-fonnte-rahasia');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/integrasi-server/whatsapp/nonaktifkan')
            ->assertSessionHasNoErrors();
        expect($konfigurasi->refresh()->Aktif)->toBeFalse();

        $audit = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'like', 'integrasi.server.%')->orderBy('Id')->pluck('Peristiwa')->all();
        expect($audit)->toBe(['integrasi.server.simpan', 'integrasi.server.uji', 'integrasi.server.nonaktifkan'])
            ->and(LogAudit::query()->where('Peristiwa', 'integrasi.server.simpan')->sole()->NilaiBaru)->not->toHaveKey('Kredensial');
    });

    it('setiap tautan daftar pengaturan edisi Lisensi menunjuk rute yang ada (termasuk Email & WhatsApp server)', function (): void {
        $tautan = BantuanDaftarPengaturan::AmbilTautanEdisi('Lisensi');

        expect($tautan)->toContain('/kelola/pengaturan/integrasi-server')
            ->and(BantuanDaftarPengaturan::CariTautanTanpaRute($tautan))->toBe([]);
    });

    it('integrasi server: hanya Owner; jenis platform lain dan penyedia jenis lain ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = PasangUntukPemilikSiap();
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        BantuanAutentikasi::AktifkanDuaFaktor($admin);

        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)->get('/kelola/pengaturan/integrasi-server')->assertForbidden();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/integrasi-server', ['Jenis' => 'GerbangBilling', 'Penyedia' => 'DokuBilling', 'Pengaturan' => [], 'Kredensial' => []])
            ->assertSessionHasErrors('Jenis');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/integrasi-server', ['Jenis' => 'Whatsapp', 'Penyedia' => 'Smtp', 'Pengaturan' => [], 'Kredensial' => []])
            ->assertSessionHasErrors('Penyedia');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/pengaturan/integrasi-server/captcha/uji')->assertNotFound();

        expect(KonfigurasiIntegrasi::query()->count())->toBe(0);
    });

    it('QR aktivasi perangkat membawa alamat server pembeli', function (): void {
        config(['app.url' => 'https://kasir.tokoabc.id/']);

        expect(PembuatQrKodeAktivasi::AmbilIsi('AB12CD34'))->toBe('https://kasir.tokoabc.id/aktivasi-perangkat?kode=AB12CD34');
    });

    it('perintah lisensi:pasang meminta data Owner, kata sandi tersembunyi, lalu lisensi:info menampilkannya', function (): void {
        $berkas = tempnam(sys_get_temp_dir(), 'lisensi');
        file_put_contents($berkas, BantuanLisensi::Berkas());

        $this->artisan('lisensi:pasang', [
            'berkas' => $berkas,
            '--nama-usaha' => 'Kopi Nusantara',
            '--nama' => 'Rina Wulandari',
            '--email' => 'Rina@KopiNusantara.id',
            '--hp' => '081234567890',
        ])
            ->expectsQuestion('Kata sandi Owner (minimal 12 karakter, huruf dan angka)', 'kata-sandi-kuat-123')
            ->expectsQuestion('Ulangi kata sandi', 'kata-sandi-kuat-123')
            ->expectsOutputToContain('terpasang di domain localhost')
            ->assertSuccessful();

        LisensiBerlaku::Lupakan();
        $this->artisan('lisensi:info')->expectsOutputToContain('PT Kopi Nusantara Sejahtera')->assertSuccessful();
        unlink($berkas);

        expect(Pengguna::query()->sole()->Email)->toBe('rina@kopinusantara.id');
    });
});
