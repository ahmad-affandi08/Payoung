<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pemenuhan\Enum\StatusLaundry;
use App\Domain\Pemenuhan\Model\TiketLaundry;
use App\Domain\Pemenuhan\Tugas\KirimNotifikasiLaundrySiapTugas;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as FabrikHttp;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Laundry (§9.9, SLS-09, F-10): tiket laundry dibuat bersama `Penjualan.Buat` (offline-first, idempoten), status proses
 * maju dari kasir/back-office, WhatsApp saat siap, lacak publik lewat `/s/{kode}` (walau struk digital mati), void
 * membatalkan tiket, Kotak Tindakan untuk cucian terlambat, izin `laundry.kelola`, isolasi outlet & tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8001']])]);
});

/**
 * Penjualan "Cuci Kering Setrika" 3,5 kg dengan blok Laundry.
 *
 * @param  array<string, mixed>  $laundry
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function SiapkanCucian(TestCase $tes, array $laundry = [], string $nama = 'Laundry Wangi Bersih Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['Laundry' => $laundry + [
        'JenisLayanan' => 'Express',
        'Berat' => '3.50',
        'Item' => [['Nama' => 'Bed cover king', 'Jumlah' => 1]],
        'Parfum' => 'Lavender',
        'Catatan' => 'Kemeja putih dipisah',
        'EstimasiSelesaiPada' => '2026-10-14T02:25:00Z',
        'NamaPelanggan' => 'Ratna Sari Dewi',
        'NoHp' => '0812-3456-7890',
    ]]);

    return [$k, $item];
}

it('Penjualan.Buat dengan blok Laundry membuat tiket Diterima (Uuid = penjualan), idempoten; data-awal membawa pengaturan', function (): void {
    [$k, $item] = SiapkanCucian($this);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
        ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $t = TiketLaundry::query()->sole();
    expect($t->Uuid)->toBe($p->Uuid)
        ->and($t->Nomor)->toBe($p->Nomor)
        ->and($t->Status)->toBe(StatusLaundry::Diterima)
        ->and($t->Berat)->toBe('3.50')
        ->and($t->Item)->toBe([['Nama' => 'Bed cover king', 'Jumlah' => 1]])
        ->and($t->NoHp)->toBe('6281234567890')
        ->and($t->EstimasiSelesaiPada->toIso8601ZuluString())->toBe('2026-10-14T02:25:00Z')
        ->and($p->PerluTinjauan)->toBeFalse()
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'TiketLaundry')->pluck('StatusKe')->all())->toBe(['Diterima']);

    $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()
        ->assertJsonPath('Laundry.Aktif', false)
        ->assertJsonPath('Laundry.JamExpress', 24)
        ->assertJsonPath('Laundry.AwalanLacak', url('/s/'.base_convert((string) $k['Tenant']->Id, 10, 36).'.'));
});

it('estimasi tidak wajar dihitung dari pengaturan; tanpa nama/berat/item = tinjauan Laundry', function (): void {
    [$k, $item] = SiapkanCucian($this, ['EstimasiSelesaiPada' => '2020-01-01T00:00:00Z', 'NamaPelanggan' => null, 'Berat' => null, 'Item' => [], 'JenisLayanan' => 'Reguler']);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Penjualan::query()->sole();
    $t = TiketLaundry::query()->sole();
    expect($t->EstimasiSelesaiPada->equalTo($p->DibuatOfflinePada->copy()->addHours(48)))->toBeTrue()
        ->and($t->NamaPelanggan)->toBe('Tanpa nama')
        ->and($p->PerluTinjauan)->toBeTrue()
        ->and($p->AlasanTinjauan)->toContain('Laundry: tiket tanpa nama pelanggan; tiket tanpa berat maupun item');
});

it('kasir: cari & ubah status maju, Siap mengirim WhatsApp sekali, Diambil tercatat; mundur ditolak; outlet lain 404', function (): void {
    [$k, $item] = SiapkanCucian($this);
    BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
    $status = fn (string $s) => $this->withToken($k['Token'])->postJson("/api/pos/v1/laundry/{$item['Uuid']}/status", ['Status' => $s, 'UuidPengguna' => $k['Kasir']->Uuid]);

    $this->withToken($k['Token'])->getJson('/api/pos/v1/laundry')->assertOk()->assertJsonCount(0, 'Tiket');
    $this->withToken($k['Token'])->getJson('/api/pos/v1/laundry?kata=ratna')->assertOk()->assertJsonPath('Tiket.0.Uuid', $item['Uuid']);
    $this->withToken($k['Token'])->getJson('/api/pos/v1/laundry?kata=081234567890')->assertOk()->assertJsonCount(1, 'Tiket');

    $status('Dicuci')->assertOk()->assertJsonPath('Tiket.Status', 'Dicuci')->assertJsonPath('Tiket.StatusBerikutnya', ['Dikeringkan', 'Disetrika', 'Siap']);
    $status('Diambil')->assertStatus(409);
    $status('Siap')->assertOk()->assertJsonPath('Tiket.Status', 'Siap');
    $status('Siap')->assertOk();
    $status('Dicuci')->assertStatus(409);

    Http::assertSentCount(1);
    Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890'
        && str_contains((string) $r['message'], 'Halo Ratna Sari Dewi, cucian')
        && str_contains((string) $r['message'], url('/s/')));
    $this->withToken($k['Token'])->getJson('/api/pos/v1/laundry')->assertOk()->assertJsonCount(1, 'Tiket')->assertJsonPath('Tiket.0.NotifikasiTerkirim', true);

    $status('Diambil')->assertOk()->assertJsonPath('Tiket.Status', 'Diambil');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $t = TiketLaundry::query()->sole();
    expect($t->DiambilOleh)->toBe($k['Kasir']->Id)
        ->and($t->DiambilPada)->not->toBeNull()
        ->and(LogAudit::query()->where('Peristiwa', 'laundry.status')->count())->toBe(3);

    app(KonteksTenant::class)->Kosongkan();
    $this->withToken($k['Token'])->postJson('/api/pos/v1/laundry/'.Str::ulid().'/status', ['Status' => 'Siap', 'UuidPengguna' => $k['Kasir']->Uuid])->assertNotFound();
    $this->withToken($k['Token'])->postJson("/api/pos/v1/laundry/{$item['Uuid']}/status", ['Status' => 'Siap', 'UuidPengguna' => (string) Str::ulid()])->assertForbidden();
    $this->withToken($k['Token'])->postJson("/api/pos/v1/laundry/{$item['Uuid']}/status", ['Status' => 'Dibatalkan', 'UuidPengguna' => $k['Kasir']->Uuid])->assertUnprocessable();
});

it('lacak publik /s/{kode} menampilkan status cucian walau struk digital mati; void membatalkan tiket', function (): void {
    [$k, $item] = SiapkanCucian($this);
    BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
    $kode = KodeStrukDigital::Buat($k['Tenant']->Id, $item['Uuid']);

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->put('/kelola/kasir/struk', [
        'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
        'TampilkanKasir' => true, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true, 'TampilkanStrukDigital' => false,
        'NamaDicetak' => null, 'TeksKepala' => [], 'CatatanKaki' => null, 'TeksPenutup' => null,
    ])->assertRedirect();
    app(KonteksTenant::class)->Kosongkan();

    $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/StrukDigital')
        ->where('Struk.Laundry.Status', 'Diterima')
        ->where('Struk.Laundry.JenisLayanan', 'Express')
        ->where('Struk.Laundry.Tahap.0.Selesai', true)
        ->where('Struk.Laundry.Tahap.1.Selesai', false)
        ->missing('Struk.Laundry.NoHp'));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Penjualan::query()->sole();
    BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TiketLaundry::query()->sole()->Status)->toBe(StatusLaundry::Dibatalkan);
    app(KonteksTenant::class)->Kosongkan();
    $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.Laundry.Status', 'Dibatalkan'));
});

it('back-office: daftar & filter terlambat, ubah status, pengaturan diaudit & divalidasi, Kotak Tindakan; Kasir 403; tenant lain 404', function (): void {
    [$k, $item] = SiapkanCucian($this);
    BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $nomor = Penjualan::query()->sole()->Nomor;
    $masuk = fn () => BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $masuk()->getJson('/kelola/laundry')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Nomor', $nomor);
    $masuk()->getJson('/kelola/laundry?saring[Terlambat]=BelumSiap')->assertOk()->assertJsonPath('Meta.Total', 0);

    // Lewat estimasi (besok 09.25 WIB) tetapi belum siap: saring & Kotak Tindakan Penting.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'Asia/Jakarta'));
    $masuk()->getJson('/kelola/laundry?saring[Terlambat]=BelumSiap')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.LewatEstimasi', true);
    $masuk()->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Butir', fn ($butir): bool => collect($butir)->contains(fn ($b): bool => $b['Kunci'] === 'laundry.lewat-estimasi' && $b['Jumlah'] === 1)));

    $masuk()->post("/kelola/laundry/{$item['Uuid']}/status", ['Status' => 'Siap'])->assertSessionHas('Kilat', "Cucian {$nomor}: Siap diambil.");
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'Asia/Jakarta'));
    $masuk()->getJson('/kelola/laundry?saring[Terlambat]=BelumDiambil')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.TerlambatDiambil', true);

    $masuk()->put('/kelola/laundry/pengaturan', ['Aktif' => true, 'JamReguler' => 48, 'JamExpress' => 72, 'Parfum' => [], 'NotifikasiSiap' => true, 'HariBelumDiambil' => 7])->assertSessionHasErrors('JamExpress');
    $masuk()->put('/kelola/laundry/pengaturan', ['Aktif' => true, 'JamReguler' => 72, 'JamExpress' => 12, 'Parfum' => ['Lavender', ' lavender ', 'Sakura'], 'NotifikasiSiap' => false, 'HariBelumDiambil' => 14])
        ->assertSessionHas('Kilat', 'Pengaturan laundry disimpan.');
    $masuk()->getJson('/kelola/laundry?saring[Terlambat]=BelumDiambil')->assertOk()->assertJsonPath('Meta.Total', 0);
    $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertJsonPath('Laundry.Aktif', true)->assertJsonPath('Laundry.Parfum', ['Lavender', 'Sakura']);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(LogAudit::query()->where('Peristiwa', 'laundry.pengaturan')->count())->toBe(1);

    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get('/kelola/laundry')->assertForbidden();

    $b = BantuanPenjualan::Siapkan($this, 'Laundry Kilat Klaten');
    app(KonteksTenant::class)->Kosongkan();
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->post("/kelola/laundry/{$item['Uuid']}/status", ['Status' => 'Diambil'])->assertNotFound();
});

it('audit F-17: WhatsApp siap paling banyak sekali — pekerja terhenti setelah kirim tidak mengulang; penyedia gagal boleh dicoba lagi', function (): void {
    [$k, $item] = SiapkanCucian($this);
    BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $t = TiketLaundry::query()->sole();
    TiketLaundry::query()->whereKey($t->Id)->update(['Status' => StatusLaundry::Siap->value]);
    $jalankan = fn () => app()->call([new KirimNotifikasiLaundrySiapTugas($k['Tenant']->Id, $t->Id), 'handle']);

    // Penyedia menolak: klaim dilepas, percobaan berikutnya mengirim.
    Http::swap(new FabrikHttp);
    Http::fake(['api.fonnte.com/send' => Http::sequence()->push(['status' => false, 'reason' => 'quota'])->push(['status' => true, 'id' => ['8002']])]);
    $jalankan();
    expect($t->refresh()->NotifikasiSiapPada)->toBeNull()->and($t->NotifikasiSiapDiprosesPada)->toBeNull();
    $jalankan();
    expect($t->refresh()->NotifikasiSiapPada)->not->toBeNull();
    Http::assertSentCount(2);

    // Pekerja lain terhenti setelah penyedia menerima (klaim ada, belum tercatat terkirim): percobaan ulang tidak mengirim.
    TiketLaundry::query()->whereKey($t->Id)->update(['NotifikasiSiapPada' => null, 'NotifikasiSiapDiprosesPada' => now()]);
    $jalankan();
    Http::assertSentCount(2);
});
