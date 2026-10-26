<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pemenuhan\Enum\StatusReservasi;
use App\Domain\Pemenuhan\Model\PengaturanReservasi;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-07 mode service (POS-04, SLS-07, §9.8): reservasi layanan per staf. Slot dari jadwal kerja staf & durasi layanan,
 * staf tidak bisa dipesan dua kali pada jam yang sama, transisi status tercatat, pindah jadwal, reservasi online publik
 * (konfirmasi otomatis/menunggu, batal oleh pelanggan, batas per nomor), pengingat WhatsApp H-1, izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Senin 12 Oktober 2026 pukul 08.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00', 'Asia/Jakarta'));
});

/**
 * Salon: layanan potong rambut 45 menit (tampil online, Rp 75.000), staf Maya & Dewi bekerja 13 Oktober 09.00–12.00.
 *
 * @return array<string, mixed>
 */
function SiapkanSalon(TestCase $tes, string $nama = 'Salon Cantik Ayu Solo'): array
{
    $t = BantuanPersediaan::SiapkanTenant($nama);
    $layanan = BantuanKatalog::BuatProduk(['Nama' => 'Potong Rambut & Styling Premium', 'Jenis' => JenisProduk::Jasa, 'DurasiMenit' => 45, 'TampilOnline' => true], '75000.00');
    $maya = Karyawan::query()->create(['Nama' => 'Maya Senior Stylist']);
    $dewi = Karyawan::query()->create(['Nama' => 'Dewi Junior Stylist']);

    foreach ([$maya, $dewi] as $k) {
        JadwalKerja::query()->create(['IdKaryawan' => $k->Id, 'IdOutlet' => $t['Outlet']->Id, 'Tanggal' => '2026-10-13', 'JamMulai' => '09:00', 'JamSelesai' => '12:00']);
    }

    BantuanOrganisasi::Masuk($tes, $t['Pemilik'], $t['Tenant']->Id);

    return $t + ['Layanan' => $layanan, 'Maya' => $maya, 'Dewi' => $dewi];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianReservasi(array $k, array $ubah = []): array
{
    return array_replace([
        'Outlet' => $k['Outlet']->Uuid,
        'UuidLayanan' => $k['Layanan']->Uuid,
        'Tanggal' => '2026-10-13',
        'Jam' => '10:00',
        'UuidStaf' => $k['Maya']->Uuid,
        'NamaPelanggan' => 'Rina Wulandari',
        'NoHp' => '0812-3456-7890',
        'Catatan' => 'Minta dirapikan poni',
    ], $ubah);
}

/** @param array<string, mixed> $k */
function SlotReservasiUji(TestCase $tes, array $k, ?string $staf = null): array
{
    return $tes->getJson('/kelola/reservasi/slot?'.http_build_query(['Outlet' => $k['Outlet']->Uuid, 'Layanan' => $k['Layanan']->Uuid, 'Tanggal' => '2026-10-13', 'Staf' => $staf ?? '']))
        ->assertOk()->json('Slot');
}

it('slot dari jadwal staf & durasi. Reservasi mengunci jam staf. Bentrok ditolak; "siapa saja" memilih staf kosong', function (): void {
    $k = SiapkanSalon($this);

    $slot = SlotReservasiUji($this, $k);
    expect(array_column($slot, 'Jam'))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00'])
        ->and(array_column($slot[0]['Staf'], 'Nama'))->toBe(['Dewi Junior Stylist', 'Maya Senior Stylist']);

    $this->post('/kelola/reservasi', IsianReservasi($k))->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Reservasi RS/2026/10/0001 dicatat.');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = Reservasi::query()->sole();
    expect($r->Status)->toBe(StatusReservasi::Dikonfirmasi)
        ->and($r->IdKaryawan)->toBe($k['Maya']->Id)
        ->and($r->NoHp)->toBe('6281234567890')
        ->and($r->MulaiPada->toIso8601ZuluString())->toBe('2026-10-13T03:00:00Z')
        ->and($r->SelesaiPada->toIso8601ZuluString())->toBe('2026-10-13T03:45:00Z')
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Reservasi')->count())->toBe(1);

    // Maya: 09:30, 10:00, 10:30 bentrok dengan 10:00–10:45.
    expect(array_column(SlotReservasiUji($this, $k, $k['Maya']->Uuid), 'Jam'))->toBe(['09:00', '11:00']);

    $this->post('/kelola/reservasi', IsianReservasi($k, ['NamaPelanggan' => 'Sari']))->assertSessionHasErrors(['Jam' => 'Jam ini sudah tidak tersedia. Pilih jam lain.']);
    $this->post('/kelola/reservasi', IsianReservasi($k, ['UuidStaf' => '', 'NamaPelanggan' => 'Sari']))->assertSessionHasNoErrors();
    $this->post('/kelola/reservasi', IsianReservasi($k, ['UuidStaf' => '', 'NamaPelanggan' => 'Tini']))->assertSessionHasErrors('Jam');
    // Di luar jadwal staf.
    $this->post('/kelola/reservasi', IsianReservasi($k, ['Jam' => '11:30', 'UuidStaf' => '']))->assertSessionHasErrors('Jam');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Reservasi::query()->where('IdKaryawan', $k['Dewi']->Id)->sole()->NamaPelanggan)->toBe('Sari');

    $this->get('/kelola/reservasi')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Reservasi/Daftar')
        ->where('OpsiLayanan.0.DurasiMenit', 45)
        ->where('OpsiLayanan.0.Harga', '75000.00')
        ->where('Reservasi.Data', function ($data): bool {
            $baris = collect($data)->firstWhere('Nomor', 'RS/2026/10/0001');

            return $baris['Staf']['Nama'] === 'Maya Senior Stylist'
                && $baris['StatusBerikutnya'] === ['Hadir', 'Batal', 'TidakDatang']
                && $baris['NoHp'] === '0812-3456-7890';
        }));
});

it('status: datang → selesai. Batal wajib alasan & membebaskan slot. Tidak datang hanya setelah lewat jam. Pindah jadwal', function (): void {
    $k = SiapkanSalon($this);
    $this->post('/kelola/reservasi', IsianReservasi($k))->assertSessionHasNoErrors();
    $this->post('/kelola/reservasi', IsianReservasi($k, ['Jam' => '09:00', 'NamaPelanggan' => 'Budi']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    [$satu, $dua] = Reservasi::query()->orderBy('Id')->get()->all();

    $this->post("/kelola/reservasi/{$satu->Uuid}/status", ['Status' => 'TidakDatang'])->assertSessionHasErrors('Status');
    $this->post("/kelola/reservasi/{$satu->Uuid}/status", ['Status' => 'Batal'])->assertSessionHasErrors(['Alasan' => 'Tulis alasan pembatalan.']);
    $this->post("/kelola/reservasi/{$satu->Uuid}/status", ['Status' => 'Batal', 'Alasan' => 'Pelanggan sakit'])->assertSessionHasNoErrors();
    expect(array_column(SlotReservasiUji($this, $k, $k['Maya']->Uuid), 'Jam'))->toContain('10:00');

    // Pindah jadwal Budi ke 11:00 dengan Dewi.
    $this->post("/kelola/reservasi/{$dua->Uuid}/jadwal-ulang", ['Tanggal' => '2026-10-13', 'Jam' => '11:00', 'UuidStaf' => $k['Dewi']->Uuid])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($dua->refresh()->IdKaryawan)->toBe($k['Dewi']->Id)->and($dua->MulaiPada->toIso8601ZuluString())->toBe('2026-10-13T04:00:00Z');

    $this->travelTo(CarbonImmutable::parse('2026-10-13 11:05', 'Asia/Jakarta'));
    $this->post("/kelola/reservasi/{$dua->Uuid}/status", ['Status' => 'Hadir'])->assertSessionHasNoErrors();
    $this->post("/kelola/reservasi/{$dua->Uuid}/status", ['Status' => 'Selesai'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($dua->refresh()->Status)->toBe(StatusReservasi::Selesai)->and($dua->HadirPada)->not->toBeNull()
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Reservasi')->where('IdDokumen', $dua->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Dikonfirmasi', 'Hadir', 'Selesai']);
});

it('reservasi online: tutup = 404. Konfirmasi otomatis/menunggu. Persetujuan wajib. Batas per nomor. Batal lewat kode; Kotak Tindakan', function (): void {
    $k = SiapkanSalon($this);
    $slug = $k['Tenant']->Slug;
    app(KonteksTenant::class)->Kosongkan();

    $this->get("/{$slug}/reservasi")->assertNotFound();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanReservasi::query()->create(['OnlineAktif' => true, 'KonfirmasiOtomatis' => false, 'MinimalMenitSebelum' => 60]);
    app(KonteksTenant::class)->Kosongkan();

    $this->get("/{$slug}/reservasi")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/Reservasi')
        ->where('Layanan.0.Nama', 'Potong Rambut & Styling Premium')
        ->where('HariIni', '2026-10-12'));
    $this->getJson("/{$slug}/reservasi/slot?".http_build_query(['Outlet' => $k['Outlet']->Uuid, 'Layanan' => $k['Layanan']->Uuid, 'Tanggal' => '2026-10-13']))
        ->assertOk()->assertJsonCount(5, 'Slot');

    $isian = ['Outlet' => $k['Outlet']->Uuid, 'UuidLayanan' => $k['Layanan']->Uuid, 'Tanggal' => '2026-10-13', 'Jam' => '09:00', 'NamaPelanggan' => 'Ayu Lestari', 'NoHp' => '081299887766'];
    $this->post("/{$slug}/reservasi", $isian)->assertSessionHasErrors('Setuju');
    $respons = $this->post("/{$slug}/reservasi", $isian + ['Setuju' => '1'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = Reservasi::query()->sole();
    expect($r->Status)->toBe(StatusReservasi::Menunggu)->and($r->Sumber->value)->toBe('Online')->and($r->DibuatOleh)->toBeNull();
    $respons->assertRedirect("/{$slug}/reservasi/{$r->KodeAkses}");

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get('/kelola/tindakan')->assertInertia(function (AssertableInertia $h) {
        $butir = collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'reservasi.menunggu-konfirmasi');
        expect($butir['Jumlah'] ?? null)->toBe(1);

        return $h;
    });

    // Batas 3 reservasi aktif per nomor.
    app(KonteksTenant::class)->Kosongkan();
    $this->post("/{$slug}/reservasi", ['Jam' => '09:30'] + $isian + ['Setuju' => '1'])->assertSessionHasNoErrors();
    $this->post("/{$slug}/reservasi", ['Jam' => '10:30'] + $isian + ['Setuju' => '1'])->assertSessionHasNoErrors();
    $this->post("/{$slug}/reservasi", ['Jam' => '11:00'] + $isian + ['Setuju' => '1'])->assertSessionHasErrors('NoHp');

    // Pelanggan membuka status & membatalkan.
    $this->get("/{$slug}/reservasi/{$r->KodeAkses}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/StatusReservasi')
        ->where('Reservasi.Status', 'Menunggu')
        ->where('Reservasi.BolehBatal', true));
    $this->post("/{$slug}/reservasi/{$r->KodeAkses}/batal")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($r->refresh()->Status)->toBe(StatusReservasi::Batal)->and($r->AlasanBatal)->toBe('Dibatalkan pelanggan');
    app(KonteksTenant::class)->Kosongkan();
    $this->get("/{$slug}/reservasi/ZZZZZZZZZZZZ")->assertNotFound();
});

it('pengingat WhatsApp H-1 sekali. Izin: supervisor boleh, tanpa izin 403. Reservasi tenant lain 404', function (): void {
    $k = SiapkanSalon($this);
    $this->post('/kelola/reservasi', IsianReservasi($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = Reservasi::query()->sole();

    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8001']])]);

    // 12 Oktober 08.00: reservasi 26 jam lagi → dalam jendela 20–28 jam.
    expect(Artisan::call('reservasi:kirim-pengingat', ['--tenant' => [$k['Tenant']->Id]]))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($p) => str_contains((string) $p['message'], 'Potong Rambut & Styling Premium') && str_contains((string) $p['message'], '/reservasi/'.$r->KodeAkses));
    Artisan::call('reservasi:kirim-pengingat', ['--tenant' => [$k['Tenant']->Id]]);
    Http::assertSentCount(1);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($r->refresh()->PengingatTerkirimPada)->not->toBeNull();

    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id)->get('/kelola/reservasi')->assertOk();
    $akuntan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan);
    BantuanOrganisasi::Masuk($this, $akuntan, $k['Tenant']->Id)->get('/kelola/reservasi')->assertForbidden();

    $lain = BantuanPersediaan::SiapkanTenant('Barbershop Gagah Klaten');
    app(KonteksTenant::class)->Kosongkan();
    BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id)->post("/kelola/reservasi/{$r->Uuid}/status", ['Status' => 'Hadir'])->assertNotFound();
    expect(Tenant::query()->count())->toBeGreaterThanOrEqual(2);
});

it('audit F-19: batas hari ke depan eksklusif — tepat tengah malam setelah hari terakhir ditolak TerlaluJauh', function (): void {
    $k = SiapkanSalon($this);
    $slug = $k['Tenant']->Slug;
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanReservasi::query()->create(['OnlineAktif' => true, 'KonfirmasiOtomatis' => true, 'MinimalMenitSebelum' => 60, 'BatasHariKeDepan' => 1]);
    app(KonteksTenant::class)->Kosongkan();
    $isian = ['Outlet' => $k['Outlet']->Uuid, 'UuidLayanan' => $k['Layanan']->Uuid, 'NamaPelanggan' => 'Ayu Lestari', 'NoHp' => '081299887766', 'Setuju' => '1'];

    // Hari ini 12 Oktober; batas 1 hari = sampai 13 Oktober 23.59. 14 Oktober 00.00 sudah terlalu jauh.
    $this->post("/{$slug}/reservasi", ['Tanggal' => '2026-10-14', 'Jam' => '00:00'] + $isian)
        ->assertSessionHasErrors(['Tanggal' => 'Reservasi paling jauh 1 hari ke depan.']);
    $this->post("/{$slug}/reservasi", ['Tanggal' => '2026-10-13', 'Jam' => '09:00'] + $isian)->assertSessionHasNoErrors();
});
