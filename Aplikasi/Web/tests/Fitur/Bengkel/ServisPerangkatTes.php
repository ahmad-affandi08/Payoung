<?php

declare(strict_types=1);

use App\Domain\Bengkel\Enum\JenisUnitServis;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pelanggan\Model\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Servis non-kendaraan (§9.6 elektronik, §9.10 work order, D-142): unit servis berjenis Perangkat (HP, laptop) memakai
 * alur perintah kerja yang sama. IMEI / nomor seri boleh kosong tetapi unik di antara unit aktif bila diisi; KM tidak
 * berlaku; jenis unit tidak bisa berganti; pesan WhatsApp menyebut perangkatnya, bukan "kendaraan".
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8001']])]);
});

/** @return array<string, mixed> */
function SiapkanServisElektronik(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Servis HP Cepat Solo');

    return $k + [
        'GantiLcd' => BantuanKatalog::BuatProduk(['Nama' => 'Jasa Ganti LCD Samsung Galaxy A54', 'Jenis' => JenisProduk::Jasa], '450000.00'),
        'Pelanggan' => Pelanggan::query()->create(['Nama' => 'Dewi Lestari Kusuma', 'NoHp' => '6281298765432']),
    ];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $isi
 */
function SimpanUnitUji(TestCase $tes, array $k, array $isi): TestResponse
{
    return BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id)->post('/kelola/bengkel/kendaraan', $isi + [
        'UuidPelanggan' => $k['Pelanggan']->Uuid,
        'Jenis' => 'Perangkat',
        'Merek' => 'Samsung',
        'Tipe' => 'Galaxy A54',
    ]);
}

it('perangkat boleh tanpa IMEI; IMEI dirapikan, unik di antara unit aktif, dan jenis unit tidak bisa berganti', function (): void {
    $k = SiapkanServisElektronik($this);

    SimpanUnitUji($this, $k, [])->assertSessionHasNoErrors();
    SimpanUnitUji($this, $k, ['Merek' => 'Apple', 'Tipe' => 'iPhone 13'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Kendaraan::query()->where('Jenis', JenisUnitServis::Perangkat->value)->whereNull('NomorPolisi')->count())->toBe(2);

    SimpanUnitUji($this, $k, ['NomorPolisi' => ' 356938 03 5 ab12 ', 'Merek' => 'Xiaomi', 'Tipe' => 'Redmi Note 12'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $unit = Kendaraan::query()->where('Merek', 'Xiaomi')->firstOrFail();
    expect($unit->NomorPolisi)->toBe('356938 03 5 AB12')->and($unit->Jenis)->toBe(JenisUnitServis::Perangkat);

    // IMEI yang sama untuk unit aktif lain ditolak; setelah diarsipkan nomornya terlepas.
    SimpanUnitUji($this, $k, ['NomorPolisi' => '356938 03 5 ab12', 'Merek' => 'Oppo'])->assertSessionHasErrors('NomorPolisi');
    SimpanUnitUji($this, $k, ['NomorPolisi' => 'a!', 'Merek' => 'Oppo'])->assertSessionHasErrors('NomorPolisi');

    // Kendaraan tetap wajib plat; field kendaraan diabaikan untuk perangkat.
    SimpanUnitUji($this, $k, ['Jenis' => 'Kendaraan', 'Merek' => 'Honda'])->assertSessionHasErrors('NomorPolisi');
    SimpanUnitUji($this, $k, ['NomorRangka' => 'MH1JM0', 'KmTerakhir' => 500, 'Merek' => 'Vivo'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $vivo = Kendaraan::query()->where('Merek', 'Vivo')->firstOrFail();
    expect($vivo->NomorRangka)->toBeNull()->and($vivo->KmTerakhir)->toBeNull();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->put("/kelola/bengkel/kendaraan/{$unit->Uuid}", [
        'UuidPelanggan' => $k['Pelanggan']->Uuid, 'Jenis' => 'Kendaraan', 'NomorPolisi' => 'AD 1 AB', 'Merek' => 'Honda',
    ])->assertSessionHasErrors('Jenis');
});

it('perintah kerja perangkat: KM diabaikan, rincian membawa Jenis, pesan WhatsApp menyebut perangkat, pengingat berbasis KM ditolak', function (): void {
    $k = SiapkanServisElektronik($this);
    SimpanUnitUji($this, $k, ['NomorPolisi' => '356938035643809'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $unit = Kendaraan::query()->firstOrFail();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->post('/kelola/bengkel/perintah-kerja', [
        'UuidOutlet' => $k['Outlet']->Uuid,
        'UuidPelanggan' => $k['Pelanggan']->Uuid,
        'UuidKendaraan' => $unit->Uuid,
        'KmMasuk' => 12345,
        'Keluhan' => 'Layar retak setelah jatuh, sentuhan tidak responsif. Kelengkapan: charger, casing, SIM card.',
        'Baris' => [['Jenis' => 'Jasa', 'UuidProduk' => $k['GantiLcd']->Uuid, 'Jumlah' => '1']],
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pk = PerintahKerja::query()->firstOrFail();
    expect($pk->KmMasuk)->toBeNull()->and($pk->Total)->toBe('450000.00');
    expect($unit->refresh()->KmTerakhir)->toBeNull();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->get("/kelola/bengkel/perintah-kerja/{$pk->Uuid}")
        ->assertInertia(fn (AssertableInertia $h) => $h
            ->where('PerintahKerja.Kendaraan.Jenis', 'Perangkat')
            ->where('PerintahKerja.Kendaraan.NomorPolisi', '356938035643809'));

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan", ['KirimWhatsapp' => true])->assertSessionHasNoErrors();
    Http::assertSent(fn (PermintaanHttp $r): bool => str_contains((string) $r['message'], 'servis perangkat Samsung Galaxy A54') && ! str_contains((string) $r['message'], 'kendaraan'));

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->put("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/servis-berikutnya", ['ServisBerikutnyaKm' => 20000])
        ->assertSessionHasErrors('ServisBerikutnyaKm');
});

it('daftar unit dapat difilter menurut jenis', function (): void {
    $k = SiapkanServisElektronik($this);
    SimpanUnitUji($this, $k, ['NomorPolisi' => 'SN-AAA-111'])->assertSessionHasNoErrors();
    SimpanUnitUji($this, $k, ['Jenis' => 'Kendaraan', 'NomorPolisi' => 'AD 77 ZZ', 'Merek' => 'Yamaha'])->assertSessionHasNoErrors();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->get('/kelola/bengkel/kendaraan?saring[Jenis]=Perangkat')
        ->assertInertia(fn (AssertableInertia $h) => $h->has('Kendaraan.Data', 1)->where('Kendaraan.Data.0.Jenis', 'Perangkat'));
});
