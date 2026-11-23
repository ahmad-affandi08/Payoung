<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;
use App\Domain\Organisasi\Model\StasiunDapur;
use App\Domain\Pemenuhan\Model\TiketDapur;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-310 (F-10 rute dapur): kasir mengatur stasiun dapur/bar tiap kategori dan menu dari aplikasi (izin `dapur.rute.atur`,
 * otomatis dimiliki Kasir), stasiun per menu menimpa kategori, dan printer checker menerima salinan semua tiket tanpa
 * menjadi tujuan KDS/tiket. Berlaku tingkat tenant, idempoten, beraudit, terisolasi antar tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $badan
 */
function AturRute(object $tes, array $k, string $jenis, string $uuid, ?string $uuidStasiun, ?string $uuidPengguna = null): TestResponse
{
    return $tes->withToken($k['Token'])->postJson("/api/pos/v1/dapur/rute/{$jenis}/{$uuid}", [
        'UuidPengguna' => $uuidPengguna ?? $k['Kasir']->Uuid,
        'UuidStasiun' => $uuidStasiun,
    ]);
}

/**
 * Buka pesanan di meja 7 dan kirim satu ronde (kopi + nasi goreng) ke dapur. Mengembalikan stasiun tiap produk.
 *
 * @param  array<string, mixed>  $k
 * @return array<string, int> nama produk → Id stasiun tiket
 */
function KirimDanBacaTiket(object $tes, array $k): array
{
    $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    $ronde = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00'], [$k['Nasi'], '1', '35000.00']]);
    expect(BantuanPesananTerbuka::Kirim($tes, $k, [$buka, $ronde]))->toBe([['Diterima', null], ['Diterima', null]]);

    return TiketDapur::query()->with('Detail')->get()
        ->flatMap(fn (TiketDapur $t) => $t->Detail->map(fn ($d) => [$d->NamaProduk => $t->IdStasiunDapur]))
        ->collapse()->all();
}

describe('D-310 atur stasiun dapur dari kasir', function (): void {
    it('izin dapur.rute.atur otomatis ada di peran Kasir, Supervisor, dan Manajer Outlet, tidak di Pelayan', function (): void {
        foreach ([PeranTenantBawaan::Kasir, PeranTenantBawaan::Supervisor, PeranTenantBawaan::ManajerOutlet] as $peran) {
            expect($peran->AmbilIzin())->toContain(IzinTenant::DapurRuteAtur);
        }

        expect(PeranTenantBawaan::Pelayan->AmbilIzin())->not->toContain(IzinTenant::DapurRuteAtur);
    });

    it('kasir memindahkan kategori ke stasiun lain. Tiket berikutnya mengikuti, idempoten, satu audit', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);

        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $k['Bar']->Uuid)->assertOk()
            ->assertJsonPath('Uuid', $k['Makanan']->Uuid)->assertJsonPath('UuidStasiun', $k['Bar']->Uuid);
        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $k['Bar']->Uuid)->assertOk();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Makanan']->refresh()->IdStasiunDapur)->toBe($k['Bar']->Id)
            ->and(LogAudit::query()->where('Peristiwa', 'dapur.rute-atur')->count())->toBe(1);

        $stasiun = KirimDanBacaTiket($this, $k);
        expect($stasiun)->toBe([
            'Es Kopi Susu Gula Aren Ukuran Besar' => $k['Bar']->Id,
            'Nasi Goreng Kampung Spesial Telur Mata Sapi' => $k['Bar']->Id,
        ]);
    });

    it('stasiun menu menimpa kategorinya. Dikosongkan = kembali ikut kategori', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);

        AturRute($this, $k, 'produk', $k['Nasi']->Uuid, $k['Bar']->Uuid)->assertOk()->assertJsonPath('UuidStasiun', $k['Bar']->Uuid);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KirimDanBacaTiket($this, $k)['Nasi Goreng Kampung Spesial Telur Mata Sapi'])->toBe($k['Bar']->Id);

        AturRute($this, $k, 'produk', $k['Nasi']->Uuid, null)->assertOk()->assertJsonPath('UuidStasiun', null);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Nasi']->refresh()->IdStasiunDapur)->toBeNull();

        $meja = $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk();
        expect($meja->json('ProdukStasiun'))->toBe([]);

        AturRute($this, $k, 'produk', $k['Kopi']->Uuid, $k['Dapur']->Uuid)->assertOk();
        $meja = $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk();
        expect($meja->json('ProdukStasiun'))->toBe([['UuidProduk' => $k['Kopi']->Uuid, 'UuidStasiun' => $k['Dapur']->Uuid]]);
    });

    it('menolak pelaku tanpa izin, pelaku asing, stasiun tak dikenal/arsip/checker, varian anak, dan jasa', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $checker = StasiunDapur::query()->create(['Nama' => 'Checker', 'Salinan' => true]);
        $arsip = StasiunDapur::query()->create(['Nama' => 'Pastry', 'Status' => 'Diarsipkan']);
        $pelayan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Pelayan);
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Combed 30s', 'Jenis' => JenisProduk::IndukVarian]);
        $anak = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Merah', 'IdInduk' => $induk->Id]);
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Pesanan', 'Jenis' => JenisProduk::Jasa]);
        $orangAsing = strtoupper((string) Str::ulid());

        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $k['Bar']->Uuid, $pelayan->Uuid)->assertForbidden();
        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $k['Bar']->Uuid, $orangAsing)->assertStatus(422);
        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $checker->Uuid)->assertNotFound();
        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, $arsip->Uuid)->assertNotFound();
        AturRute($this, $k, 'kategori', $k['Makanan']->Uuid, strtoupper((string) Str::ulid()))->assertNotFound();
        AturRute($this, $k, 'kategori', strtoupper((string) Str::ulid()), $k['Bar']->Uuid)->assertNotFound();
        AturRute($this, $k, 'produk', $anak->Uuid, $k['Bar']->Uuid)->assertStatus(422);
        AturRute($this, $k, 'produk', $jasa->Uuid, $k['Bar']->Uuid)->assertStatus(422);
        AturRute($this, $k, 'produk', $k['Nasi']->Uuid, $checker->Uuid)->assertNotFound();
        $this->flushHeaders();
        $this->postJson('/api/pos/v1/dapur/rute/kategori/'.$k['Makanan']->Uuid, ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidStasiun' => $k['Bar']->Uuid])->assertUnauthorized();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Makanan']->refresh()->IdStasiunDapur)->toBe($k['Dapur']->Id)
            ->and($k['Nasi']->refresh()->IdStasiunDapur)->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'dapur.rute-atur')->count())->toBe(0);
    });

    it('isolasi tenant: perangkat tenant lain tidak bisa mengubah kategori atau menu tenant ini', function (): void {
        $a = BantuanPesananTerbuka::SiapkanRestoran($this);
        $b = BantuanPesananTerbuka::SiapkanRestoran($this);
        $uuidKategoriA = $a['Makanan']->Uuid;
        $uuidProdukA = $a['Nasi']->Uuid;

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        AturRute($this, $b, 'kategori', $uuidKategoriA, $b['Bar']->Uuid)->assertNotFound();
        AturRute($this, $b, 'produk', $uuidProdukA, $b['Bar']->Uuid)->assertNotFound();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($a['Makanan']->refresh()->IdStasiunDapur)->toBe($a['Dapur']->Id)->and($a['Nasi']->refresh()->IdStasiunDapur)->toBeNull();
    });
});

describe('D-310 printer checker (stasiun salinan)', function (): void {
    it('checker tidak menerima tiket KDS, tidak jadi stasiun bawaan, dan ditandai di data meja', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $checker = StasiunDapur::query()->create(['Nama' => 'Checker', 'Urutan' => 0, 'Salinan' => true]);

        $meja = $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk();
        $daftar = collect($meja->json('StasiunDapur'));
        expect($daftar->firstWhere('Uuid', $checker->Uuid)['Salinan'])->toBeTrue()
            ->and($daftar->firstWhere('Uuid', $k['Bar']->Uuid)['Salinan'])->toBeFalse()
            ->and($meja->json('UuidStasiunBawaan'))->toBe($k['Bar']->Uuid);

        $stasiun = KirimDanBacaTiket($this, $k);
        expect($stasiun)->toBe([
            'Es Kopi Susu Gula Aren Ukuran Besar' => $k['Bar']->Id,
            'Nasi Goreng Kampung Spesial Telur Mata Sapi' => $k['Dapur']->Id,
        ])->and(TiketDapur::query()->where('IdStasiunDapur', $checker->Id)->count())->toBe(0);

        $app = app(DaftarStasiunDapur::class);
        expect(collect($app->AmbilOpsiAktif())->pluck('Label')->all())->not->toContain('Checker')
            ->and(collect($app->AmbilSalinanAktif())->pluck('Nama')->all())->toBe(['Checker']);
    });

    it('menandai stasiun sebagai checker ditolak bila masih dipakai kategori atau menu, atau bila satu-satunya stasiun memasak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $bar = StasiunDapur::query()->create(['Nama' => 'Bar', 'Urutan' => 1]);
        $dapur = StasiunDapur::query()->create(['Nama' => 'Dapur', 'Urutan' => 2]);
        $kopi = BantuanKatalog::BuatKategori('Kopi');
        $kopi->forceFill(['IdStasiunDapur' => $bar->Id])->save();

        $this->put("/kelola/stasiun-dapur/{$bar->Uuid}", ['Nama' => 'Bar', 'Salinan' => true])->assertSessionHasErrors();
        expect($bar->refresh()->Salinan)->toBeFalse();

        $kopi->forceFill(['IdStasiunDapur' => null])->save();
        $this->put("/kelola/stasiun-dapur/{$bar->Uuid}", ['Nama' => 'Bar', 'Salinan' => true])->assertSessionHasNoErrors();
        expect($bar->refresh()->Salinan)->toBeTrue();

        // Dapur kini satu-satunya stasiun memasak.
        $this->put("/kelola/stasiun-dapur/{$dapur->Uuid}", ['Nama' => 'Dapur', 'Salinan' => true])->assertSessionHasErrors();
        expect($dapur->refresh()->Salinan)->toBeFalse();

        $this->put("/kelola/stasiun-dapur/{$bar->Uuid}", ['Nama' => 'Bar', 'Salinan' => false])->assertSessionHasNoErrors();
        expect($bar->refresh()->Salinan)->toBeFalse();
    });
});

describe('D-310 stasiun menu dari back-office', function (): void {
    it('Pemilik mengatur stasiun menu dari detail produk. Panel memuat pilihan dan stasiun kategorinya', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja');
        $bar = StasiunDapur::query()->create(['Nama' => 'Bar', 'Urutan' => 1]);
        $dapur = StasiunDapur::query()->create(['Nama' => 'Dapur', 'Urutan' => 2]);
        $kategori = BantuanKatalog::BuatKategori('Minuman');
        $kategori->forceFill(['IdStasiunDapur' => $bar->Id])->save();
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis Jumbo']);
        $produk->forceFill(['IdKategori' => $kategori->Id])->save();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $this->get("/kelola/produk/{$produk->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('StasiunDapur.UuidStasiun', null)
            ->where('StasiunDapur.NamaStasiunKategori', 'Bar')
            ->has('StasiunDapur.Opsi', 2));

        $this->post("/kelola/produk/{$produk->Uuid}/stasiun-dapur", ['UuidStasiun' => $dapur->Uuid])->assertSessionHasNoErrors()->assertRedirect();
        expect($produk->refresh()->IdStasiunDapur)->toBe($dapur->Id);
        $this->post("/kelola/produk/{$produk->Uuid}/stasiun-dapur", ['UuidStasiun' => $dapur->Uuid])->assertSessionHasNoErrors();
        expect(LogAudit::query()->where('Peristiwa', 'dapur.rute-atur')->count())->toBe(1);

        $this->post("/kelola/produk/{$produk->Uuid}/stasiun-dapur", ['UuidStasiun' => null])->assertSessionHasNoErrors();
        expect($produk->refresh()->IdStasiunDapur)->toBeNull();
    });

    it('halaman detail tanpa stasiun memasak tidak menampilkan panel', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Kelontong');
        $produk = BantuanKatalog::BuatProduk();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $this->get("/kelola/produk/{$produk->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('StasiunDapur', null));
    });

    it('peran tanpa produk.kelola ditolak dan tenant lain tidak bisa menyentuh produknya', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Kedai A');
        $stasiun = StasiunDapur::query()->create(['Nama' => 'Dapur']);
        $produk = BantuanKatalog::BuatProduk();

        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post("/kelola/produk/{$produk->Uuid}/stasiun-dapur", ['UuidStasiun' => $stasiun->Uuid])->assertForbidden();

        $b = BantuanKatalog::SiapkanTenantProduk('Kedai B');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $this->post("/kelola/produk/{$produk->Uuid}/stasiun-dapur", ['UuidStasiun' => null])->assertNotFound();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($produk->refresh()->IdStasiunDapur)->toBeNull();
    });
});
