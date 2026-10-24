<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-08 (D-189): QRIS statis toko dibuat berperilaku seperti dinamis. Peramban membaca teks QR dari gambar yang diunggah;
 * server memeriksa dan menyimpannya; data-awal membawanya ke kasir yang menyusun QRIS bernominal di perangkat.
 * Penerimaan dana tetap dikonfirmasi kasir (tanpa penyedia pembayaran).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
});

/** @return array{Kasus: list<array<string, string>>, TidakSah: list<array<string, string>>} */
function VektorQrisFitur(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 5).'/Spesifikasi/VektorUjiQris/QrisDinamis.json'), true, flags: JSON_THROW_ON_ERROR);
}

function TeksQrisStatisUji(): string
{
    return VektorQrisFitur()['Kasus'][0]['Statis'];
}

/** @return array<string, mixed> */
function IsianQrisStatisUji(array $ubah = []): array
{
    return ['Jenis' => 'QrisStatis', 'Nama' => 'QRIS Toko', 'GambarQris' => UploadedFile::fake()->image('qris.png', 400, 400), 'TeksQris' => TeksQrisStatisUji(), ...$ubah];
}

it('tambah QRIS statis dengan teks hasil baca gambar: tersimpan, terdaftar sebagai nominal otomatis, tercatat audit', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
    $tes = BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

    $tes->post('/kelola/panduan-awal/metode-pembayaran', IsianQrisStatisUji())->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($tenant->Id);
    $metode = MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole();
    expect($metode->TeksQris)->toBe(TeksQrisStatisUji())
        ->and(LogAudit::query()->where('Peristiwa', 'metode-pembayaran.buat')->latest('Id')->first()->NilaiBaru['NominalOtomatis'])->toBeTrue();

    $tes->get('/kelola/panduan-awal/metode-pembayaran')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('MetodePembayaran.1.Jenis', 'QrisStatis')
        ->where('MetodePembayaran.1.NominalOtomatis', true));
});

it('tanpa teks (gambar saja) tetap diterima seperti sebelumnya: nominal otomatis mati', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/metode-pembayaran', IsianQrisStatisUji(['TeksQris' => null]))->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole()->TeksQris)->toBeNull();
});

it('teks QRIS cacat ditolak dengan alasan jelas dan tidak ada berkas atau metode yang tersimpan', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
    $tes = BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
    $statis = TeksQrisStatisUji();

    foreach ([
        'CRC salah' => [substr($statis, 0, -4).'0000', 'checksum'],
        'bukan QRIS' => ['https://payoung.id/s/ABC', 'bukan kode QRIS'],
        'sudah dinamis' => [VektorQrisFitur()['TidakSah'][5]['Teks'], 'sudah berisi nominal'],
    ] as [$teks, $kata]) {
        $respons = $tes->post('/kelola/panduan-awal/metode-pembayaran', IsianQrisStatisUji(['TeksQris' => $teks]));
        $respons->assertSessionHasErrors('TeksQris');
        expect(strtolower((string) session('errors')->first('TeksQris')))->toContain(strtolower($kata));
    }

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(MetodePembayaran::query()->where('Jenis', 'QrisStatis')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('teks QRIS hanya berarti untuk QRIS statis: jenis lain mengabaikannya', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'QrisDinamis', 'Nama' => 'QRIS Otomatis', 'TeksQris' => TeksQrisStatisUji()])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(MetodePembayaran::query()->where('Jenis', 'QrisDinamis')->sole()->TeksQris)->toBeNull();
});

it('metode QRIS statis lama: aktifkan nominal otomatis lalu matikan; teks cacat atau metode lain ditolak; tenant lain 404', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
    ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanPanduanAwal::BuatTenant('Toko Roti Harum');
    $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

    $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianQrisStatisUji(['TeksQris' => null]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $metode = MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole();
    $tunai = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tunai->value)->sole();
    $alamat = fn (MetodePembayaran $m): string => "/kelola/panduan-awal/metode-pembayaran/{$m->Uuid}/teks-qris";

    $tes()->post($alamat($metode), ['TeksQris' => TeksQrisStatisUji()])->assertSessionHasNoErrors()->assertSessionHas('Kilat');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect($metode->refresh()->TeksQris)->toBe(TeksQrisStatisUji())
        ->and(LogAudit::query()->where('Peristiwa', 'metode-pembayaran.teks-qris')->count())->toBe(1);

    // Kirim ulang teks yang sama tidak menambah audit.
    $tes()->post($alamat($metode), ['TeksQris' => TeksQrisStatisUji()])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(LogAudit::query()->where('Peristiwa', 'metode-pembayaran.teks-qris')->count())->toBe(1);

    $tes()->post($alamat($metode), ['TeksQris' => substr(TeksQrisStatisUji(), 0, -4).'0000'])->assertSessionHasErrors('TeksQris');
    $tes()->post($alamat($tunai), ['TeksQris' => TeksQrisStatisUji()])->assertSessionHasErrors('TeksQris');

    $tes()->post($alamat($metode), ['TeksQris' => null])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect($metode->refresh()->TeksQris)->toBeNull();

    BantuanPanduanAwal::Masuk($this, $pemilikLain, $lain)->post($alamat($metode), ['TeksQris' => TeksQrisStatisUji()])->assertNotFound();
});

it('data-awal POS membawa TeksQris hanya untuk QRIS statis yang punya teks; jenis lain dan tanpa teks null', function (): void {
    $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
    $a['Qris']->forceFill(['TeksQris' => TeksQrisStatisUji()])->save();
    $a['Edc']->forceFill(['TeksQris' => TeksQrisStatisUji()])->save();

    $metode = collect($this->withToken($a['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('MetodePembayaran'));

    expect($metode->firstWhere('Uuid', $a['Qris']->Uuid)['TeksQris'])->toBe(TeksQrisStatisUji())
        ->and($metode->firstWhere('Uuid', $a['Edc']->Uuid)['TeksQris'])->toBeNull();
});
