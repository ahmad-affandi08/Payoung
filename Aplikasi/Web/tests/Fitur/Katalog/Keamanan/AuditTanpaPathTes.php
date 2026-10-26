<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * QA F-03 audit: setiap Aksi yang mengubah katalog mencatat LogAudit, tetapi tanpa path penyimpanan internal
 * (`produk/{IdTenant}/…`, `impor/{IdTenant}/…`, path disk server) maupun rahasia. Gambar dicatat sebagai versi.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    config(['katalog.DiskGambar' => 'local']);
});

it('audit gambar & impor tidak memuat path penyimpanan. Gambar dicatat sebagai VersiGambar', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi.png', 400, 400)])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $versi = PenyimpanGambarProduk::AmbilVersi($produk->refresh());
    $masuk()->delete("/kelola/produk/{$produk->Uuid}/gambar")->assertSessionHasNoErrors();
    BantuanImpor::Unggah($masuk(), BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi Tubruk Robusta']]));

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $ubah = LogAudit::query()->where('Peristiwa', 'produk.gambar.ubah')->sole();
    $hapus = LogAudit::query()->where('Peristiwa', 'produk.gambar.hapus')->sole();
    expect($ubah->NilaiBaru)->toBe(['VersiGambar' => $versi])
        ->and($ubah->NilaiLama)->toBe(['VersiGambar' => null])
        ->and($hapus->NilaiLama)->toBe(['VersiGambar' => $versi]);

    $semua = DB::table('LogAudit')->where('IdTenant', $t['Tenant']->Id)->get(['Peristiwa', 'NilaiLama', 'NilaiBaru']);
    expect($semua->count())->toBeGreaterThanOrEqual(3);

    foreach ($semua as $baris) {
        $isi = (string) $baris->NilaiLama.' '.(string) $baris->NilaiBaru;
        expect($isi)->not->toContain("produk/{$t['Tenant']->Id}/")
            ->and($isi)->not->toContain("impor/{$t['Tenant']->Id}/")
            ->and($isi)->not->toContain(storage_path());
    }
});
