<?php

declare(strict_types=1);

use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PengukurPenyimpanan;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit E2: `BatasPenyimpananMb` paket ditegakkan pada unggahan yang boleh ditolak (gambar produk, lampiran), diukur dari
 * berkas nyata per tenant, dan diumumkan di Kotak Tindakan saat hampir penuh.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    config(['katalog.DiskGambar' => 'local']);
});

describe('E2 penyimpanan berkas per tenant', function (): void {
    it('pengukur menjumlah berkas di folder tenant itu saja. Unggahan berhasil menambah pemakaian', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $lain = BantuanKatalog::SiapkanTenantProduk();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi.png', 1200, 900)])->assertSessionHasNoErrors();
        Storage::disk('local')->put("kasir/bukti-kas/{$t['Tenant']->Id}/a.jpg", str_repeat('x', 1000));
        Storage::disk('local')->put("karyawan/swafoto/{$t['Tenant']->Id}/b.jpg", str_repeat('x', 500));
        Storage::disk('local')->put("pembelian/faktur/{$t['Tenant']->Id}/c.pdf", str_repeat('x', 250));
        Storage::disk('local')->put("kasir/bukti-kas/{$lain['Tenant']->Id}/z.jpg", str_repeat('x', 99999));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pengukur = app(PengukurPenyimpanan::class);
        $gambar = (int) Storage::disk('local')->size((string) $produk->refresh()->PathGambar) + (int) Storage::disk('local')->size(str_replace('.webp', '-kecil.webp', (string) $produk->PathGambar));
        $dicatat = PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->value('Byte');

        expect($dicatat)->toBeGreaterThan(0)
            ->and($pengukur->HitungByte($t['Tenant']->Id))->toBeGreaterThanOrEqual(1750 + 1)
            ->and($pengukur->HitungByte($t['Tenant']->Id))->toBeLessThan(1750 + $gambar + 4096)
            ->and($pengukur->Segarkan($t['Tenant']->Id)->DiukurPada)->not->toBeNull();
    });

    it('Catat menambah secara atomik: baris pertama dibuat sekali, penambahan berikutnya menumpuk, nol tidak mengubah apa pun', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penjaga = app(PenjagaPenyimpanan::class);

        $penjaga->Catat($t['Tenant']->Id, 0);
        expect(PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->count())->toBe(0);

        $penjaga->Catat($t['Tenant']->Id, 1500);
        $penjaga->Catat($t['Tenant']->Id, 2500);
        $penjaga->Catat($t['Tenant']->Id, -10);

        expect(PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->count())->toBe(1)
            ->and($penjaga->AmbilByte($t['Tenant']->Id))->toBe(4000);
    });

    it('hasil pindaian tidak menghapus unggahan yang masuk selama pindaian berlangsung', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penjaga = app(PenjagaPenyimpanan::class);
        $pengukur = app(PengukurPenyimpanan::class);

        $penjaga->Catat($t['Tenant']->Id, 3000);
        $byteAwal = $penjaga->AmbilByte($t['Tenant']->Id);
        // Pindaian dimulai, lalu unggahan 700 byte selesai sebelum hasilnya ditulis.
        $penjaga->Catat($t['Tenant']->Id, 700);
        $hasil = $pengukur->TerapkanHasil($t['Tenant']->Id, $byteAwal, 3000);

        expect($hasil->Byte)->toBe(3700)
            ->and($penjaga->AmbilByte($t['Tenant']->Id))->toBe(3700)
            ->and($hasil->DiukurPada)->not->toBeNull();
    });

    it('unggahan gambar produk ditolak PenyimpananPenuh bila melewati batas paket, tanpa batas selalu lolos', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $batasMb = app(PemeriksaFiturTenant::class)->AmbilBatasEfektif($t['Tenant']->Id, 'BatasPenyimpananMb');
        expect($batasMb)->not->toBeNull();

        PemakaianPenyimpanan::query()->updateOrCreate(['IdTenant' => $t['Tenant']->Id], ['Byte' => $batasMb * 1048576 - 10]);
        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi.png', 600, 600)])
            ->assertSessionHasErrors('Umum');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($produk->refresh()->PathGambar)->toBeNull();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->update(['Byte' => 0]);
        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi.png', 600, 600)])
            ->assertSessionHasNoErrors();
    });

    it('Kotak Tindakan: hampir penuh (≥ 90%) = Perhatian, penuh = Penting, hanya bagi pemegang langganan.kelola', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $batasMb = (int) app(PemeriksaFiturTenant::class)->AmbilBatasEfektif($t['Tenant']->Id, 'BatasPenyimpananMb');
        $ambil = function () use ($t) {
            $butir = [];
            BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get('/kelola/tindakan')->assertOk()
                ->assertInertia(function (AssertableInertia $h) use (&$butir) {
                    $butir = $h->toArray()['props']['Butir'];

                    return $h;
                });

            return collect($butir)->firstWhere('Kunci', 'penyimpanan.penuh');
        };

        expect($ambil())->toBeNull();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        PemakaianPenyimpanan::query()->create(['IdTenant' => $t['Tenant']->Id, 'Byte' => (int) ($batasMb * 1048576 * 0.95)]);
        expect($ambil()['Tingkat'] ?? null)->toBe('Perhatian');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->update(['Byte' => $batasMb * 1048576]);
        expect($ambil()['Tingkat'] ?? null)->toBe('Penting');
    });

    it('perintah penyimpanan:ukur --semua mengisi pemakaian semua tenant', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        Storage::disk('local')->put("kasir/bukti-kas/{$t['Tenant']->Id}/a.jpg", str_repeat('x', 2048));

        $this->artisan('penyimpanan:ukur', ['--semua' => true])->assertSuccessful();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect((int) PemakaianPenyimpanan::query()->where('IdTenant', $t['Tenant']->Id)->value('Byte'))->toBeGreaterThanOrEqual(2048);
    });

    it('perintah penyimpanan:ukur membaca berantai: tenant di luar halaman pertama tetap diukur ulang', function (): void {
        $idTenant = [];

        foreach (['Toko Penyimpanan Satu', 'Toko Penyimpanan Dua', 'Toko Penyimpanan Tiga'] as $nama) {
            $t = BantuanKatalog::SiapkanTenantProduk($nama);
            Storage::disk('local')->put("kasir/bukti-kas/{$t['Tenant']->Id}/a.jpg", str_repeat('x', 4096));
            BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
            // Sudah pernah diukur (punya baris), tetapi angkanya basi: berkas sebenarnya lebih besar.
            PemakaianPenyimpanan::query()->create(['IdTenant' => $t['Tenant']->Id, 'Byte' => 1]);
            $idTenant[] = $t['Tenant']->Id;
        }

        // Halaman baca hanya 2 tenant: tanpa pembacaan berantai, tenant ketiga tidak pernah terjangkau.
        $this->artisan('penyimpanan:ukur', ['--halaman' => 2])->expectsOutput('3 tenant diukur penyimpanannya.')->assertSuccessful();

        foreach ($idTenant as $id) {
            BantuanOrganisasi::AturKonteks($id);
            expect((int) PemakaianPenyimpanan::query()->where('IdTenant', $id)->value('Byte'))->toBeGreaterThanOrEqual(4096);
        }
    });
});
