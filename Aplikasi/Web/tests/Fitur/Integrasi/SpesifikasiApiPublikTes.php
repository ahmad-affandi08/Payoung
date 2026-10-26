<?php

declare(strict_types=1);

use App\Domain\Integrasi\ApiPublik\Aksi\BuatTokenApi;
use App\Domain\Integrasi\ApiPublik\Enum\CakupanApi;
use App\Domain\Integrasi\ApiPublik\Enum\PeristiwaWebhook;
use App\Domain\Integrasi\ApiPublik\Layanan\PenyusunDataPenjualanApi;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Http\Kontroler\Publik\PengembangKontroler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * X7 bagian 3: spesifikasi OpenAPI publik (`public/pengembang/openapi-v1.json`) adalah kontrak yang dibaca pengembang
 * pihak ketiga dan portal `/pengembang`. Test ini menjaganya tetap sama dengan kenyataan: setiap rute `/api/v1` ada di
 * spesifikasi dan sebaliknya, cakupan & peristiwa webhook sama dengan enum, dan kunci respons nyata sama persis dengan
 * properti skema (tidak ada field bocor yang tidak terdokumentasi, tidak ada field terdokumentasi yang hilang).
 */

/** @return array<string, mixed> */
function BacaSpesifikasiApiPublik(): array
{
    return json_decode((string) file_get_contents(public_path(PengembangKontroler::BERKAS_SPESIFIKASI)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Bandingkan kunci data dengan properti skema, turun ke array objek (`Baris`, `Pembayaran`, `Satuan`).
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $skema
 */
function CocokkanDenganSkema(array $data, array $skema, string $jejak): void
{
    $properti = (array) $skema['properties'];
    expect(array_keys($data))->toEqualCanonicalizing(array_keys($properti), "Kunci {$jejak} berbeda dengan spesifikasi");

    foreach ($properti as $nama => $p) {
        $p = (array) $p;

        if (($p['type'] ?? null) === 'array' && isset($p['items']['properties']) && is_array($data[$nama]) && $data[$nama] !== []) {
            CocokkanDenganSkema((array) $data[$nama][0], (array) $p['items'], "{$jejak}.{$nama}[0]");
        }
    }
}

it('setiap rute /api/v1 terdokumentasi dan sebaliknya. Cakupan & peristiwa webhook sama dengan enum', function (): void {
    $spesifikasi = BacaSpesifikasiApiPublik();
    expect($spesifikasi['openapi'])->toBe('3.1.0');

    $rute = [];

    foreach (Route::getRoutes() as $r) {
        if (str_starts_with($r->uri(), 'api/v1/')) {
            foreach (array_diff($r->methods(), ['HEAD']) as $metode) {
                $rute[] = strtolower($metode).' /'.substr($r->uri(), strlen('api/v1/'));
            }
        }
    }

    $terdokumentasi = [];
    $cakupanDipakai = [];

    foreach ($spesifikasi['paths'] as $jalur => $daftar) {
        foreach ($daftar as $metode => $op) {
            $terdokumentasi[] = "{$metode} {$jalur}";
            $cakupanDipakai[] = $op['security'][0]['TokenApi'][0];
        }
    }

    expect($terdokumentasi)->toEqualCanonicalizing($rute)
        ->and(array_values(array_unique($cakupanDipakai)))->toEqualCanonicalizing(CakupanApi::AmbilSemuaNilai())
        ->and(array_keys($spesifikasi['webhooks']))->toEqualCanonicalizing(PeristiwaWebhook::AmbilSemuaNilai());
});

it('kunci respons nyata endpoint & muatan webhook sama persis dengan skema spesifikasi', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $skema = BacaSpesifikasiApiPublik()['components']['schemas'];
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    OverrideTenant::query()->create([
        'IdTenant' => $k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => 'api.publik',
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji spesifikasi API',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    Pelanggan::query()->create(['Nama' => 'CV Karya Mandiri Bangun Persada', 'NoHp' => '081234567890']);
    $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $semen, 'Jumlah' => '4', 'Harga' => '63500.00']]]);
    $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']])]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $token = app(BuatTokenApi::class)->Jalankan($k['Tenant']->Id, $k['Pemilik']->Id, 'Uji spesifikasi', CakupanApi::AmbilSemuaNilai(), null)['Token'];
    $api = fn (string $url): array => (array) $this->withToken($token)->getJson($url)->assertOk()->json();

    $produk = $api('/api/v1/produk');
    expect(array_keys($produk))->toBe(['Data', 'Kursor']);
    CocokkanDenganSkema($produk['Kursor'], $skema['Kursor'], 'Kursor');
    CocokkanDenganSkema($produk['Data'][0], $skema['Produk'], 'Produk');
    CocokkanDenganSkema($api("/api/v1/produk/{$semen->Uuid}")['Data'], $skema['Produk'], 'Produk satu');
    CocokkanDenganSkema($api('/api/v1/stok')['Data'][0], $skema['Stok'], 'Stok');
    CocokkanDenganSkema($api('/api/v1/penjualan?dari=2026-10-05&sampai=2026-10-05')['Data'][0], $skema['Penjualan'], 'Penjualan');
    CocokkanDenganSkema($api('/api/v1/pelanggan')['Data'][0], $skema['Pelanggan'], 'Pelanggan');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $retur = app(PenyusunDataPenjualanApi::class)->Retur(ReturPenjualan::query()->sole()->Id);
    expect($retur)->not->toBeNull();
    CocokkanDenganSkema((array) $retur, $skema['ReturPenjualan'], 'ReturPenjualan');
});

it('portal /pengembang menampilkan endpoint & webhook dari spesifikasi, berkas spesifikasi bisa diunduh', function (): void {
    $this->get('/pengembang')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Situs/Pengembang')
            ->has('Endpoint', count(BacaSpesifikasiApiPublik()['paths']))
            ->where('Endpoint.0.Jalur', '/api/v1/produk')
            ->where('Endpoint.0.Cakupan', 'produk:baca')
            ->has('Webhook', count(PeristiwaWebhook::cases()))
            ->where('UnduhSpesifikasi', '/pengembang/openapi-v1.json'));

    expect(file_exists(public_path('pengembang/openapi-v1.json')))->toBeTrue();
});
