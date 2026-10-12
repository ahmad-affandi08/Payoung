<?php

declare(strict_types=1);

use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Integrasi\ApiPublik\Aksi\KirimKirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Enum\StatusKirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Layanan\PenjagaAlamatWebhook;
use App\Domain\Integrasi\ApiPublik\Model\KirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Model\WebhookTenant;
use App\Domain\Integrasi\ApiPublik\Penangan\AntrekanWebhookIntegrasi;
use App\Domain\Integrasi\ApiPublik\Penangan\AntrekanWebhookPenjualan;
use App\Domain\Integrasi\ApiPublik\Tugas\AntrekanWebhookIntegrasiTugas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Peristiwa\PenjualanDiterima;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * X7 bagian 2 (PRD §16.4 Webhook Keluar): Owner mendaftarkan alamat HTTPS publik; penjualan selesai, void, dan retur
 * dikirim sebagai POST JSON bertanda tangan HMAC-SHA256 (`X-Tanda-Tangan` atas `{X-Waktu-Kirim}.{badan}`) dengan
 * `IdPeristiwa` unik. Gagal dicoba ulang 1m, 5m, 30m, 2j, 12j lewat perintah terjadwal lalu Gagal; log terlihat dan
 * bisa dikirim ulang. Alamat privat/loopback/metadata ditolak (SSRF), tenant terisolasi.
 */

/** @var list<string> IP hasil DNS palsu; diubah test untuk mensimulasikan DNS rebinding. */
$GLOBALS['IpDnsWebhookUji'] = ['93.184.216.34'];

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $GLOBALS['IpDnsWebhookUji'] = ['93.184.216.34'];
    $this->app->instance(PenjagaAlamatWebhook::class, new PenjagaAlamatWebhook(fn (string $host): array => $GLOBALS['IpDnsWebhookUji']));
});

function AktifkanFiturWebhook(Tenant $tenant): void
{
    OverrideTenant::query()->create([
        'IdTenant' => $tenant->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => 'api.publik',
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji webhook sistem gudang',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($tenant->Id);
}

/**
 * @param  array<string, mixed>  $k
 * @param  list<string>  $peristiwa
 * @return array{Webhook: WebhookTenant, Rahasia: string}
 */
function DaftarkanWebhookUji(mixed $tes, array $k, array $peristiwa, string $url = 'https://gudang.contoh.co.id/payoung'): array
{
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id)
        ->post('/kelola/pengaturan/webhook', ['Nama' => 'Sistem gudang', 'Url' => $url, 'Peristiwa' => $peristiwa])
        ->assertRedirect('/kelola/pengaturan/webhook')
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('Kilat');
    $rahasia = (string) (session('RahasiaWebhookBaru')['Rahasia'] ?? '');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($rahasia)->toHaveLength(48);

    return ['Webhook' => WebhookTenant::query()->latest('Id')->firstOrFail(), 'Rahasia' => $rahasia];
}

it('penjualan selesai, void, dan retur terkirim bertanda tangan HMAC dengan IdPeristiwa; rahasia hanya tampil sekali', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('{"diterima":true}', 200)]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    ['Webhook' => $webhook, 'Rahasia' => $rahasia] = DaftarkanWebhookUji($this, $k, ['penjualan.selesai', 'penjualan.divoid', 'penjualan.diretur']);
    expect(WebhookTenant::query()->sole()->getRawOriginal('Rahasia'))->not->toContain($rahasia);

    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '20', 'Harga' => '63500.00']]]);

    $kiriman = KirimanWebhook::query()->where('Peristiwa', 'penjualan.selesai')->sole();
    expect($kiriman->Status)->toBe(StatusKirimanWebhook::Terkirim)
        ->and($kiriman->KodeRespons)->toBe(200)
        ->and($kiriman->TerkirimPada)->not->toBeNull();

    Http::assertSent(function (PermintaanHttp $p) use ($rahasia, $kiriman, $jual, $semen): bool {
        $badan = $p->body();
        $data = json_decode($badan, true);

        return $p->url() === 'https://gudang.contoh.co.id/payoung'
            && $p->hasHeader('X-Id-Peristiwa', $kiriman->Uuid)
            && $p->hasHeader('X-Peristiwa', 'penjualan.selesai')
            && $p->header('X-Tanda-Tangan')[0] === 'sha256='.hash_hmac('sha256', $p->header('X-Waktu-Kirim')[0].'.'.$badan, $rahasia)
            && $data['IdPeristiwa'] === $kiriman->Uuid
            && $data['Data']['Nomor'] === $jual->Nomor
            && $data['Data']['Baris'][0]['UuidProduk'] === $semen->Uuid
            && ! array_key_exists('HppSatuan', $data['Data']['Baris'][0])
            && ! array_key_exists('IdOutlet', $data['Data']);
    });

    $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '2']])]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $retur = KirimanWebhook::query()->where('Peristiwa', 'penjualan.diretur')->sole();
    expect($retur->Status)->toBe(StatusKirimanWebhook::Terkirim)
        ->and($retur->Muatan['Data']['UuidPenjualan'])->toBe($jual->Uuid)
        ->and($retur->Muatan['Data'])->not->toHaveKey('IdOutlet');

    $jual2 = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '1', 'Harga' => '63500.00']]]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual2)]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KirimanWebhook::query()->where('Peristiwa', 'penjualan.divoid')->sole()->Muatan['Data']['Nomor'])->toBe($jual2->Nomor)
        ->and(KirimanWebhook::query()->count())->toBe(4)
        ->and(KirimanWebhook::query()->pluck('IdWebhookTenant')->unique()->all())->toBe([$webhook->Id]);

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get('/kelola/pengaturan/webhook')
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pengaturan/Webhook')
            ->where('RahasiaBaru', null)
            ->where('Webhook.0.Url', 'https://gudang.contoh.co.id/payoung')
            ->missing('Webhook.0.Rahasia')
            ->has('Kiriman', 4)
            ->missing('Kiriman.0.Muatan'));
});

it('gagal dicoba ulang 1m, 5m, 30m, 2j, 12j lewat perintah terjadwal lalu Gagal; kirim ulang memakai IdPeristiwa yang sama', function (): void {
    $pulih = false;
    Http::fake(['gudang.contoh.co.id/*' => function () use (&$pulih) {
        return $pulih ? Http::response('', 204) : Http::response('Layanan sedang pemeliharaan', 503);
    }]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['penjualan.selesai']);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '5', 'Harga' => '63500.00']]]);

    $kiriman = KirimanWebhook::query()->sole();
    $idPeristiwa = $kiriman->Uuid;
    expect($kiriman->Status)->toBe(StatusKirimanWebhook::Menunggu)
        ->and($kiriman->Percobaan)->toBe(1)
        ->and($kiriman->KodeRespons)->toBe(503)
        ->and($kiriman->CuplikanRespons)->toBe('Layanan sedang pemeliharaan')
        ->and($kiriman->BerikutnyaPada?->equalTo(now()->addMinute()))->toBeTrue();

    // Audit kinerja skala besar: penyapu hanya memilih tenant yang punya kiriman jatuh tempo, tanpa baris audit akses
    // lintas tenant (penyapu berjalan tiap menit; hasilnya hanya pengenal tenant).
    $auditSebelum = LogAuditPengelola::query()->count();
    $pengelola = app(KonteksPengelola::class);
    expect($pengelola->IdTenantDenganPekerjaan(KirimanWebhook::class, fn ($q) => $q->where('Status', StatusKirimanWebhook::Menunggu->value)->where('BerikutnyaPada', '<=', now()->addMinutes(2))))->toBe([$k['Tenant']->Id])
        ->and($pengelola->IdTenantDenganPekerjaan(KirimanWebhook::class, fn ($q) => $q->where('Status', StatusKirimanWebhook::Menunggu->value)->where('BerikutnyaPada', '<=', now()->subDay())))->toBe([])
        ->and(LogAuditPengelola::query()->count())->toBe($auditSebelum);

    // Sebelum jatuh tempo, perintah tidak mengirim apa pun.
    $this->artisan('integrasi:kirim-webhook')->assertSuccessful();
    Http::assertSentCount(1);

    foreach (KirimKirimanWebhook::JADWAL_COBA_ULANG_MENIT as $urutan => $menit) {
        $this->travel($menit)->minutes();
        $this->artisan('integrasi:kirim-webhook')->assertSuccessful();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kiriman->refresh();
        expect($kiriman->Percobaan)->toBe($urutan + 2);
    }

    expect($kiriman->Status)->toBe(StatusKirimanWebhook::Gagal)
        ->and($kiriman->BerikutnyaPada)->toBeNull();
    Http::assertSentCount(6);

    // Penerima pulih: kirim ulang dari log memakai muatan & IdPeristiwa yang sama.
    $pulih = true;
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->post("/kelola/pengaturan/webhook/kiriman/{$idPeristiwa}/kirim-ulang")
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kiriman->refresh();
    expect($kiriman->Status)->toBe(StatusKirimanWebhook::Terkirim)
        ->and($kiriman->Uuid)->toBe($idPeristiwa)
        ->and($kiriman->KodeRespons)->toBe(204);
    Http::assertSent(fn (PermintaanHttp $p): bool => $p->hasHeader('X-Id-Peristiwa', $idPeristiwa));

    // Log selesai dibersihkan setelah 30 hari.
    $this->travel(31)->days();
    $this->artisan('integrasi:kirim-webhook')->assertSuccessful();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KirimanWebhook::query()->count())->toBe(0);
});

it('menolak alamat non-HTTPS, privat, loopback, dan metadata awan; DNS yang berubah ke IP privat tidak dikirimi', function (): void {
    Http::fake();
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    $kirim = fn (string $url) => BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->post('/kelola/pengaturan/webhook', ['Nama' => 'Sistem gudang', 'Url' => $url, 'Peristiwa' => ['penjualan.selesai']]);

    $kirim('http://gudang.contoh.co.id/payoung')->assertSessionHasErrors();
    $kirim('https://169.254.169.254/latest/meta-data')->assertSessionHasErrors();
    $kirim('https://127.0.0.1/hook')->assertSessionHasErrors();
    $kirim('https://localhost/hook')->assertSessionHasErrors();
    $kirim('https://admin:rahasia@gudang.contoh.co.id/payoung')->assertSessionHasErrors();
    $GLOBALS['IpDnsWebhookUji'] = ['10.1.2.3'];
    $kirim('https://intranet.contoh.co.id/payoung')->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(WebhookTenant::query()->count())->toBe(0);

    // Lolos saat dibuat, lalu DNS diarahkan ke loopback (rebinding): kiriman dicatat gagal tanpa permintaan HTTP.
    $GLOBALS['IpDnsWebhookUji'] = ['93.184.216.34'];
    DaftarkanWebhookUji($this, $k, ['penjualan.selesai']);
    $GLOBALS['IpDnsWebhookUji'] = ['127.0.0.1'];
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '1', 'Harga' => '63500.00']]]);
    $kiriman = KirimanWebhook::query()->sole();
    expect($kiriman->Status)->toBe(StatusKirimanWebhook::Menunggu)
        ->and($kiriman->Percobaan)->toBe(1)
        ->and($kiriman->CuplikanRespons)->toContain('alamat publik');
    Http::assertNothingSent();
});

it('cek IP publik menolak rentang privat, CGNAT, link-local, dan IPv6 lokal', function (string $ip, bool $publik): void {
    expect(PenjagaAlamatWebhook::CekIpPublik($ip))->toBe($publik);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['10.0.0.1', false],
    ['172.16.5.4', false],
    ['192.168.1.10', false],
    ['127.0.0.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['::1', false],
    ['fd00::1', false],
    ['fe80::1', false],
    ['::ffff:10.0.0.1', false],
]);

it('hanya webhook aktif yang melanggan peristiwa itu, dengan fitur api.publik, dan milik tenant sendiri yang dikirimi', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $a = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    $b = BantuanPenjualan::Siapkan($this, 'Toko Besi Makmur Jaya Klaten');
    AktifkanFiturWebhook($a['Tenant']);
    AktifkanFiturWebhook($b['Tenant']);
    DaftarkanWebhookUji($this, $a, ['penjualan.divoid'], 'https://void.contoh.co.id/a');
    ['Webhook' => $nonaktif] = DaftarkanWebhookUji($this, $a, ['penjualan.selesai'], 'https://nonaktif.contoh.co.id/a');
    BantuanOrganisasi::Masuk($this, $a['Pemilik'], $a['Tenant']->Id)
        ->put("/kelola/pengaturan/webhook/{$nonaktif->Uuid}/status", ['Aktif' => false])->assertSessionHasNoErrors();
    DaftarkanWebhookUji($this, $b, ['penjualan.selesai'], 'https://toko-b.contoh.co.id/b');

    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    $semen = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    BantuanPenjualan::Jual($this, $a, ['Baris' => [['Produk' => $semen, 'Jumlah' => '3', 'Harga' => '63500.00']]]);

    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect(KirimanWebhook::query()->count())->toBe(0);
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(KirimanWebhook::query()->count())->toBe(0);
    Http::assertNothingSent();

    // Tenant B sendiri menjual: hanya webhook B yang dikirimi; tenant A tidak bisa mengirim ulang kiriman B.
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    $besi = BantuanPenjualan::BuatProdukBerstok($b['Gudang'], $b['Pemilik']->Id, 'Besi Beton Polos 10 mm SNI 12 m', '100', '68000', '82000.00');
    BantuanPenjualan::Jual($this, $b, ['Baris' => [['Produk' => $besi, 'Jumlah' => '4', 'Harga' => '82000.00']]]);
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    $kirimanB = KirimanWebhook::query()->sole();
    Http::assertSent(fn (PermintaanHttp $p): bool => $p->url() === 'https://toko-b.contoh.co.id/b');
    BantuanOrganisasi::Masuk($this, $a['Pemilik'], $a['Tenant']->Id)
        ->post("/kelola/pengaturan/webhook/kiriman/{$kirimanB->Uuid}/kirim-ulang")->assertNotFound();

    // Fitur habis: peristiwa berikutnya tidak lagi dikirim.
    OverrideTenant::query()->where('IdTenant', $b['Tenant']->Id)->update(['BerakhirPada' => now()->subMinute()]);
    BantuanPenjualan::Jual($this, $b, ['Baris' => [['Produk' => $besi, 'Jumlah' => '1', 'Harga' => '82000.00']]]);
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(KirimanWebhook::query()->count())->toBe(1);

    // Admin (bukan Owner) tidak boleh mengelola webhook.
    $admin = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Admin);
    BantuanOrganisasi::Masuk($this, $admin, $a['Tenant']->Id)->get('/kelola/pengaturan/webhook')->assertForbidden();
});

it('penangan yang diulang untuk peristiwa yang sama tidak menggandakan kiriman (idempoten)', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['penjualan.selesai']);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '1', 'Harga' => '63500.00']]]);

    app(AntrekanWebhookPenjualan::class)->handle(new PenjualanDiterima($k['Tenant']->Id, $jual->IdOutlet, $jual->TanggalBisnis->toDateString(), $jual->Id));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KirimanWebhook::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

/**
 * Kunci `Data` muatan webhook sama persis dengan properti skema spesifikasi OpenAPI untuk peristiwa itu (turun ke
 * baris pertama `Baris`).
 *
 * @param  array<string, mixed>  $data
 */
function PeriksaDataWebhookSesuaiSpesifikasi(string $peristiwa, array $data): void
{
    $spesifikasi = json_decode((string) file_get_contents(public_path('pengembang/openapi-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $ref = (string) $spesifikasi['webhooks'][$peristiwa]['post']['requestBody']['content']['application/json']['schema']['properties']['Data']['$ref'];
    $skema = $spesifikasi['components']['schemas'][substr($ref, strlen('#/components/schemas/'))];
    expect(array_keys($data))->toEqualCanonicalizing(array_keys($skema['properties']), "Data {$peristiwa}");

    if (isset($skema['properties']['Baris']['items']['properties'])) {
        expect($data['Baris'])->not->toBeEmpty()
            ->and(array_keys($data['Baris'][0]))->toEqualCanonicalizing(array_keys($skema['properties']['Baris']['items']['properties']), "Data {$peristiwa}.Baris[0]");
    }
}

it('produk.diubah dikirim setiap kali produk disimpan (IdPeristiwa berbeda), datanya berbentuk GET /api/v1/produk', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($t['Tenant']);
    DaftarkanWebhookUji($this, $t, ['produk.diubah']);

    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
        'Nama' => 'Semen Gresik Portland Komposit 40 kg',
        'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '63500']], defaultJual: true)],
    ]);
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$form['Uuid']}", [...$form, 'Nama' => 'Semen Gresik Portland Komposit 40 kg (zak baru)'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kiriman = KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->orderBy('Id')->get();
    expect($kiriman)->toHaveCount(2)
        ->and($kiriman->pluck('Uuid')->unique())->toHaveCount(2)
        ->and($kiriman->every(fn (KirimanWebhook $x): bool => $x->Status === StatusKirimanWebhook::Terkirim))->toBeTrue()
        ->and($kiriman[0]->Muatan['Data']['Uuid'])->toBe($form['Uuid'])
        ->and($kiriman[1]->Muatan['Data']['Nama'])->toBe('Semen Gresik Portland Komposit 40 kg (zak baru)')
        ->and($kiriman[1]->Muatan['Data'])->not->toHaveKey('Id');
    PeriksaDataWebhookSesuaiSpesifikasi('produk.diubah', $kiriman[1]->Muatan['Data']);
    Http::assertSentCount(2);
});

it('produk.diubah: sekali per perubahan nyata dari harga, arsip, dan pulihkan; simpan ulang tanpa perubahan tidak mengirim', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($t['Tenant']);
    DaftarkanWebhookUji($this, $t, ['produk.diubah']);
    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
        'Nama' => 'Cat Tembok Avitex 5 kg Putih',
        'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '98000']], defaultJual: true)],
    ]);
    $hitung = function () use ($t): int {
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        return KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->count();
    };
    $klien = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    // Produk baru + harga & satuan awalnya dalam satu transaksi = satu kiriman.
    $klien()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    expect($hitung())->toBe(1);

    // Kirim ulang idempoten (Uuid sama) dan simpan form tanpa perubahan: tidak ada kiriman baru.
    $klien()->post('/kelola/produk', $form);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->firstOrFail();
    $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->firstOrFail();
    $klien()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'Sku' => $produk->Sku, 'Satuan' => [[...$form['Satuan'][0], 'Uuid' => $satuan->Uuid]]])
        ->assertSessionHasNoErrors();
    expect($hitung())->toBe(1);

    // Harga dasar diubah dari halaman harga: datanya sudah memuat harga baru.
    $klien()->put("/kelola/produk/{$form['Uuid']}/harga", ['Satuan' => [['UuidProdukSatuan' => $satuan->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '99500']]]]])
        ->assertSessionHasNoErrors();
    expect($hitung())->toBe(2);
    $terakhir = KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->latest('Id')->firstOrFail();
    expect($terakhir->Muatan['Data']['Satuan'][0]['HargaDasar'])->toBe('99500.00');

    // Arsip & pulihkan masing-masing satu kiriman, status Aktif terbawa.
    $klien()->post("/kelola/produk/{$form['Uuid']}/arsipkan")->assertSessionHasNoErrors();
    $klien()->post("/kelola/produk/{$form['Uuid']}/pulihkan")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kiriman = KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->orderBy('Id')->get();
    expect($kiriman)->toHaveCount(4)
        ->and($kiriman[2]->Muatan['Data']['Aktif'])->toBeFalse()
        ->and($kiriman[3]->Muatan['Data']['Aktif'])->toBeTrue();
});

it('produk.diubah terpicu saat Favorit berubah (form dan aksi massal), memuat Favorit, dan tidak terpicu bila nilainya sama', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($t['Tenant']);
    DaftarkanWebhookUji($this, $t, ['produk.diubah']);
    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
        'Nama' => 'Kuas Cat Tembok 4 inci',
        'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '18500']], defaultJual: true)],
    ]);
    $klien = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    $hitung = function () use ($t): int {
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        return KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->count();
    };

    $klien()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    expect($hitung())->toBe(1);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->firstOrFail();
    $form['Satuan'][0]['Uuid'] = ProdukSatuan::query()->where('IdProduk', $produk->Id)->firstOrFail()->Uuid;
    $form['Sku'] = $produk->Sku;

    // Hanya Favorit yang berubah: satu kiriman baru, datanya memuat Favorit = true.
    $klien()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'Favorit' => true])->assertSessionHasNoErrors();
    expect($hitung())->toBe(2);
    $kiriman = KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->orderBy('Id')->get();
    expect($kiriman[1]->Muatan['Data']['Favorit'])->toBeTrue();
    PeriksaDataWebhookSesuaiSpesifikasi('produk.diubah', $kiriman[1]->Muatan['Data']);

    // Nilai yang sama tidak mengirim apa pun.
    $klien()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'Favorit' => true])->assertSessionHasNoErrors();
    expect($hitung())->toBe(2);

    // Aksi massal "Hapus favorit" juga memicu, dan memuat Favorit = false.
    $klien()->post('/kelola/produk/massal', ['Aksi' => 'HapusFavorit', 'Uuid' => [$form['Uuid']]])->assertSessionHasNoErrors();
    expect($hitung())->toBe(3);
    $terakhir = KirimanWebhook::query()->where('Peristiwa', 'produk.diubah')->latest('Id')->firstOrFail();
    expect($terakhir->Muatan['Data']['Favorit'])->toBeFalse();
});

it('penangan webhook integrasi hanya diantrekan bila tenant punya webhook aktif yang melanggan peristiwa itu', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($t['Tenant']);
    $antreanIntegrasi = fn (): int => Queue::pushed(AntrekanWebhookIntegrasiTugas::class)->count();
    $simpan = fn (string $nama) => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => $nama]))->assertSessionHasNoErrors();

    // Belum ada webhook: produk baru tidak menambah tugas antrean.
    Queue::fake();
    $simpan('Paku Beton 7 cm (1 kg)');
    expect($antreanIntegrasi())->toBe(0);

    // Webhook yang tidak melanggan produk.diubah juga tidak.
    DaftarkanWebhookUji($this, $t, ['pelanggan.dibuat']);
    Queue::fake();
    $simpan('Paku Beton 10 cm (1 kg)');
    expect($antreanIntegrasi())->toBe(0);

    DaftarkanWebhookUji($this, $t, ['produk.diubah'], 'https://gudang.contoh.co.id/payoung-2');
    Queue::fake();
    $simpan('Paku Beton 12 cm (1 kg)');
    expect($antreanIntegrasi())->toBe(1);
});

it('pelanggan.dibuat, shift.ditutup, stok.disesuaikan, PO disetujui, dan GRN diposting terkirim sesuai spesifikasi; hanya yang dilanggan', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['pelanggan.dibuat', 'shift.ditutup', 'stok.disesuaikan', 'pesanan-pembelian.disetujui', 'penerimaan-barang.diposting']);
    BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');

    // Pelanggan dari jalur mana pun (di sini langsung model, seperti impor/toko online).
    $pelanggan = Pelanggan::query()->create(['Nama' => 'CV Karya Mandiri Bangun Persada', 'NoHp' => '081234567890']);

    $penyesuaian = app(AjukanPenyesuaianStok::class)->Jalankan(BantuanDokumenPersediaan::DrafPenyesuaian($k['Gudang'], AlasanPenyesuaian::Rusak, [BantuanDokumenPersediaan::Baris($semen, '-3')]), $k['Pemilik']->Id);

    $pemasok = BantuanPembelian::BuatPemasok('PT Semen Indonesia Distribusi Jateng', idPengguna: $k['Pemilik']->Id);
    $po = BantuanPembelian::BuatPoDisetujui($pemasok, $k['Gudang'], [[$semen, '50', '51500']], $k['Pemilik']->Id);
    $grn = BantuanPembelian::TerimaDariPo($po, ['50'], $k['Pemilik']->Id);

    $tutup = [
        'Jenis' => 'Shift.Tutup',
        'Uuid' => BantuanKasir::Uuid(),
        'Data' => [
            'UuidShift' => $k['UuidShift'],
            'UuidPengguna' => $k['Kasir']->Uuid,
            'DitutupPada' => now()->subMinute()->utc()->toIso8601ZuluString(),
            'KasAktual' => '500000.00',
            'PecahanKasAkhir' => null,
            'NonTunai' => [],
            'Alasan' => null,
            'UuidPenyetuju' => null,
            'Ringkasan' => ['KasSeharusnya' => '500000.00', 'Selisih' => '0.00'],
        ],
    ];
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tutup]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $data = fn (string $peristiwa): array => KirimanWebhook::query()->where('Peristiwa', $peristiwa)->sole()->Muatan['Data'];

    expect($data('pelanggan.dibuat')['Uuid'])->toBe($pelanggan->Uuid)
        ->and($data('stok.disesuaikan'))->toMatchArray(['Uuid' => $penyesuaian->Uuid, 'Nomor' => $penyesuaian->Nomor, 'UuidGudang' => $k['Gudang']->Uuid, 'Alasan' => 'Rusak'])
        ->and($data('stok.disesuaikan')['Baris'][0])->toEqual(['UuidProduk' => $semen->Uuid, 'Jumlah' => '-3.0000', 'NomorBatch' => null])
        ->and($data('pesanan-pembelian.disetujui'))->toMatchArray(['Uuid' => $po->Uuid, 'UuidPemasok' => $pemasok->Uuid, 'Total' => '2575000.00'])
        ->and($data('pesanan-pembelian.disetujui')['Baris'][0])->toMatchArray(['UuidProduk' => $semen->Uuid, 'Jumlah' => '50.0000', 'Harga' => '51500.00'])
        ->and($data('penerimaan-barang.diposting'))->toMatchArray(['Uuid' => $grn->Uuid, 'UuidPesananPembelian' => $po->Uuid, 'Nomor' => $grn->Nomor])
        ->and($data('shift.ditutup'))->toMatchArray(['Uuid' => $k['UuidShift'], 'UuidOutlet' => $k['Outlet']->Uuid, 'KasSeharusnya' => '500000.00', 'KasAktual' => '500000.00', 'Selisih' => '0.00'])
        ->and(KirimanWebhook::query()->count())->toBe(5)
        ->and(KirimanWebhook::query()->where('Status', StatusKirimanWebhook::Terkirim->value)->count())->toBe(5);

    foreach (['pelanggan.dibuat', 'shift.ditutup', 'stok.disesuaikan', 'pesanan-pembelian.disetujui', 'penerimaan-barang.diposting'] as $peristiwa) {
        PeriksaDataWebhookSesuaiSpesifikasi($peristiwa, $data($peristiwa));
    }

    // Tanpa HPP/nilai persediaan di penyesuaian.
    expect(json_encode($data('stok.disesuaikan')))->not->toContain('Hpp')->not->toContain('Nilai');
});

it('PeristiwaIntegrasi yang ditangani ulang tidak menggandakan kiriman; kunci berbeda (tutup ulang shift) = kiriman baru', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['shift.ditutup']);

    $peristiwa = new PeristiwaIntegrasi($k['Tenant']->Id, 'shift.ditutup', 991, ['Uuid' => 'shift-uji'], '1');
    app(AntrekanWebhookIntegrasi::class)->handle($peristiwa);
    app(AntrekanWebhookIntegrasi::class)->handle($peristiwa);
    app(AntrekanWebhookIntegrasi::class)->handle(new PeristiwaIntegrasi($k['Tenant']->Id, 'shift.ditutup', 991, ['Uuid' => 'shift-uji'], '2'));
    app(AntrekanWebhookIntegrasi::class)->handle(new PeristiwaIntegrasi($k['Tenant']->Id, 'jenis.tidak-dikenal', 991, []));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KirimanWebhook::query()->pluck('KunciPeristiwa')->sort()->values()->all())->toBe(['1', '2']);
});

it('stok.menipis dikirim sekali saat saldo turun melewati stok minimum; terkirim lagi setelah stok diisi di atas minimum', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['stok.menipis']);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '20', '52000', '63500.00');
    $pasir = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Pasir Merapi Ayakan (karung 25 kg)', '20', '15000', '21000.00');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    ProdukGudang::query()->create(['IdProduk' => $semen->Id, 'IdGudang' => $k['Gudang']->Id, 'StokMinimum' => '10']);
    $jual = function (Produk $produk, string $jumlah) use ($k, $semen): void {
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => $jumlah, 'Harga' => $produk->Is($semen) ? '63500.00' : '21000.00']]]);
    };
    $kiriman = function () use ($k) {
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        return KirimanWebhook::query()->where('Peristiwa', 'stok.menipis')->orderBy('Id')->get();
    };

    // 20 → 12 (masih di atas 10), lalu 12 → 9 melewati batas: satu kiriman; 9 → 7 tidak mengirim lagi.
    $jual($semen, '8');
    expect($kiriman())->toHaveCount(0);
    $jual($semen, '3');
    $jual($semen, '2');
    // Produk tanpa batas minimum tidak pernah dikirim.
    $jual($pasir, '19');
    expect($kiriman())->toHaveCount(1)
        ->and($kiriman()[0]->Muatan['Data'])->toMatchArray([
            'UuidProduk' => $semen->Uuid,
            'NamaProduk' => 'Semen Gresik Portland Komposit 40 kg',
            'UuidGudang' => $k['Gudang']->Uuid,
            'Saldo' => '9.0000',
            'StokMinimum' => '10.0000',
        ])
        ->and($kiriman()[0]->Status)->toBe(StatusKirimanWebhook::Terkirim);
    PeriksaDataWebhookSesuaiSpesifikasi('stok.menipis', $kiriman()[0]->Muatan['Data']);

    // Diisi kembali ke 27 lalu turun ke 10 (= minimum): kiriman kedua.
    $pemasok = BantuanPembelian::BuatPemasok('PT Semen Indonesia Distribusi Jateng', idPengguna: $k['Pemilik']->Id);
    BantuanPembelian::TerimaDariPo(BantuanPembelian::BuatPoDisetujui($pemasok, $k['Gudang'], [[$semen, '20', '51500']], $k['Pemilik']->Id), ['20'], $k['Pemilik']->Id);
    $jual($semen, '17');
    expect($kiriman())->toHaveCount(2)
        ->and($kiriman()[1]->Muatan['Data']['Saldo'])->toBe('10.0000');
});

it('pembayaran.diterima dikirim saat pelunasan piutang diposting, berisi alokasi per piutang', function (): void {
    Http::fake(['gudang.contoh.co.id/*' => Http::response('', 204)]);
    $k = BantuanPenjualan::Siapkan($this, 'Grosir Sembako Pelunasan Webhook');
    AktifkanFiturWebhook($k['Tenant']);
    DaftarkanWebhookUji($this, $k, ['pembayaran.diterima']);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550001', 'LimitKredit' => '5000000', 'TerminHari' => 30]);
    $item = BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '77000.00']]],
        ['UuidPelanggan' => $toko->Uuid],
    );
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $piutang = Piutang::query()->sole();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->post('/kelola/piutang/pelunasan', [
        'UuidPelanggan' => $toko->Uuid,
        'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
        'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
        'Catatan' => 'Transfer BCA a.n. Toko Makmur Jaya',
        'Alokasi' => [['UuidPiutang' => $piutang->Uuid, 'Jumlah' => '50000']],
    ])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $bayar = PembayaranPiutang::query()->sole();
    $data = KirimanWebhook::query()->where('Peristiwa', 'pembayaran.diterima')->sole()->Muatan['Data'];
    expect($data)->toMatchArray([
        'Sumber' => 'PelunasanPiutang',
        'Uuid' => $bayar->Uuid,
        'Nomor' => $bayar->Nomor,
        'UuidPelanggan' => $toko->Uuid,
        'Jumlah' => '50000.00',
        'Metode' => null,
        'Giro' => false,
        'Alokasi' => [['NomorPiutang' => $piutang->Nomor, 'Jumlah' => '50000.00']],
    ]);
    PeriksaDataWebhookSesuaiSpesifikasi('pembayaran.diterima', $data);
});
