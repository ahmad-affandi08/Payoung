<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusLangkahPanduan;
use App\Domain\PanduanAwal\Kueri\ProgresPanduan;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-23 A "mulai jualan dalam 5 menit": satu klik di langkah Sektor menerapkan template, mengonfirmasi pajak sesuai
 * usulan, menambah semua produk contoh (sebatas kuota), dan menandai langkah Produk & Metode pembayaran selesai.
 * Diulang = tidak menggandakan produk; kota kosong padahal PBJT diusulkan = pajak dibiarkan untuk diisi manual.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

function JumlahProdukContohTemplate(string $kode): int
{
    return count(app(PembacaIsiTemplate::class)->Baca(BantuanPanduanAwal::TerbitkanTemplate($kode)->Isi)->produkContoh);
}

it('satu klik: template, pajak, produk contoh, dan metode bayar siap. Langsung ke langkah Perangkat. Diulang tidak menggandakan', function (): void {
    $jumlahContoh = JumlahProdukContohTemplate('FNB-CAF');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant('Kopi Nusantara Laweyan Cepat');
    Outlet::query()->whereKey($outlet->Id)->update(['KodeKota' => '33.72']);
    BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/panduan-awal/perangkat');

    BantuanOrganisasi::AturKonteks($tenant->Id);
    $progres = app(ProgresPanduan::class);
    expect($jumlahContoh)->toBeGreaterThan(0)
        ->and(Produk::query()->count())->toBe($jumlahContoh)
        ->and($progres->AmbilStatus(LangkahPanduan::Sektor))->toBe(StatusLangkahPanduan::Selesai)
        ->and($progres->AmbilStatus(LangkahPanduan::Pajak))->toBe(StatusLangkahPanduan::Selesai)
        ->and($progres->AmbilStatus(LangkahPanduan::Produk))->toBe(StatusLangkahPanduan::Selesai)
        ->and($progres->AmbilStatus(LangkahPanduan::MetodePembayaran))->toBe(StatusLangkahPanduan::Selesai)
        ->and($progres->AmbilStatus(LangkahPanduan::Perangkat))->toBe(StatusLangkahPanduan::Belum)
        ->and(Outlet::query()->whereKey($outlet->Id)->sole()->IdTemplateSektorVersi)->not->toBeNull();

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(Produk::query()->count())->toBe($jumlahContoh);
});

it('kota belum diisi padahal PBJT diusulkan: pajak dibiarkan untuk diisi manual. Kuota SKU hampir penuh: produk contoh sebatas sisa kuota', function (): void {
    $jumlahContoh = JumlahProdukContohTemplate('FNB-CAF');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant('Kopi Tanpa Kota Cepat', 'GRATIS');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $batas = (int) app(PastikanBatasPaket::class)->AmbilRingkasan($tenant->Id, 'BatasSku', 0)['Batas'];
    $sisa = 5;

    for ($i = 0; $i < $batas - $sisa; $i++) {
        BantuanKatalog::BuatProduk(['Nama' => "Produk Lama Pengisi Kuota Nomor {$i}"]);
    }

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/panduan-awal/perangkat')
        ->assertSessionHas('Kilat', fn (string $pesan): bool => str_contains($pesan, 'Periksa pengaturan pajak di langkah Pajak.')
            && str_contains($pesan, "{$sisa} produk contoh ditambahkan")
            && str_contains($pesan, ($jumlahContoh - $sisa).' produk contoh tidak ditambahkan karena kuota paket penuh'));

    BantuanOrganisasi::AturKonteks($tenant->Id);
    $progres = app(ProgresPanduan::class);
    expect(Produk::query()->count())->toBe($batas)
        ->and($progres->AmbilStatus(LangkahPanduan::Pajak))->toBe(StatusLangkahPanduan::Belum)
        ->and($progres->AmbilStatus(LangkahPanduan::MetodePembayaran))->toBe(StatusLangkahPanduan::Selesai);
});

it('tenant baru (panduan wajib): siapkan otomatis langsung menyelesaikan panduan, atau mengarahkan ke satu-satunya langkah yang kurang', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Otomatis Wajib', panduanWajib: true);
    $masuk = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $progres = ProgresPanduanAwal::query()->sole();
    $progres->StatusLangkah = [...($progres->StatusLangkah ?? []), 'ProfilUsaha' => ['Status' => 'Selesai', 'Pada' => now()->toIso8601ZuluString()]];
    $progres->save();

    // Kota belum diisi → pajak PBJT tidak bisa dikonfirmasi otomatis: diarahkan ke langkah Pajak, panduan belum selesai.
    BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);
    $masuk()->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertRedirect('/kelola/panduan-awal/pajak')
        ->assertSessionHas('Kilat', fn (string $pesan): bool => str_contains($pesan, 'Tinggal satu hal: Pajak.'));
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->SelesaiPada)->toBeNull();

    // Kota diisi → semua langkah wajib beres sekaligus: panduan selesai, back-office terbuka.
    Outlet::query()->update(['KodeKota' => '33.72']);
    $masuk()->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertRedirect('/kelola/panduan-awal/perangkat')
        ->assertSessionHas('Kilat', fn (string $pesan): bool => str_contains($pesan, 'Toko siap berjualan.'));
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->SelesaiPada)->not->toBeNull();
    $masuk()->get('/kelola/produk')->assertOk();
});
