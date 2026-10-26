<?php

declare(strict_types=1);

use App\Domain\Pengelola\Konten\Aksi\SiapkanArtikelBantuanBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Kueri\BantuanKontekstual;
use App\Domain\Situs\Layanan\AturanSlugSitus;
use App\Domain\Situs\Model\ArtikelBantuan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PLT-12 (v6.02) Pusat Bantuan: konsol (buat draf, simpan, terbitkan, tarik, hapus, izin, audit, validasi konteks & video),
 * publik `/pusat-bantuan` (hanya terbit, cari, kategori, artikel terkait, peta situs) dan bantuan kontekstual di
 * back-office (artikel menurut awalan jalur halaman, paling spesifik dulu; draf tidak pernah bocor).
 */

function MasukKontenBantuan(PeranPengelolaBawaan $peran = PeranPengelolaBawaan::KontenLegal): void
{
    test()->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @param array<string, mixed> $timpa */
function BuatArtikelBantuanUji(array $timpa = []): ArtikelBantuan
{
    $a = new ArtikelBantuan;
    $a->forceFill([
        'Slug' => 'cara-tambah-produk',
        'Judul' => 'Cara menambah produk',
        'Ringkasan' => 'Langkah menambah produk pertama.',
        'Isi' => "## Langkah\n\nBuka menu Produk lalu tekan Tambah produk.",
        'Kategori' => 'Katalog',
        'Konteks' => ['/kelola/produk'],
        'Urutan' => 0,
        'Status' => StatusArtikel::Terbit,
        'DiterbitkanPada' => now(),
        ...$timpa,
    ])->save();

    return $a;
}

function UrlPublikBantuan(string $jalur): string
{
    return rtrim((string) config('app.url'), '/').$jalur;
}

describe('konsol artikel bantuan', function (): void {
    it('Konten & Legal menulis draf, menyimpan dengan konteks dan video, lalu menerbitkan. Audit tercatat', function (): void {
        MasukKontenBantuan();
        $this->post(BantuanPengelola::Url('/situs/bantuan'), ['Judul' => 'Cara Buka Shift Kasir!'])->assertRedirect();

        $a = ArtikelBantuan::query()->sole();
        expect($a->Slug)->toBe('cara-buka-shift-kasir')->and($a->Status)->toBe(StatusArtikel::Draf)->and($a->Konteks)->toBe([]);

        $this->get(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}"))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Bantuan/Ubah')
            ->where('Artikel.Slug', 'cara-buka-shift-kasir')
            ->where('Izin.Kelola', true));

        $this->post(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}/terbitkan"))->assertSessionHasErrors('Isi');

        $this->put(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}"), [
            'Judul' => 'Cara buka shift kasir', 'Slug' => 'buka-shift', 'Ringkasan' => 'Hitung kas awal lalu mulai berjualan.',
            'Isi' => "Tekan Buka shift.\n\n- Isi kas awal", 'Kategori' => 'Kasir', 'Urutan' => 3,
            'UrlVideo' => 'https://www.youtube.com/watch?v=abc123',
            'Konteks' => ['/kelola/shift', '/KELOLA/shift/', '/kelola/laporan/shift'],
        ])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}/terbitkan"))->assertSessionHasNoErrors();

        $a->refresh();
        expect($a->Slug)->toBe('buka-shift')->and($a->Status)->toBe(StatusArtikel::Terbit)->and($a->DiterbitkanPada)->not->toBeNull()
            ->and($a->Konteks)->toBe(['/kelola/shift', '/kelola/laporan/shift'])
            ->and($a->Urutan)->toBe(3);
        expect(LogAuditPengelola::query()->whereIn('Aksi', ['situs.bantuan.buat', 'situs.bantuan.ubah', 'situs.bantuan.terbitkan'])->count())->toBe(3);

        $this->get(BantuanPengelola::Url('/situs/bantuan'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Bantuan/Daftar')
            ->where('Artikel.Data.0.Judul', 'Cara buka shift kasir')
            ->where('Artikel.Data.0.JumlahKonteks', 2)
            ->where('PilihanKategori', ['Kasir']));
    });

    it('slug unik dan valid. Video harus https YouTube/Vimeo. Konteks hanya jalur /kelola (maks 10)', function (): void {
        MasukKontenBantuan();
        BuatArtikelBantuanUji();
        $a = BuatArtikelBantuanUji(['Slug' => 'lain', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);
        $isi = ['Judul' => 'Lain', 'Isi' => 'Isi.'];
        $alamat = BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}");

        $this->put($alamat, [...$isi, 'Slug' => 'cara-tambah-produk'])->assertSessionHasErrors('Slug');
        $this->put($alamat, [...$isi, 'Slug' => 'Bukan Slug/2'])->assertSessionHasErrors('Slug');
        $this->put($alamat, [...$isi, 'UrlVideo' => 'http://www.youtube.com/watch?v=1'])->assertSessionHasErrors('UrlVideo');
        $this->put($alamat, [...$isi, 'UrlVideo' => 'https://contoh.example/video.mp4'])->assertSessionHasErrors('UrlVideo');
        $this->put($alamat, [...$isi, 'UrlVideo' => 'https://user:pass@youtube.com/watch?v=1'])->assertSessionHasErrors('UrlVideo');
        $this->put($alamat, [...$isi, 'Konteks' => ['/pengelola/tenant']])->assertSessionHasErrors('Konteks');
        $this->put($alamat, [...$isi, 'Konteks' => ['/kelola/../masuk']])->assertSessionHasErrors('Konteks');
        $this->put($alamat, [...$isi, 'Konteks' => array_map(fn (int $i): string => "/kelola/halaman-{$i}", range(1, 11))])->assertSessionHasErrors('Konteks');
        $this->put($alamat, [...$isi, 'UrlVideo' => 'https://vimeo.com/123456', 'Konteks' => ['/kelola']])->assertSessionHasNoErrors();
    });

    it('artikel terbit harus ditarik dulu sebelum dihapus; Dukungan ditolak melihat konsol bantuan. Slug terlarang di situs', function (): void {
        $a = BuatArtikelBantuanUji();

        MasukKontenBantuan(PeranPengelolaBawaan::Dukungan);
        $this->get(BantuanPengelola::Url('/situs/bantuan'))->assertForbidden();

        MasukKontenBantuan();
        $this->delete(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}"))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}/tarik"))->assertSessionHasNoErrors();
        expect($a->refresh()->Status)->toBe(StatusArtikel::Draf);

        $this->delete(BantuanPengelola::Url("/situs/bantuan/{$a->Uuid}"))->assertRedirect();
        expect(ArtikelBantuan::query()->count())->toBe(0)
            ->and(AturanSlugSitus::Periksa('pusat-bantuan'))->not->toBeNull()
            ->and(in_array('pusat-bantuan', (array) config('tenant.SlugTerlarang'), true))->toBeTrue();
    });
});

describe('Pusat Bantuan publik', function (): void {
    beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

    it('hanya artikel terbit. Draf 404. Dikelompokkan per kategori. Cari dan filter kategori', function (): void {
        BuatArtikelBantuanUji();
        BuatArtikelBantuanUji(['Slug' => 'cara-buka-shift', 'Judul' => 'Cara buka shift', 'Kategori' => 'Kasir', 'Isi' => 'Hitung kas awal laci.', 'Ringkasan' => null, 'Urutan' => 1]);
        BuatArtikelBantuanUji(['Slug' => 'draf-rahasia', 'Judul' => 'Draf rahasia', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);

        $this->get(UrlPublikBantuan('/pusat-bantuan'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/PusatBantuan')
            ->has('Kelompok', 2)
            ->where('Kelompok.0.Kategori', 'Kasir')
            ->where('Kelompok.1.Artikel.0.Slug', 'cara-tambah-produk')
            ->where('Kategori', ['Kasir', 'Katalog'])
            ->where('Total', 2)
            ->where('Halaman.Seo.TanpaIndeks', false));

        // Cari menjangkau isi artikel; hasil cari/saring tidak diindeks mesin pencari.
        $this->get(UrlPublikBantuan('/pusat-bantuan?cari=kas+awal'))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Total', 1)->where('Kelompok.0.Artikel.0.Slug', 'cara-buka-shift')->where('Halaman.Seo.TanpaIndeks', true));
        $this->get(UrlPublikBantuan('/pusat-bantuan?kategori=Katalog'))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Total', 1)->where('KategoriAktif', 'Katalog'));
        $this->get(UrlPublikBantuan('/pusat-bantuan?cari=%25'))->assertInertia(fn (AssertableInertia $h) => $h->where('Total', 0));
        $this->get(UrlPublikBantuan('/pusat-bantuan?cari=draf+rahasia'))->assertInertia(fn (AssertableInertia $h) => $h->where('Total', 0));

        $this->get(UrlPublikBantuan('/pusat-bantuan/draf-rahasia'))->assertNotFound();
        $this->get(UrlPublikBantuan('/pusat-bantuan/tidak-ada'))->assertNotFound();
    });

    it('halaman artikel: isi, tautan video, terkait sekategori, meta kanonik. Hasil cari noindex di HTML', function (): void {
        BuatArtikelBantuanUji(['UrlVideo' => 'https://youtu.be/abc123']);
        BuatArtikelBantuanUji(['Slug' => 'cara-ubah-harga', 'Judul' => 'Cara ubah harga', 'Urutan' => 1]);
        BuatArtikelBantuanUji(['Slug' => 'cara-buka-shift', 'Judul' => 'Cara buka shift', 'Kategori' => 'Kasir']);

        $respons = $this->get(UrlPublikBantuan('/pusat-bantuan/cara-tambah-produk'));
        $respons->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/ArtikelBantuan')
            ->where('Artikel.Judul', 'Cara menambah produk')
            ->where('Artikel.UrlVideo', 'https://youtu.be/abc123')
            ->where('Artikel.Isi', fn ($isi) => str_contains((string) $isi, 'Tambah produk'))
            ->has('Terkait', 1)
            ->where('Terkait.0.Slug', 'cara-ubah-harga'));
        expect($respons->getContent())->toContain('<title inertia>Cara menambah produk | Pusat Bantuan Payoung</title>')
            ->toContain('rel="canonical"')
            ->not->toContain('noindex');

        $cari = $this->get(UrlPublikBantuan('/pusat-bantuan?cari=produk'));
        expect($cari->getContent())->toContain('<meta name="robots" content="noindex, follow">');
    });

    it('peta situs memuat /pusat-bantuan dan artikel terbit saja', function (): void {
        BuatArtikelBantuanUji();
        BuatArtikelBantuanUji(['Slug' => 'draf-rahasia', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);

        $xml = (string) $this->get(UrlPublikBantuan('/peta-situs'))->assertOk()->getContent();
        expect($xml)->toContain('/pusat-bantuan</loc>')->toContain('/pusat-bantuan/cara-tambah-produk</loc>')->not->toContain('draf-rahasia');
    });
});

describe('bantuan kontekstual di back-office', function (): void {
    beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

    it('mencocokkan awalan jalur (paling spesifik dulu) dan tidak membocorkan draf', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        BuatArtikelBantuanUji(['Slug' => 'umum', 'Judul' => 'Mulai memakai Payoung', 'Konteks' => ['/kelola'], 'Urutan' => 0]);
        BuatArtikelBantuanUji(['Slug' => 'produk', 'Judul' => 'Mengelola produk', 'Konteks' => ['/kelola/produk'], 'Urutan' => 1]);
        BuatArtikelBantuanUji(['Slug' => 'label', 'Judul' => 'Cetak label harga', 'Konteks' => ['/kelola/produk/label'], 'Urutan' => 2, 'UrlVideo' => 'https://youtu.be/xyz']);
        BuatArtikelBantuanUji(['Slug' => 'stok', 'Judul' => 'Mengelola stok', 'Konteks' => ['/kelola/persediaan'], 'Urutan' => 3]);
        BuatArtikelBantuanUji(['Slug' => 'draf', 'Judul' => 'Draf rahasia', 'Konteks' => ['/kelola/produk'], 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);

        $respons = $masuk()->getJson('/kelola/pusat-bantuan?jalur='.urlencode('/kelola/produk/label'))->assertOk();

        expect(array_column($respons->json('Halaman'), 'Slug'))->toBe(['label', 'produk', 'umum'])
            ->and(array_column($respons->json('Lainnya'), 'Slug'))->toBe(['stok'])
            ->and($respons->json('Halaman.0.UrlVideo'))->toBe('https://youtu.be/xyz')
            ->and($respons->json('Halaman.0.Url'))->toEndWith('/pusat-bantuan/label')
            ->and($respons->json('UrlPusatBantuan'))->toEndWith('/pusat-bantuan')
            ->and($respons->getContent())->not->toContain('Draf rahasia');

        // /kelola/produksi bukan bagian dari /kelola/produk.
        $lain = $masuk()->getJson('/kelola/pusat-bantuan?jalur='.urlencode('/kelola/produksi'))->assertOk();
        expect(array_column($lain->json('Halaman'), 'Slug'))->toBe(['umum']);
    });

    it('cari menampilkan hasil menurut judul/ringkasan. Jalur rusak jatuh ke /kelola. Wajib masuk', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        BuatArtikelBantuanUji(['Slug' => 'umum', 'Judul' => 'Mulai memakai Payoung', 'Konteks' => ['/kelola']]);
        BuatArtikelBantuanUji(['Slug' => 'stok', 'Judul' => 'Mengelola stok gudang', 'Konteks' => ['/kelola/persediaan']]);

        $hasil = $masuk()->getJson('/kelola/pusat-bantuan?cari=gudang')->assertOk();
        expect($hasil->json('Halaman'))->toBe([])->and(array_column($hasil->json('Lainnya'), 'Slug'))->toBe(['stok']);

        $rusak = $masuk()->getJson('/kelola/pusat-bantuan?jalur='.urlencode('/pengelola/../etc/passwd'))->assertOk();
        expect(array_column($rusak->json('Halaman'), 'Slug'))->toBe(['umum'])
            ->and(BantuanKontekstual::RapikanJalur('/kelola/Produk/?a=1#x'))->toBe('/kelola/produk')
            ->and(BantuanKontekstual::HitungKecocokan(['/kelola/produk'], '/kelola/produksi'))->toBe(0)
            ->and(BantuanKontekstual::HitungKecocokan(['/kelola', '/kelola/produk'], '/kelola/produk/label'))->toBe(14);

        $this->flushSession();
        $this->getJson('/kelola/pusat-bantuan')->assertRedirect();
    });
});

describe('artikel bantuan awal', function (): void {
    it('perintah membuat artikel terbit yang belum ada, tidak menimpa yang sudah disunting, dan idempoten', function (): void {
        $sunting = BuatArtikelBantuanUji(['Slug' => 'stok-opname', 'Judul' => 'Opname versi saya', 'Isi' => 'Isi saya.']);

        expect(Artisan::call('bantuan:siapkan-bawaan'))->toBe(0)
            ->and(Artisan::output())->toContain('artikel dibuat');

        $jumlah = count(SiapkanArtikelBantuanBawaan::Daftar());
        expect(ArtikelBantuan::query()->count())->toBe($jumlah)
            ->and(ArtikelBantuan::query()->where('Status', StatusArtikel::Terbit->value)->whereNotNull('DiterbitkanPada')->count())->toBe($jumlah)
            ->and($sunting->refresh()->Judul)->toBe('Opname versi saya');

        expect(Artisan::call('bantuan:siapkan-bawaan'))->toBe(0)
            ->and(Artisan::output())->toContain('Tidak ada yang dibuat')
            ->and(ArtikelBantuan::query()->count())->toBe($jumlah);
    });

    it('setiap artikel awal sah: slug, konteks yang ada di rute back-office, kategori dan isi terisi', function (): void {
        $uriKelola = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => '/'.$r->uri())->filter(fn (string $u): bool => str_starts_with($u, '/kelola'))->all();
        $slug = [];

        foreach (SiapkanArtikelBantuanBawaan::Daftar() as $a) {
            expect(preg_match('#^'.ArtikelBantuan::POLA_SLUG.'$#', $a['Slug']))->toBe(1)
                ->and(strlen($a['Judul']))->toBeLessThanOrEqual(150)
                ->and(strlen($a['Ringkasan']))->toBeLessThanOrEqual(300)
                ->and(trim($a['Isi']))->not->toBe('')
                ->and($a['Kategori'])->not->toBe('')
                ->and($a['Konteks'])->not->toBe([]);
            $slug[] = $a['Slug'];

            foreach ($a['Konteks'] as $konteks) {
                expect(preg_match('#^'.ArtikelBantuan::POLA_KONTEKS.'$#', $konteks))->toBe(1);
                // Konteks harus awalan jalur nyata, kalau tidak artikelnya tidak pernah muncul di halaman mana pun.
                $ada = $konteks === '/kelola' || collect($uriKelola)->contains(fn (string $u): bool => $u === $konteks || str_starts_with($u, $konteks.'/'));
                expect($ada)->toBeTrue("Konteks {$konteks} pada {$a['Slug']} tidak cocok dengan rute back-office mana pun.");
            }
        }

        expect(count($slug))->toBe(count(array_unique($slug)));
    });
});
