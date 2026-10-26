<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusKampanye;
use App\Domain\Pelanggan\Enum\StatusPenerimaKampanye;
use App\Domain\Pelanggan\Layanan\TautanBerhentiLangganan;
use App\Domain\Pelanggan\Model\KampanyePesan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PenerimaKampanye;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * CRM-07 kampanye pesan bersegmen: hanya pelanggan yang setuju promosi, segmen RFM, pratinjau penerima, kirim bertahap
 * lewat WhatsApp (D-33: kanal email ditutup), tujuan terenkripsi, tautan berhenti berlangganan bertanda tangan (UU PDP),
 * jadwal, batal, satu kampanye berjalan, izin `pelanggan.kelola`, isolasi tenant.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['5001']])]);
});

/** Pesan WhatsApp kampanye yang dikirim ke penyedia (Fonnte). */
function PesanKampanyeTerkirim(): Collection
{
    return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
        ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send'))->values();
}

/**
 * Ani (setuju, belanja sekali hari ini → Baru), Budi (setuju, belum belanja), Citra (tidak setuju, belanja), Dedi
 * (setuju, nomor HP tidak sah). Pemilik masuk, fitur WhatsApp usaha aktif.
 *
 * @return array<string, mixed>
 */
function SiapkanKampanye(TestCase $tes, string $nama = 'Kopi Senja Kampanye'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $buat = fn (string $nama, string $hp, ?string $email, bool $setuju): Pelanggan => Pelanggan::query()->create([
        'Nama' => $nama, 'NoHp' => $hp, 'Email' => $email, 'SetujuPemasaran' => $setuju,
    ]);
    $ani = $buat('Ani Lestari', '6281200000001', 'ani@contoh.id', true);
    $budi = $buat('Budi Santoso', '6281200000002', 'budi@contoh.id', true);
    $citra = $buat('Citra Dewi', '6281200000003', 'citra@contoh.id', false);
    $dedi = $buat('Dedi Kurnia', '12345', 'dedi@contoh.id', true);
    $jual = fn (Pelanggan $p): array => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidPelanggan' => $p->Uuid]);

    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$jual($ani), $jual($citra)]))->toBe([['Diterima', null], ['Diterima', null]]);
    OverrideTenant::query()->create([
        'IdTenant' => $k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji kampanye pesan lewat WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);

    return $k + ['Ani' => $ani, 'Budi' => $budi, 'Citra' => $citra, 'Dedi' => $dedi];
}

/** @return array<string, mixed> */
function IsianKampanye(array $timpa = []): array
{
    return [
        'Nama' => 'Promo kopi gula aren Oktober',
        'Kanal' => 'Whatsapp',
        'Isi' => 'Halo {nama}, minggu ini kopi gula aren diskon 20% di {toko}. Tunjukkan pesan ini ke kasir.',
        'Segmen' => [],
        ...$timpa,
    ];
}

it('pratinjau hanya menghitung yang setuju promosi & punya nomor WhatsApp sah. Kirim WhatsApp bertahap, tujuan terenkripsi, selesai', function (): void {
    $k = SiapkanKampanye($this);

    $this->postJson('/kelola/pelanggan/kampanye/pratinjau', ['Kanal' => 'Whatsapp', 'Segmen' => []])->assertOk()->assertJson([
        'JumlahPenerima' => 2,
        'TanpaKontak' => 1,
        'PerSegmen' => ['Baru' => 1, 'BelumBelanja' => 2, 'Juara' => 0],
    ]);
    $this->postJson('/kelola/pelanggan/kampanye/pratinjau', ['Kanal' => 'Whatsapp', 'Segmen' => ['Rfm' => ['Baru']]])->assertJson(['JumlahPenerima' => 1, 'TanpaKontak' => 0]);

    // D-33: kampanye email tidak bisa dibuat lagi; pilihan kanal di formulir hanya WhatsApp.
    $this->post('/kelola/pelanggan/kampanye', IsianKampanye(['Kanal' => 'Email', 'Judul' => 'Diskon 20% kopi gula aren']))
        ->assertSessionHasErrors(['Kanal' => 'Kampanye lewat email tidak tersedia lagi. Pilih WhatsApp.']);
    $this->get('/kelola/pelanggan/kampanye/buat')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('OpsiKanal', [['Nilai' => 'Whatsapp', 'Label' => 'WhatsApp']])
        ->where('KanalAktif.Email', false));
    $this->post('/kelola/pelanggan/kampanye', IsianKampanye())->assertSessionHasNoErrors();
    $kampanye = KampanyePesan::query()->sole();
    expect($kampanye->Status)->toBe(StatusKampanye::Draf);

    $this->get("/kelola/pelanggan/kampanye/{$kampanye->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Pelanggan/Kampanye/Detail')
        ->where('Kampanye.Status', 'Draf')
        ->where('Kampanye.Segmen', ['Semua pelanggan yang setuju menerima promosi'])
        ->where('Kampanye.Contoh', fn (string $c): bool => str_starts_with($c, 'Halo Budi, minggu ini kopi gula aren diskon 20% di Kopi Senja Kampanye.')));

    $this->post("/kelola/pelanggan/kampanye/{$kampanye->Uuid}/jalankan")->assertSessionHasNoErrors()
        ->assertSessionHas('Kilat', 'Kampanye mulai dikirim ke 2 pelanggan secara bertahap.');

    $pesan = PesanKampanyeTerkirim();
    $keAni = $pesan->first(fn (PermintaanHttp $r): bool => $r['target'] === '6281200000001');
    expect($pesan)->toHaveCount(2)
        ->and($pesan->pluck('target')->sort()->values()->all())->toBe(['6281200000001', '6281200000002'])
        ->and($keAni)->not->toBeNull()
        ->and((string) $keAni['message'])->toContain('Halo Ani Lestari, minggu ini')
        ->and((string) $keAni['message'])->toContain('Berhenti menerima pesan promosi: '.url('/berhenti-langganan/'))
        ->and((string) $keAni['message'])->toContain('signature=');
    Mail::assertNothingSent();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kampanye->refresh();
    expect($kampanye->Status)->toBe(StatusKampanye::Selesai)
        ->and($kampanye->JumlahPenerima)->toBe(2)
        ->and($kampanye->JumlahTerkirim)->toBe(2)
        ->and(PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Terkirim->value)->count())->toBe(2)
        ->and(DB::table('PenerimaKampanye')->pluck('Tujuan')->implode(' '))->not->toContain('6281200000001')
        ->and(LogAudit::query()->where('Peristiwa', 'kampanye-pesan.jalankan')->count())->toBe(1);

    // Daftar penerima di rincian tidak pernah membawa email/nomor.
    $json = $this->getJson("/kelola/pelanggan/kampanye/{$kampanye->Uuid}")->assertOk()->json();
    expect(json_encode($json))->not->toContain('contoh.id')->not->toContain('62812')
        ->and(collect($json['Data'])->pluck('NamaPelanggan')->sort()->values()->all())->toBe(['Ani Lestari', 'Budi Santoso']);
    $this->getJson('/kelola/pelanggan/kampanye')->assertJsonPath('Data.0.Status', 'Selesai');
});

it('tautan berhenti berlangganan: GET hanya konfirmasi, POST mencabut persetujuan. Tautan diubah = 404', function (): void {
    $k = SiapkanKampanye($this);
    $tautan = TautanBerhentiLangganan::Buat($k['Tenant']->Id, $k['Budi']->Uuid);
    app(KonteksTenant::class)->Kosongkan();

    $this->get($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/BerhentiLangganan')
        ->where('Ditemukan', true)
        ->where('NamaToko', $k['Outlet']->Nama)
        ->where('SudahBerhenti', false));
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Pelanggan::query()->whereKey($k['Budi']->Id)->value('SetujuPemasaran'))->toBeTruthy();
    app(KonteksTenant::class)->Kosongkan();

    $this->get(str_replace($k['Budi']->Uuid, $k['Ani']->Uuid, $tautan))->assertNotFound();
    $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=00', $tautan))->assertNotFound();
    $this->post(preg_replace('/signature=[0-9a-f]+/', 'signature=00', $tautan))->assertNotFound();

    $this->post($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('SudahBerhenti', true));
    $this->post($tautan)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Pelanggan::query()->whereKey($k['Budi']->Id)->value('SetujuPemasaran'))->toBeFalsy()
        ->and(LogAudit::query()->where('Peristiwa', 'pelanggan.berhenti-pemasaran')->count())->toBe(1);
    $this->postJson('/kelola/pelanggan/kampanye/pratinjau', ['Kanal' => 'Whatsapp', 'Segmen' => []])->assertJson(['JumlahPenerima' => 1]);
});

it('jadwal dijalankan perintah terjadwal saat waktunya tiba; tanpa penerima ditolak; batal; izin & isolasi tenant', function (): void {
    $k = SiapkanKampanye($this);

    // Segmen tanpa anggota → tidak bisa dikirim.
    $this->post('/kelola/pelanggan/kampanye', IsianKampanye(['Nama' => 'Untuk juara', 'Segmen' => ['Rfm' => ['Juara']]]));
    $juara = KampanyePesan::query()->where('Nama', 'Untuk juara')->sole();
    $this->post("/kelola/pelanggan/kampanye/{$juara->Uuid}/jalankan")
        ->assertSessionHasErrors(['Umum' => 'Tidak ada pelanggan yang cocok, setuju menerima promosi, dan punya kontak untuk kanal ini.']);
    $this->post("/kelola/pelanggan/kampanye/{$juara->Uuid}/batal")->assertSessionHasNoErrors();
    expect($juara->refresh()->Status)->toBe(StatusKampanye::Dibatalkan);

    $this->post('/kelola/pelanggan/kampanye', IsianKampanye(['Segmen' => ['Rfm' => ['BelumBelanja']]]));
    $kampanye = KampanyePesan::query()->where('Status', StatusKampanye::Draf->value)->sole();
    $this->post("/kelola/pelanggan/kampanye/{$kampanye->Uuid}/jalankan", ['DijadwalkanPada' => '2026-10-07T07:00:00Z'])
        ->assertSessionHas('Kilat', 'Kampanye dijadwalkan.');
    expect($kampanye->refresh()->Status)->toBe(StatusKampanye::Dijadwalkan);
    // Yang sudah dijadwalkan tidak bisa diubah.
    $this->put("/kelola/pelanggan/kampanye/{$kampanye->Uuid}", IsianKampanye())->assertSessionHasErrors('Umum');

    Artisan::call('pelanggan:jalankan-kampanye-terjadwal');
    expect(PesanKampanyeTerkirim())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 07:01:00', 'UTC'));
    Artisan::call('pelanggan:jalankan-kampanye-terjadwal');
    expect(PesanKampanyeTerkirim())->toHaveCount(1)
        ->and(PesanKampanyeTerkirim()[0]['target'])->toBe('6281200000002');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($kampanye->refresh()->Status)->toBe(StatusKampanye::Selesai);
    $this->post("/kelola/pelanggan/kampanye/{$kampanye->Uuid}/batal")->assertSessionHasErrors('Umum');

    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get('/kelola/pelanggan/kampanye')->assertForbidden();

    $b = SiapkanKampanye($this, 'Kopi Pagi Lain');
    app(KonteksTenant::class)->Kosongkan();
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->get("/kelola/pelanggan/kampanye/{$kampanye->Uuid}")->assertNotFound();
});

it('D-33: kampanye email lama tidak dikirim lagi — yang terjadwal dibatalkan penjadwal, draf ditolak saat dijalankan', function (): void {
    $k = SiapkanKampanye($this);
    $this->post('/kelola/pelanggan/kampanye', IsianKampanye(['Nama' => 'Email lama terjadwal']))->assertSessionHasNoErrors();
    $this->post('/kelola/pelanggan/kampanye', IsianKampanye(['Nama' => 'Email lama draf']))->assertSessionHasNoErrors();
    // Data dari sebelum D-33: kampanye berkanal Email.
    DB::table('KampanyePesan')->where('Nama', 'Email lama terjadwal')->update(['Kanal' => 'Email', 'Judul' => 'Promo lama', 'Status' => StatusKampanye::Dijadwalkan->value, 'DijadwalkanPada' => now()->subMinute()]);
    DB::table('KampanyePesan')->where('Nama', 'Email lama draf')->update(['Kanal' => 'Email', 'Judul' => 'Promo lama']);

    Artisan::call('pelanggan:jalankan-kampanye-terjadwal');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KampanyePesan::query()->where('Nama', 'Email lama terjadwal')->sole()->Status)->toBe(StatusKampanye::Dibatalkan);

    $draf = KampanyePesan::query()->where('Nama', 'Email lama draf')->sole();
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->post("/kelola/pelanggan/kampanye/{$draf->Uuid}/jalankan")
        ->assertSessionHasErrors(['Kanal' => 'Kampanye lewat email tidak tersedia lagi. Ubah ke WhatsApp.']);
    expect($draf->refresh()->Status)->toBe(StatusKampanye::Draf)
        ->and(PesanKampanyeTerkirim())->toHaveCount(0);
    Mail::assertNothingSent();
});
