<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\TransaksiKasBank;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\ProdukHabis;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Promo\Enum\StatusPromo;
use App\Domain\Promo\Model\Promo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * OWN-07: aksi cepat di Aplikasi Owner. Ubah harga dasar, tandai habis per outlet, nyalakan/matikan promo, dan catat
 * pengeluaran dengan foto nota, semuanya lewat Aksi back-office yang sama (riwayat harga, audit, jurnal).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function TokenAksiCepatUji(object $tes, Pengguna $pengguna, string $kataSandi = BantuanAutentikasi::KATA_SANDI): string
{
    $pengguna->forceFill(['EmailDiverifikasiPada' => now()])->save();
    $token = $tes->postJson('/api/pemilik/v1/masuk', ['Email' => $pengguna->Email, 'KataSandi' => $kataSandi, 'NamaPerangkat' => 'HP Uji'])->assertOk()->json('Token');

    return is_string($token) ? $token : '';
}

function PanggilAksiCepatUji(object $tes, string $token, string $uuidTenant, string $metode, string $url, array $data = [], array $header = []): TestResponse
{
    // `withHeaders` menempel di seluruh sisa tes; dibersihkan supaya `Idempotency-Key` satu panggilan tidak bocor ke panggilan lain.
    $tes->flushHeaders();

    return $tes->withToken($token)->withHeaders(['X-Tenant' => $uuidTenant, 'X-Versi-Aplikasi' => '1.0.0', ...$header])->json($metode, '/api/pemilik/v1/aksi-cepat'.$url, $data);
}

describe('OWN-07 ubah harga', function (): void {
    it('menetapkan harga dasar satuan jual, mempertahankan harga bertingkat, mencatat riwayat bersumber Aplikasi Owner dan audit', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren 16 oz'], '18000.00');
        $satuan = $produk->satuan()->first() ?? ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        ProdukHarga::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => $satuan->Id, 'IdDaftarHarga' => null, 'JumlahMinimum' => '10', 'Harga' => '16000.00']);
        $token = TokenAksiCepatUji($this, $k['Pemilik']);

        PanggilAksiCepatUji($this, $token, $k['Tenant']->Uuid, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '20000'])
            ->assertOk()
            ->assertJsonPath('HargaLama', '18000.00')
            ->assertJsonPath('HargaBaru', '20000.00')
            ->assertJsonPath('Berubah', true);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $harga = ProdukHarga::query()->where('IdProduk', $produk->Id)->orderBy('JumlahMinimum')->pluck('Harga', 'JumlahMinimum')->all();
        expect(array_map('strval', array_values($harga)))->toContain('20000.00')->toContain('16000.00')
            ->and(RiwayatHarga::query()->where('IdProduk', $produk->Id)->where('Sumber', 'AplikasiOwner')->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->count())->toBe(1);

        // Harga sama: tanpa perubahan dan tanpa audit baru.
        PanggilAksiCepatUji($this, $token, $k['Tenant']->Uuid, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '20000.00'])->assertOk()->assertJsonPath('Berubah', false);
        expect(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->count())->toBe(1);
    });

    it('menolak harga nol atau format salah, induk varian, produk tanpa harga dasar, produk tenant lain, dan peran tanpa izin', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk([], '18000.00');
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian], null);
        $tanpaHarga = BantuanKatalog::BuatProduk([], null);
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '0'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'HargaTidakValid');
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '12.345'])->assertStatus(422);
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '-5'])->assertStatus(422);
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$induk->Uuid}/harga", ['Harga' => '5000'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ProdukIndukVarian');
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$tanpaHarga->Uuid}/harga", ['Harga' => '5000'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'HargaDasarTidakAda');
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', '/produk/01J00000000000000000000000/harga', ['Harga' => '5000'])->assertNotFound();

        $kasir = TokenAksiCepatUji($this, $k['Kasir'], 'kata-sandi-uji');
        PanggilAksiCepatUji($this, $kasir, $tenant, 'PUT', "/produk/{$produk->Uuid}/harga", ['Harga' => '5000'])->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHarga::query()->where('IdProduk', $produk->Id)->value('Harga'))->toBe('18000.00');
    });

    it('produk tenant lain tidak terlihat dan tidak bisa diubah', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Toko A Harga');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $produkA = BantuanKatalog::BuatProduk(['Nama' => 'Produk Rahasia A'], '9000.00');
        $b = BantuanOrganisasi::BuatTenant('Toko B Harga');
        $tokenB = TokenAksiCepatUji($this, $b['Pemilik']);

        PanggilAksiCepatUji($this, $tokenB, $b['Tenant']->Uuid, 'PUT', "/produk/{$produkA->Uuid}/harga", ['Harga' => '1000'])->assertNotFound();
        PanggilAksiCepatUji($this, $tokenB, $b['Tenant']->Uuid, 'GET', '/produk?cari=Rahasia')->assertOk()->assertJsonPath('Produk', []);
    });
});

describe('OWN-07 tandai habis dan daftar produk', function (): void {
    it('mencari produk dengan harga dasar dan status habis per outlet; menandai habis lalu tersedia lagi (idempoten)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Roti Bakar Cokelat Keju'], '15000.00');
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos', 'Jenis' => JenisProduk::IndukVarian], null);
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;
        $outlet = $k['Outlet']->Uuid;

        $cari = PanggilAksiCepatUji($this, $token, $tenant, 'GET', "/produk?cari=Roti&outlet={$outlet}")->assertOk();
        expect($cari->json('Produk.0'))->toMatchArray(['Uuid' => $produk->Uuid, 'HargaDasar' => '15000.00', 'BisaUbahHarga' => true, 'BisaTandaiHabis' => true, 'Habis' => false]);
        $kaos = PanggilAksiCepatUji($this, $token, $tenant, 'GET', "/produk?cari=Kaos&outlet={$outlet}")->json('Produk.0');
        expect($kaos)->toMatchArray(['BisaUbahHarga' => false, 'BisaTandaiHabis' => false, 'Habis' => null]);

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Outlet' => $outlet, 'Habis' => true])->assertOk()->assertJsonPath('Habis', true);
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Outlet' => $outlet, 'Habis' => true])->assertOk()->assertJsonPath('Habis', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->where('IdProduk', $produk->Id)->count())->toBe(1);
        PanggilAksiCepatUji($this, $token, $tenant, 'GET', "/produk?cari=Roti&outlet={$outlet}")->assertJsonPath('Produk.0.Habis', true);

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Outlet' => $outlet, 'Habis' => false])->assertOk()->assertJsonPath('Habis', false);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->where('IdProduk', $produk->Id)->count())->toBe(0);
    });

    it('wajib outlet, outlet di luar akses 404, induk varian ditolak, kasir tanpa izin produk.kelola 403', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk([], '15000.00');
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian], null);
        $cabang = BantuanJurnal::BuatOutlet('KLT', 'Cabang Klaten');
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Habis' => true])->assertStatus(422);
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/produk/{$induk->Uuid}/habis", ['Outlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ProdukIndukVarian');

        $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $k['Outlet']->Id, 'IdPengguna' => $supervisor->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        $tokenManajer = TokenAksiCepatUji($this, $supervisor, 'kata-sandi-uji');
        PanggilAksiCepatUji($this, $tokenManajer, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Outlet' => $cabang->Uuid, 'Habis' => true])->assertNotFound();

        $kasir = TokenAksiCepatUji($this, $k['Kasir'], 'kata-sandi-uji');
        PanggilAksiCepatUji($this, $kasir, $tenant, 'PUT', "/produk/{$produk->Uuid}/habis", ['Outlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertForbidden();
    });
});

describe('OWN-07 promo', function (): void {
    it('menampilkan promo (aktif dulu, penanda berakhir) dan mematikan/menyalakannya lewat status Diarsipkan/Aktif dengan audit', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $aktif = Promo::query()->create(['Kode' => 'HEMAT5K', 'Nama' => 'Hemat 5 Ribu', 'Definisi' => ['Syarat' => [], 'Aksi' => ['Jenis' => 'PotonganNominal', 'Nilai' => '5000']], 'Status' => StatusPromo::Aktif]);
        $lama = Promo::query()->create(['Kode' => 'LEBARAN', 'Nama' => 'Promo Lebaran', 'Definisi' => ['Syarat' => [], 'Aksi' => ['Jenis' => 'PotonganNominal', 'Nilai' => '1000']], 'Status' => StatusPromo::Diarsipkan, 'SelesaiPada' => now()->subDay()]);
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;

        $daftar = PanggilAksiCepatUji($this, $token, $tenant, 'GET', '/promo')->assertOk();
        expect($daftar->json('Promo.0'))->toMatchArray(['Kode' => 'HEMAT5K', 'Aktif' => true, 'Berakhir' => false])
            ->and($daftar->json('Promo.1'))->toMatchArray(['Kode' => 'LEBARAN', 'Aktif' => false, 'Berakhir' => true]);
        PanggilAksiCepatUji($this, $token, $tenant, 'GET', '/promo?cari=lebar')->assertJsonCount(1, 'Promo');

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/promo/{$aktif->Uuid}/status", ['Aktif' => false])->assertOk()->assertJsonPath('Promo.Aktif', false);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($aktif->fresh()?->Status)->toBe(StatusPromo::Diarsipkan)
            ->and(LogAudit::query()->where('Peristiwa', 'promo.arsipkan')->count())->toBe(1);

        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/promo/{$aktif->Uuid}/status", ['Aktif' => true])->assertOk()->assertJsonPath('Promo.Aktif', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($aktif->fresh()?->Status)->toBe(StatusPromo::Aktif);

        // Mengulang tidak menambah audit; promo tenant lain 404; kasir tanpa izin 403.
        PanggilAksiCepatUji($this, $token, $tenant, 'PUT', "/promo/{$aktif->Uuid}/status", ['Aktif' => true])->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'promo.pulihkan')->count())->toBe(1);
        $kasir = TokenAksiCepatUji($this, $k['Kasir'], 'kata-sandi-uji');
        PanggilAksiCepatUji($this, $kasir, $tenant, 'PUT', "/promo/{$aktif->Uuid}/status", ['Aktif' => false])->assertForbidden();
        $b = BantuanOrganisasi::BuatTenant('Toko B Promo');
        PanggilAksiCepatUji($this, TokenAksiCepatUji($this, $b['Pemilik']), $b['Tenant']->Uuid, 'PUT', "/promo/{$aktif->Uuid}/status", ['Aktif' => false])->assertNotFound();
    });
});

describe('OWN-07 pengeluaran dengan foto nota', function (): void {
    it('opsi memuat kategori kas keluar dan akun kas/bank; pengeluaran terposting (jurnal seimbang), foto nota tersimpan privat', function (): void {
        Storage::fake((string) config('akuntansi.DiskLampiran'));
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(SiapkanKategoriBiayaBawaan::class)->Jalankan();
        $listrik = KategoriKas::query()->where('Nama', 'Listrik')->sole();
        $kas = Akun::query()->where('KasBank', true)->where('Aktif', true)->orderBy('Kode')->first();
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;

        $opsi = PanggilAksiCepatUji($this, $token, $tenant, 'GET', '/pengeluaran/opsi')->assertOk();
        expect(collect($opsi->json('Kategori'))->pluck('Nama')->all())->toContain('Listrik')
            ->and(collect($opsi->json('Sumber'))->pluck('Uuid')->all())->toContain($kas?->Uuid);

        $foto = UploadedFile::fake()->image('nota.jpg', 600, 800)->size(120);
        $respons = PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', [
            'UuidKategori' => $listrik->Uuid,
            'UuidAkunSumber' => $kas?->Uuid,
            'Jumlah' => '350000',
            'Keterangan' => 'Token listrik Oktober',
            'Outlet' => $k['Outlet']->Uuid,
            'Foto' => $foto,
        ])->assertCreated()->assertJsonPath('Pengeluaran.Jumlah', '350000.00')->assertJsonPath('Pengeluaran.BerLampiran', true);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $transaksi = TransaksiKasBank::query()->where('Uuid', $respons->json('Pengeluaran.Uuid'))->sole();
        expect($transaksi->Nomor)->toStartWith('KB/')
            ->and($transaksi->Keterangan)->toBe('Listrik: Token listrik Oktober')
            ->and($transaksi->PathLampiran)->not->toBeNull();
        Storage::disk((string) config('akuntansi.DiskLampiran'))->assertExists((string) $transaksi->PathLampiran);
        $jurnal = Jurnal::query()->where('IdSumber', $transaksi->Id)->first();
        expect($jurnal)->not->toBeNull();
    });

    it('Idempotency-Key yang sama menghasilkan satu pengeluaran; jumlah salah, kategori/akun asing, dan tanpa izin ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(SiapkanKategoriBiayaBawaan::class)->Jalankan();
        $listrik = KategoriKas::query()->where('Nama', 'Listrik')->sole();
        $kas = Akun::query()->where('KasBank', true)->where('Aktif', true)->orderBy('Kode')->firstOrFail();
        $token = TokenAksiCepatUji($this, $k['Pemilik']);
        $tenant = $k['Tenant']->Uuid;
        $badan = ['UuidKategori' => $listrik->Uuid, 'UuidAkunSumber' => $kas->Uuid, 'Jumlah' => '50000', 'Keterangan' => 'Galon'];

        $pertama = PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', $badan, ['Idempotency-Key' => 'kunci-uji-1'])->assertCreated();
        $kedua = PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', $badan, ['Idempotency-Key' => 'kunci-uji-1'])->assertOk();
        expect($kedua->json('Pengeluaran.Uuid'))->toBe($pertama->json('Pengeluaran.Uuid'));
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TransaksiKasBank::query()->count())->toBe(1);

        PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', [...$badan, 'Jumlah' => '0'])->assertStatus(422);
        PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', [...$badan, 'Jumlah' => '12.345'])->assertStatus(422);
        PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', [...$badan, 'UuidKategori' => '01J00000000000000000000000'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'KategoriTidakDikenal');
        PanggilAksiCepatUji($this, $token, $tenant, 'POST', '/pengeluaran', [...$badan, 'UuidAkunSumber' => '01J00000000000000000000000'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'SumberTidakDikenal');
        $kasir = TokenAksiCepatUji($this, $k['Kasir'], 'kata-sandi-uji');
        PanggilAksiCepatUji($this, $kasir, $tenant, 'POST', '/pengeluaran', $badan)->assertForbidden();
        PanggilAksiCepatUji($this, $kasir, $tenant, 'GET', '/pengeluaran/opsi')->assertForbidden();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TransaksiKasBank::query()->count())->toBe(1);
    });

    it('lampiran bukan gambar/dokumen yang diizinkan ditolak tanpa mencatat transaksi', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(SiapkanKategoriBiayaBawaan::class)->Jalankan();
        $listrik = KategoriKas::query()->where('Nama', 'Listrik')->sole();
        $kas = Akun::query()->where('KasBank', true)->where('Aktif', true)->orderBy('Kode')->firstOrFail();
        $token = TokenAksiCepatUji($this, $k['Pemilik']);

        PanggilAksiCepatUji($this, $token, $k['Tenant']->Uuid, 'POST', '/pengeluaran', [
            'UuidKategori' => $listrik->Uuid, 'UuidAkunSumber' => $kas->Uuid, 'Jumlah' => '1000',
            'Foto' => UploadedFile::fake()->create('nota.exe', 10, 'application/octet-stream'),
        ])->assertStatus(422);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TransaksiKasBank::query()->count())->toBe(0);
    });
});
