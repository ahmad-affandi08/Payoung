<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaProduk;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Layanan\PenyusunKursorKatalog;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Tenant\Enum\StatusLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Carbon::setTestNow('2026-09-27 03:00:00');
    $this->t = BantuanKatalog::SiapkanTenantProduk();
    $this->produk = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren', 'IdKelompokPajak' => $this->t['KelompokPajak']->Id], '18000.00', $this->t['Pcs']);
    $this->pcs = BantuanHarga::SatuanDasar($this->produk);
    $this->daftar = BantuanHarga::BuatDaftarHarga('Harga Ojek Online', ['IdOutlet' => [$this->t['Outlet']->Id], 'Kanal' => KanalPenjualan::Online, 'Prioritas' => 10]);
    BantuanHarga::TambahHargaDaftar($this->daftar, $this->pcs, '1', '21000');
    ['Token' => $this->token] = BantuanPerangkat::BuatDanAktifkan($this, $this->t['Tenant']->Id);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function AmbilKatalogPos(TestCase $tes, string $token, ?string $sejak = null): TestResponse
{
    return $tes->withToken($token)->withHeader('X-Versi-Aplikasi', '1.0.0')
        ->getJson('/api/pos/v1/katalog'.($sejak === null ? '' : '?sejak='.urlencode($sejak)));
}

describe('F-03 katalog POS GET /api/pos/v1/katalog (D.3)', function (): void {
    it('lengkap: amplop, semua bagian bertanda (Tim 1, 2, 3), daftar harga dengan UuidOutlet, uang sebagai string', function (): void {
        $respons = AmbilKatalogPos($this, $this->token)->assertOk()
            ->assertJsonPath('Skema', 1)
            ->assertJsonPath('Lengkap', true)
            ->assertJsonPath('WaktuServer', '2026-09-27T03:00:00Z')
            ->assertJsonPath('Terhapus', []);

        expect(array_keys($respons->json()))->toContain('Kategori', 'Satuan', 'Produk', 'ProdukSatuan', 'ProdukBarcode', 'KelompokPajak', 'DaftarHarga', 'ProdukHarga', 'KelompokPilihan', 'Pilihan', 'Resep', 'PaketProdukDetail')
            ->and(app(PenyusunKursorKatalog::class)->Baca((string) $respons->json('Kursor'))->toIso8601ZuluString())->toBe('2026-09-27T02:58:00Z')
            ->and($respons->json('Produk.0.UuidKelompokPajak'))->toBe($this->t['KelompokPajak']->Uuid)
            ->and($respons->json('KelompokPajak'))->toContain(['Uuid' => $this->t['KelompokPajak']->Uuid, 'Nama' => 'Barang kena PPN', 'Kategori' => null, 'Pajak' => []])
            ->and($respons->json('DaftarHarga'))->toBe([[
                'Uuid' => $this->daftar->Uuid, 'Nama' => 'Harga Ojek Online', 'UuidOutlet' => [$this->t['Outlet']->Uuid], 'Kanal' => 'Online',
                'TierPelanggan' => null, 'MulaiPada' => null, 'SelesaiPada' => null, 'Prioritas' => 10, 'Aktif' => true,
            ]])
            ->and(collect($respons->json('ProdukHarga'))->sortBy('Harga')->values()->all())->toBe([
                ['Uuid' => ProdukHarga::query()->whereNull('IdDaftarHarga')->value('Uuid'), 'UuidProduk' => $this->produk->Uuid, 'UuidProdukSatuan' => $this->pcs->Uuid, 'UuidDaftarHarga' => null, 'JumlahMinimum' => '1.0000', 'Harga' => '18000.00'],
                ['Uuid' => ProdukHarga::query()->whereNotNull('IdDaftarHarga')->value('Uuid'), 'UuidProduk' => $this->produk->Uuid, 'UuidProdukSatuan' => $this->pcs->Uuid, 'UuidDaftarHarga' => $this->daftar->Uuid, 'JumlahMinimum' => '1.0000', 'Harga' => '21000.00'],
            ]);
    });

    it('delta: hanya baris berubah sejak kursor (tumpang tindih 120 detik), produk terhapus Dihapus: true, jejak Terhapus', function (): void {
        $lain = BantuanKatalog::BuatProduk(['Nama' => 'Roti Bakar Cokelat Keju'], '15000.00', $this->t['Pcs']);
        Carbon::setTestNow('2026-09-27 03:05:00');
        $kursor = (string) AmbilKatalogPos($this, $this->token)->json('Kursor');

        Carbon::setTestNow('2026-09-27 03:10:00');
        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        $uuidDasar = ProdukHarga::query()->whereNull('IdDaftarHarga')->where('IdProdukSatuan', $this->pcs->Id)->value('Uuid');
        app(SimpanHargaProduk::class)->Jalankan($this->produk, [$this->pcs->Id => [BantuanHarga::Baris('1', '19000'), BantuanHarga::Baris('12', '17000')]], SumberPerubahanHarga::Manual);
        app(SimpanHargaProduk::class)->Jalankan($lain, [BantuanHarga::SatuanDasar($lain)->Id => []], SumberPerubahanHarga::Manual);
        $uuidRotiDihapus = PenghapusanKatalog::query()->value('UuidEntitas');
        $lain->delete();

        Carbon::setTestNow('2026-09-27 03:11:00');
        $respons = AmbilKatalogPos($this, $this->token, $kursor)->assertOk()->assertJsonPath('Lengkap', false);

        expect(collect($respons->json('ProdukHarga'))->pluck('Harga')->sort()->values()->all())->toBe(['17000.00', '19000.00'])
            ->and(collect($respons->json('ProdukHarga'))->pluck('Uuid'))->toContain($uuidDasar)
            ->and($respons->json('DaftarHarga'))->toBe([])
            ->and(collect($respons->json('Produk'))->firstWhere('Uuid', $lain->Uuid)['Dihapus'] ?? null)->toBeTrue()
            ->and(collect($respons->json('Produk'))->pluck('Uuid')->all())->not->toContain($this->produk->Uuid)
            ->and($respons->json('Terhapus'))->toBe([['Entitas' => EntitasKatalog::ProdukHarga->value, 'Uuid' => $uuidRotiDihapus]]);

        // Kursor baru tetap mengirim ulang perubahan 2 menit terakhir (tumpang tindih disengaja).
        $ulang = AmbilKatalogPos($this, $this->token, (string) $respons->json('Kursor'))->assertOk();
        expect($ulang->json('ProdukHarga'))->toHaveCount(2);
    });

    it('kursor tidak valid → 422 KursorTidakValid; kursor lebih tua dari 90 hari → sinkron lengkap', function (): void {
        AmbilKatalogPos($this, $this->token, 'bukan-kursor')->assertStatus(422)->assertJsonPath('Galat.Kode', 'KursorTidakValid');
        AmbilKatalogPos($this, $this->token, rtrim(strtr(base64_encode('{"W":"2026-09-01","S":1}'), '+/', '-_'), '='))->assertStatus(422);

        $lama = app(PenyusunKursorKatalog::class)->Buat(CarbonImmutable::now()->subDays(91));
        AmbilKatalogPos($this, $this->token, $lama)->assertOk()->assertJsonPath('Lengkap', true);
    });

    it('isolasi tenant: perangkat tenant B hanya menerima katalog tenant B', function (): void {
        $b = BantuanKatalog::SiapkanTenantProduk('Toko Makmur Jaya');
        $produkB = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit 2 L'], '32000.00', $b['Pcs']);
        ['Token' => $tokenB] = BantuanPerangkat::BuatDanAktifkan($this, $b['Tenant']->Id);

        $respons = AmbilKatalogPos($this, $tokenB)->assertOk();

        expect(collect($respons->json('Produk'))->pluck('Uuid')->all())->toBe([$produkB->Uuid])
            ->and($respons->json('DaftarHarga'))->toBe([])
            ->and(collect($respons->json('ProdukHarga'))->pluck('UuidProduk')->unique()->all())->toBe([$produkB->Uuid]);
    });

    it('tanpa token 401; langganan ditangguhkan → masalah kunci POS yang sudah ada', function (): void {
        $this->getJson('/api/pos/v1/katalog')->assertUnauthorized()->assertJsonPath('Galat.Kode', 'TokenPerangkatTidakValid');

        BantuanPerangkat::AturStatusLangganan($this->t['Tenant']->Id, StatusLangganan::Ditangguhkan);
        AmbilKatalogPos($this, $this->token)->assertForbidden()->assertJsonPath('Galat.Kode', 'LanggananTidakAktif');
    });

    it('kontrak kompatibel mundur (CLAUDE.md #16): kunci amplop + bagian semua tim PERSIS (urutan diabaikan)', function (): void {
        $kunci = array_keys(AmbilKatalogPos($this, $this->token)->assertOk()->json());
        sort($kunci);

        expect($kunci)->toBe([
            'DaftarHarga', 'Kategori', 'KelompokPajak', 'KelompokPilihan', 'Kursor', 'Lengkap', 'PaketProdukDetail', 'Pilihan',
            'Produk', 'ProdukBarcode', 'ProdukHarga', 'ProdukKelompokPilihan', 'ProdukSatuan', 'Resep', 'Satuan', 'Skema',
            'Terhapus', 'WaktuServer',
        ]);
    });
});

describe('F-03 gambar produk POS GET /api/pos/v1/katalog/gambar/{produk}', function (): void {
    it('200 dengan gambar (besar & kecil), 404 tanpa gambar, 404 untuk produk tenant lain', function (): void {
        Storage::fake((string) config('katalog.DiskGambar'));
        $path = "produk/{$this->t['Tenant']->Id}/{$this->produk->Uuid}-01JBGAMBARVERSI0000000000A.webp";
        Storage::disk((string) config('katalog.DiskGambar'))->put($path, 'gambar-besar');
        Storage::disk((string) config('katalog.DiskGambar'))->put(str_replace('.webp', '-kecil.webp', $path), 'gambar-kecil');
        $this->produk->fill(['PathGambar' => $path])->save();
        $tanpaGambar = BantuanKatalog::BuatProduk(['Nama' => 'Teh Tarik'], '12000.00', $this->t['Pcs']);
        $url = fn (string $uuid, string $ukuran = 'besar') => "/api/pos/v1/katalog/gambar/{$uuid}?ukuran={$ukuran}&versi=01JBGAMBARVERSI0000000000A";

        $besar = $this->withToken($this->token)->get($url($this->produk->Uuid))->assertOk()->assertHeader('Content-Type', 'image/webp');
        expect($besar->streamedContent())->toBe('gambar-besar');
        expect($this->withToken($this->token)->get($url($this->produk->Uuid, 'kecil'))->assertOk()->streamedContent())->toBe('gambar-kecil');
        $this->withToken($this->token)->getJson($url($tanpaGambar->Uuid))->assertNotFound();

        $b = BantuanKatalog::SiapkanTenantProduk('Toko Makmur Jaya');
        ['Token' => $tokenB] = BantuanPerangkat::BuatDanAktifkan($this, $b['Tenant']->Id);
        $this->withToken($tokenB)->getJson($url($this->produk->Uuid))->assertNotFound()->assertJsonPath('Galat.Kode', fn (mixed $kode) => is_string($kode));
    });
});
