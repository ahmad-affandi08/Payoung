<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit E5 (UU PDP): ekspor data lengkap usaha, ZIP berisi CSV per tabel; tanpa rahasia, terisolasi per tenant,
 * hanya Pemilik, kedaluwarsa otomatis.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/** @return array<string, string> isi berkas ZIP ekspor, kunci = nama berkas */
function BacaZipEksporUji(EksporDataTenant $ekspor): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path((string) $ekspor->PathBerkas)))->toBeTrue();
    $isi = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $isi[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $isi;
}

describe('E5 ekspor data lengkap usaha', function (): void {
    it('Pemilik meminta ekspor: ZIP berisi CSV tiap tabel data usaha, profil, anggota, dan petunjuk, tanpa rahasia dan data tenant lain', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Sumber Rejeki Sragen');
        BantuanKatalog::BuatProduk(['Nama' => 'Kopi Tubruk Robusta Temanggung 250 gram'], '35000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => '=HYPERLINK("http://jahat.example","klik")'], '1000.00', $t['Pcs']);
        $lain = BantuanKatalog::SiapkanTenantProduk('Warung Bakso Pak Kumis');
        BantuanKatalog::BuatProduk(['Nama' => 'Bakso Urat Spesial Pak Kumis'], '20000.00', $lain['Pcs']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/pengaturan/data-saya/ekspor')->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $ekspor = EksporDataTenant::query()->sole();
        expect($ekspor->Status)->toBe(StatusEksporDataTenant::Siap)
            ->and($ekspor->JumlahTabel)->toBeGreaterThan(20)
            ->and($ekspor->JumlahBaris)->toBeGreaterThan(0)
            ->and($ekspor->KedaluwarsaPada?->isFuture())->toBeTrue();
        $isi = BacaZipEksporUji($ekspor);

        expect($isi)->toHaveKeys(['BACA-SAYA.txt', 'Tenant.csv', 'Anggota.csv', 'Produk.csv', 'Outlet.csv'])
            ->and($isi['Produk.csv'])->toContain('Kopi Tubruk Robusta Temanggung 250 gram')
            ->and($isi['Produk.csv'])->toContain("'=HYPERLINK")
            ->and($isi['Produk.csv'])->not->toContain('Bakso Urat Spesial Pak Kumis')
            ->and($isi['Anggota.csv'])->toContain((string) $t['Pemilik']->Email)
            ->and($isi['BACA-SAYA.txt'])->toContain('Produk.csv')->toContain('Tidak ikut diekspor');

        // Tabel pembawa rahasia tidak ikut, kolom rahasia dibuang dari tabel lain.
        foreach (['TenantPengguna.csv', 'GerbangPembayaranTenant.csv', 'TokenApiTenant.csv', 'WajahKaryawan.csv', 'KunciIdempotensi.csv'] as $terlarang) {
            expect($isi)->not->toHaveKey($terlarang);
        }

        $semuaHeader = collect($isi)->filter(fn ($v, $k) => str_ends_with($k, '.csv'))->map(fn (string $v): string => strtok(ltrim($v, "\xEF\xBB\xBF"), "\n") ?: '')->implode(',');
        expect($semuaHeader)->not->toMatch('/HashToken|TokenPush|HashPin|KataSandi|Kredensial|SidikWajah/');
        expect(DB::table('LogAudit')->where('Peristiwa', 'data.ekspor-minta')->count())->toBe(1)
            ->and(DB::table('LogAudit')->where('Peristiwa', 'data.ekspor-siap')->count())->toBe(1);
    });

    it('halaman Data saya dan unduhan: hanya Pemilik. Unduh mencatat waktu. Tenant lain tidak bisa mengunduh', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Sumber Rejeki Sragen');
        $kasir = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/pengaturan/data-saya/ekspor')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $uuid = EksporDataTenant::query()->sole()->Uuid;

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get('/kelola/pengaturan/data-saya')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pengaturan/DataSaya')->where('Ekspor.0.Status', 'Siap')->where('Ekspor.0.BisaDiunduh', true));

        // Kasir dan Admin (izin khusus Pemilik) ditolak.
        BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->get('/kelola/pengaturan/data-saya')->assertForbidden();
        BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->post('/kelola/pengaturan/data-saya/ekspor')->assertForbidden();
        BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->get("/kelola/pengaturan/data-saya/ekspor/{$uuid}/unduh")->assertForbidden();

        $unduh = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get("/kelola/pengaturan/data-saya/ekspor/{$uuid}/unduh");
        $unduh->assertOk()->assertHeader('Content-Type', 'application/zip');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(EksporDataTenant::query()->sole()->DiunduhPada)->not->toBeNull()
            ->and(DB::table('LogAudit')->where('Peristiwa', 'data.ekspor-unduh')->count())->toBe(1);

        $lain = BantuanKatalog::SiapkanTenantProduk('Warung Bakso Pak Kumis');
        BantuanKatalog::MasukSebagai($this, $lain['Tenant']->Id)->get("/kelola/pengaturan/data-saya/ekspor/{$uuid}/unduh")->assertNotFound();
    });

    it('batas permintaan: satu ekspor per 24 jam. Ekspor gagal tidak menahan. Ekspor yang sedang berjalan menolak permintaan kedua', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Sumber Rejeki Sragen');
        $minta = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/pengaturan/data-saya/ekspor');

        $minta()->assertSessionHasNoErrors();
        $minta()->assertSessionHasErrors('Umum');
        expect(EksporDataTenant::query()->withoutGlobalScopes()->count())->toBe(1);

        // Setelah jeda berlalu, permintaan baru boleh.
        $this->travelTo(now()->addHours(25));
        $minta()->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(EksporDataTenant::query()->count())->toBe(2);

        // Ekspor gagal tidak menghalangi permintaan berikutnya; yang masih berjalan menghalangi.
        EksporDataTenant::query()->update(['Status' => StatusEksporDataTenant::Gagal]);
        EksporDataTenant::query()->orderByDesc('Id')->first()?->update(['Status' => StatusEksporDataTenant::Berjalan]);
        $minta()->assertSessionHasErrors('Umum');
        EksporDataTenant::query()->update(['Status' => StatusEksporDataTenant::Gagal]);
        $minta()->assertSessionHasNoErrors();
    });

    it('perintah ekspor-data:bersihkan menghapus berkas kedaluwarsa dan menutup akses unduh', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Sumber Rejeki Sragen');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/pengaturan/data-saya/ekspor')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $ekspor = EksporDataTenant::query()->sole();
        $path = (string) $ekspor->PathBerkas;
        Storage::disk('local')->assertExists($path);

        $this->travelTo(now()->addDays(8));
        $this->artisan('ekspor-data:bersihkan')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($ekspor->refresh()->Status)->toBe(StatusEksporDataTenant::Kedaluwarsa)->and($ekspor->PathBerkas)->toBeNull();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get("/kelola/pengaturan/data-saya/ekspor/{$ekspor->Uuid}/unduh")->assertNotFound();
    });
});
