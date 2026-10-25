<?php

declare(strict_types=1);

use App\Domain\Pengelola\Komunikasi\Aksi\KirimSiaranJatuhTempo;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusSiaran;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PesanSiaran;
use App\Domain\Pengelola\Komunikasi\Surel\SiaranPlatform;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * @param  array<string, mixed>  $segmen
 */
function BuatSiaranUji(KanalSiaran $kanal = KanalSiaran::Email, array $segmen = [], ?Carbon $jadwal = null, StatusSiaran $status = StatusSiaran::Terjadwal): PesanSiaran
{
    return PesanSiaran::query()->create([
        'Judul' => 'Pemeliharaan Minggu malam',
        'Isi' => "Layanan tidak tersedia 22.00–23.00 WIB.\n\nTerima kasih atas pengertiannya.",
        'Kanal' => $kanal,
        'Segmen' => SegmenSiaran::Bersihkan($segmen),
        'Status' => $status,
        'JadwalKirimPada' => $jadwal ?? now()->subMinute(),
        'IdPenggunaPengelola' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
}

beforeEach(function (): void {
    // Pagi hari WIB, di dalam jam kirim WhatsApp.
    $this->travelTo(Carbon::parse('2026-10-26 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']])]);
});

describe('D-210 segmen penerima', function (): void {
    it('menghitung Owner tenant aktif sesuai kanal, mengecualikan tenant Uji/Demo/Internal, dan menyaring menurut tenant', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        $uji = BantuanTenantPengelola::BuatTenant('Toko Uji', 'uji@contoh.id');
        Tenant::query()->whereKey($uji->Id)->update(['Penanda' => PenandaTenant::cases()[0]->value]);
        $segmen = app(SegmenSiaran::class);

        $semua = $segmen->Hitung([], KanalSiaran::Email);
        $terpilih = $segmen->Hitung(['Tenant' => [$a->Uuid]], KanalSiaran::Email);

        expect($semua['Penerima'])->toBe(2)->and($semua['TenantCocok'])->toBe(2)->and($semua['TanpaKontak'])->toBe(0)
            ->and($terpilih['Penerima'])->toBe(1)->and($terpilih['Contoh'])->toHaveCount(1);
        expect($segmen->Hitung(['Status' => ['Ditangguhkan']], KanalSiaran::Email)['Penerima'])->toBe(0);
    });

    it('Bersihkan membuang nilai yang tidak dikenal', function (): void {
        expect(SegmenSiaran::Bersihkan(['Status' => ['Trial', 'Ngawur', 5], 'Kesehatan' => ['Berisiko', 'x'], 'Paket' => 'bukan-array']))
            ->toMatchArray(['Status' => ['Trial'], 'Kesehatan' => ['Berisiko'], 'Paket' => []]);
    });
});

describe('D-210 membuat dan membatalkan siaran', function (): void {
    it('Super Admin menjadwalkan siaran; tercatat di audit', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->post(BantuanPengelola::Url('/komunikasi'), [
            'Judul' => 'Fitur baru Kotak Tindakan',
            'Isi' => 'Sekarang ada Kotak Tindakan di Beranda dashboard.',
            'Kanal' => 'Email',
            'Segmen' => ['Status' => ['Trial']],
            'JadwalKirimPada' => null,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $siaran = PesanSiaran::query()->sole();
        expect($siaran->Status)->toBe(StatusSiaran::Terjadwal)->and($siaran->Segmen['Status'])->toBe(['Trial']);
        expect(LogAuditPengelola::query()->where('Aksi', 'komunikasi.siaran.buat')->exists())->toBeTrue();
    });

    it('menolak siaran tanpa penerima, jadwal yang sudah lewat, dan isi yang terlalu pendek', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $dasar = ['Judul' => 'Judul uji', 'Isi' => 'Isi pesan yang cukup panjang.', 'Kanal' => 'Email'];

        $this->post(BantuanPengelola::Url('/komunikasi'), [...$dasar, 'Segmen' => ['Status' => ['Ditangguhkan']]])->assertSessionHasErrors('Segmen');
        $this->post(BantuanPengelola::Url('/komunikasi'), [...$dasar, 'JadwalKirimPada' => now()->subDay()->toIso8601String()])->assertSessionHasErrors('JadwalKirimPada');
        $this->post(BantuanPengelola::Url('/komunikasi'), [...$dasar, 'Isi' => 'pendek'])->assertSessionHasErrors('Isi');

        expect(PesanSiaran::query()->count())->toBe(0);
    });

    it('siaran terjadwal bisa dibatalkan, yang sudah mengirim tidak', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $terjadwal = BuatSiaranUji(jadwal: now()->addDay());
        $mengirim = BuatSiaranUji(status: StatusSiaran::Mengirim);

        $this->put(BantuanPengelola::Url("/komunikasi/{$terjadwal->Uuid}/batal"))->assertSessionHasNoErrors();
        $this->put(BantuanPengelola::Url("/komunikasi/{$mengirim->Uuid}/batal"))->assertSessionHasErrors('Umum');

        expect($terjadwal->refresh()->Status)->toBe(StatusSiaran::Dibatalkan)->and($mengirim->refresh()->Status)->toBe(StatusSiaran::Mengirim);
    });

    it('§19.3: hanya Super Admin; peran lain ditolak', function (PeranPengelolaBawaan $peran): void {
        BantuanTenantPengelola::Masuk($this, $peran);

        $this->get(BantuanPengelola::Url('/komunikasi'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/komunikasi'), [])->assertForbidden();
    })->with([PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Keuangan]);

    it('halaman daftar, buat (dengan tenant dari aksi massal), dan estimasi tampil untuk Super Admin', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->get(BantuanPengelola::Url('/komunikasi'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Pengelola/Komunikasi/Daftar'));
        $this->get(BantuanPengelola::Url('/komunikasi/buat?tenant[]='.$tenant->Uuid))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Komunikasi/Buat')
            ->where('TenantAwal.0.Nama', 'Toko A')
            ->where('Whatsapp.Tersedia', true));
        $this->getJson(BantuanPengelola::Url('/komunikasi/estimasi?Kanal=Email&Tenant[]='.$tenant->Uuid))->assertOk()->assertJson(['Penerima' => 1, 'TenantCocok' => 1]);
    });
});

describe('D-210 pengiriman bertahap', function (): void {
    it('email: menetapkan penerima, mengirim ke tiap Owner, dan menutup siaran dengan hitungan benar', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        $siaran = BuatSiaranUji();

        $hasil = app(KirimSiaranJatuhTempo::class)->Jalankan();

        expect($hasil)->toBe(['Siaran' => 1, 'Terkirim' => 2]);
        Mail::assertSent(SiaranPlatform::class, 2);
        Mail::assertSent(SiaranPlatform::class, fn (SiaranPlatform $surel): bool => $surel->hasTo('a@contoh.id') && count($surel->paragraf) === 2);
        $siaran->refresh();
        expect($siaran->Status)->toBe(StatusSiaran::Selesai)
            ->and([$siaran->JumlahPenerima, $siaran->JumlahTerkirim, $siaran->JumlahGagal])->toBe([2, 2, 0])
            ->and($siaran->SelesaiPada)->not->toBeNull();
    });

    it('siaran yang jadwalnya belum tiba tidak dikirim, dan dibatalkan tidak diproses', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $nanti = BuatSiaranUji(jadwal: now()->addHour());
        $batal = BuatSiaranUji(status: StatusSiaran::Dibatalkan);

        expect(app(KirimSiaranJatuhTempo::class)->Jalankan())->toBe(['Siaran' => 0, 'Terkirim' => 0]);
        Mail::assertNothingSent();
        expect($nanti->refresh()->Status)->toBe(StatusSiaran::Terjadwal)->and($batal->refresh()->Status)->toBe(StatusSiaran::Dibatalkan);
    });

    it('whatsapp: terkirim lewat penyedia tidak resmi dengan nomor yang dirapikan', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $siaran = BuatSiaranUji(KanalSiaran::WhatsApp);

        $hasil = app(KirimSiaranJatuhTempo::class)->Jalankan();

        expect($hasil['Terkirim'])->toBe(1)->and($siaran->refresh()->Status)->toBe(StatusSiaran::Selesai);
        Http::assertSent(fn ($permintaan): bool => str_contains((string) $permintaan->url(), 'fonnte.com') && str_contains(json_encode($permintaan->data()) ?: '', 'Pemeliharaan Minggu malam'));
    });

    it('whatsapp di luar jam 08.00–21.00 WIB menunggu, tidak dikirim', function (): void {
        $this->travelTo(Carbon::parse('2026-10-26 22:30:00', 'Asia/Jakarta'));
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $siaran = BuatSiaranUji(KanalSiaran::WhatsApp);

        expect(app(KirimSiaranJatuhTempo::class)->Jalankan())->toBe(['Siaran' => 0, 'Terkirim' => 0]);
        Http::assertNothingSent();
        expect($siaran->refresh()->Status)->toBe(StatusSiaran::Terjadwal);
    });

    it('whatsapp tanpa penyedia dikonfigurasi: penerima dilewati dengan alasan, siaran tetap selesai', function (): void {
        config(['integrasi.Whatsapp' => ['Penyedia' => null]]);
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $siaran = BuatSiaranUji(KanalSiaran::WhatsApp);

        app(KirimSiaranJatuhTempo::class)->Jalankan();

        $penerima = PenerimaSiaran::query()->sole();
        expect($penerima->Status)->toBe(StatusPenerimaSiaran::Dilewati)->and($penerima->Alasan)->toContain('belum dikonfigurasi')
            ->and($siaran->refresh()->JumlahDilewati)->toBe(1)->and($siaran->Status)->toBe(StatusSiaran::Selesai);
    });

    it('email yang gagal tercatat gagal dan tidak menghentikan penerima lain', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        $gagal = Mockery::mock();
        $gagal->shouldReceive('send')->once()->andThrow(new RuntimeException('Kotak surat penuh.'));
        $berhasil = Mockery::mock();
        $berhasil->shouldReceive('send')->once();
        Mail::shouldReceive('to')->with('a@contoh.id')->once()->andReturn($gagal);
        Mail::shouldReceive('to')->with('b@contoh.id')->once()->andReturn($berhasil);
        $siaran = BuatSiaranUji();

        app(KirimSiaranJatuhTempo::class)->Jalankan();

        $siaran->refresh();
        expect([$siaran->JumlahTerkirim, $siaran->JumlahGagal])->toBe([1, 1])
            ->and(PenerimaSiaran::query()->where('Status', StatusPenerimaSiaran::Gagal->value)->sole()->Alasan)->toBe('Kotak surat penuh.');
    });

    it('perintah komunikasi:kirim-siaran terdaftar di jadwal dan berjalan', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BuatSiaranUji();

        $this->artisan('komunikasi:kirim-siaran')->expectsOutputToContain('1 siaran diproses, 1 pesan terkirim.')->assertSuccessful();
    });
});

describe('D-210 ekspor daftar tenant', function (): void {
    it('mengunduh CSV sesuai cari, mengamankan sel berawalan rumus, dan mencatat audit', function (): void {
        BantuanTenantPengelola::BuatTenant('Toko Kopi A', 'a@contoh.id');
        BantuanTenantPengelola::BuatTenant('=HYPERLINK("x") Berbahaya', 'b@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $respons = $this->get(BantuanPengelola::Url('/tenant/ekspor?cari=Kopi'));

        $respons->assertOk()->assertDownload();
        $isi = $respons->streamedContent();
        expect($isi)->toContain('Toko Kopi A')->toContain('a@contoh.id')->not->toContain('Berbahaya');
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.daftar.ekspor')->exists())->toBeTrue();

        $semua = $this->get(BantuanPengelola::Url('/tenant/ekspor'))->streamedContent();
        expect($semua)->toContain("'=HYPERLINK");
    });

    it('peran tanpa izin lihat tenant ditolak', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::KontenLegal);

        $this->get(BantuanPengelola::Url('/tenant/ekspor'))->assertForbidden();
    });
});
