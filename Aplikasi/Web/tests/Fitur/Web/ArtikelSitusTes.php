<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelSitus;
use App\Domain\Situs\Model\GambarSitus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * Situs pemasaran bagian B2: artikel blog — konsol (buat draf, simpan, terbitkan, tarik, hapus, izin, audit), publik
 * `/blog` & `/blog/{slug}` (hanya terbit, kategori, paginasi, terkait), SEO artikel, dan peta situs.
 */

function MasukKontenArtikel(PeranPengelolaBawaan $peran = PeranPengelolaBawaan::KontenLegal): void
{
    test()->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @param array<string, mixed> $timpa */
function BuatArtikelUji(array $timpa = []): ArtikelSitus
{
    $a = new ArtikelSitus;
    $a->forceFill([
        'Slug' => 'tips-kasir-kafe',
        'Judul' => 'Tips kasir kafe',
        'Ringkasan' => 'Cara cepat melayani antrean pagi.',
        'Isi' => "## Siapkan menu favorit\n\nTaruh menu terlaris di halaman pertama.",
        'Kategori' => 'Tips kasir',
        'Status' => StatusArtikel::Terbit,
        'DiterbitkanPada' => now(),
        ...$timpa,
    ])->save();

    return $a;
}

function UrlPublikArtikel(string $jalur): string
{
    return rtrim((string) config('app.url'), '/').$jalur;
}

beforeEach(function (): void {
    Storage::fake('public');
});

describe('konsol artikel', function (): void {
    it('Konten & Legal menulis draf (slug dari judul), menyimpan, lalu menerbitkan. Audit tercatat', function (): void {
        MasukKontenArtikel();
        $this->post(BantuanPengelola::Url('/situs/artikel'), ['Judul' => 'Cara Menghitung HPP Kopi!'])->assertRedirect();

        $a = ArtikelSitus::query()->sole();
        expect($a->Slug)->toBe('cara-menghitung-hpp-kopi')->and($a->Status)->toBe(StatusArtikel::Draf);

        $this->get(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Artikel/Ubah')
            ->where('Artikel.Slug', 'cara-menghitung-hpp-kopi')
            ->where('Izin.Kelola', true));

        // Isi kosong tidak bisa diterbitkan.
        $this->post(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}/terbitkan"))->assertSessionHasErrors('Isi');

        $this->put(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"), [
            'Judul' => 'Cara menghitung HPP kopi', 'Slug' => 'hpp-kopi', 'Ringkasan' => 'Rumus sederhana.',
            'Isi' => "HPP = bahan + kemasan.\n\n- Kopi\n- Susu", 'Kategori' => 'Keuangan', 'NamaPenulis' => 'Tim Payoung',
        ])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}/terbitkan"))->assertSessionHasNoErrors();

        $a->refresh();
        expect($a->Slug)->toBe('hpp-kopi')->and($a->Status)->toBe(StatusArtikel::Terbit)->and($a->DiterbitkanPada)->not->toBeNull();
        expect(LogAuditPengelola::query()->whereIn('Aksi', ['situs.artikel.buat', 'situs.artikel.ubah', 'situs.artikel.terbitkan'])->count())->toBe(3);

        $this->get(BantuanPengelola::Url('/situs/artikel'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Artikel/Daftar')
            ->where('Artikel.Data.0.Judul', 'Cara menghitung HPP kopi')
            ->where('PilihanKategori', ['Keuangan']));
    });

    it('slug unik & valid. Sampul harus ada di pustaka', function (): void {
        MasukKontenArtikel();
        BuatArtikelUji();
        $a = BuatArtikelUji(['Slug' => 'lain', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);
        $isi = ['Judul' => 'Lain', 'Isi' => 'Isi.'];

        $this->put(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"), [...$isi, 'Slug' => 'tips-kasir-kafe'])->assertSessionHasErrors('Slug');
        $this->put(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"), [...$isi, 'Slug' => 'Bukan Slug/2'])->assertSessionHasErrors('Slug');
        $this->put(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"), [...$isi, 'UuidGambarSampul' => str_repeat('A', 26)])->assertSessionHasErrors('UuidGambarSampul');
    });

    it('artikel terbit harus ditarik dulu sebelum dihapus; Dukungan ditolak', function (): void {
        $a = BuatArtikelUji();

        MasukKontenArtikel(PeranPengelolaBawaan::Dukungan);
        $this->get(BantuanPengelola::Url('/situs/artikel'))->assertForbidden();

        MasukKontenArtikel();
        $this->delete(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}/tarik"))->assertSessionHasNoErrors();
        expect($a->refresh()->Status)->toBe(StatusArtikel::Draf);
        $terbitPertama = $a->DiterbitkanPada;

        $this->delete(BantuanPengelola::Url("/situs/artikel/{$a->Uuid}"))->assertRedirect();
        expect(ArtikelSitus::query()->count())->toBe(0)->and($terbitPertama)->not->toBeNull();
    });

    it('gambar yang dipakai sampul artikel tidak bisa dihapus dari pustaka', function (): void {
        MasukKontenArtikel();
        $this->post(BantuanPengelola::Url('/situs/gambar'), ['Berkas' => UploadedFile::fake()->image('sampul.png', 1200, 630), 'TeksAlternatif' => 'Sampul'])
            ->assertSessionHasNoErrors();
        $g = GambarSitus::query()->sole();
        BuatArtikelUji(['UuidGambarSampul' => $g->Uuid, 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);

        $this->delete(BantuanPengelola::Url("/situs/gambar/{$g->Uuid}"))->assertSessionHasErrors('Umum');
        expect(GambarSitus::query()->count())->toBe(1);
    });
});

describe('blog publik', function (): void {
    it('hanya artikel terbit yang tampil. Draf 404. Kategori & paginasi', function (): void {
        BuatArtikelUji();
        BuatArtikelUji(['Slug' => 'draf-rahasia', 'Judul' => 'Draf rahasia', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);
        foreach (range(1, 13) as $i) {
            BuatArtikelUji(['Slug' => "stok-{$i}", 'Judul' => "Stok {$i}", 'Kategori' => 'Stok', 'DiterbitkanPada' => now()->subDays($i)]);
        }

        $this->get(UrlPublikArtikel('/blog'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/Blog')
            ->has('Artikel', 12)
            ->where('Artikel.0.Slug', 'tips-kasir-kafe')
            ->where('Kategori', ['Stok', 'Tips kasir'])
            ->where('JumlahHalaman', 2)
            ->where('Halaman.Seo.Kanonik', fn ($url) => str_ends_with((string) $url, '/blog')));

        $this->get(UrlPublikArtikel('/blog?halaman=2'))->assertInertia(fn (AssertableInertia $h) => $h->has('Artikel', 2)->where('HalamanKe', 2));
        $this->get(UrlPublikArtikel('/blog?kategori=Tips+kasir'))->assertInertia(fn (AssertableInertia $h) => $h->has('Artikel', 1)->where('KategoriAktif', 'Tips kasir'));

        $this->get(UrlPublikArtikel('/blog/draf-rahasia'))->assertNotFound();
        $this->get(UrlPublikArtikel('/blog/tidak-ada'))->assertNotFound();
    });

    it('halaman artikel: isi, terkait sekategori, SEO artikel (og:type article & JSON-LD)', function (): void {
        BuatArtikelUji();
        BuatArtikelUji(['Slug' => 'tips-dua', 'Judul' => 'Tips dua', 'DiterbitkanPada' => now()->subDay()]);
        BuatArtikelUji(['Slug' => 'stok-satu', 'Judul' => 'Stok satu', 'Kategori' => 'Stok']);

        $respons = $this->get(UrlPublikArtikel('/blog/tips-kasir-kafe'));
        $respons->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/Artikel')
            ->where('Artikel.Judul', 'Tips kasir kafe')
            ->where('Artikel.Isi', fn ($isi) => str_contains((string) $isi, 'Siapkan menu favorit'))
            ->has('Terkait', 1)
            ->where('Terkait.0.Slug', 'tips-dua')
            ->where('Halaman.Seo.Jenis', 'article')
            ->where('Halaman.Seo.Deskripsi', 'Cara cepat melayani antrean pagi.'));

        $html = $respons->getContent();
        expect($html)->toContain('<meta property="og:type" content="article">')
            ->toContain('"@type":"BlogPosting"')
            ->toContain('<title inertia>Tips kasir kafe | Payoung</title>');
    });

    it('peta situs memuat /blog dan artikel terbit saja', function (): void {
        BuatArtikelUji();
        BuatArtikelUji(['Slug' => 'draf-rahasia', 'Status' => StatusArtikel::Draf, 'DiterbitkanPada' => null]);

        $xml = (string) $this->get(UrlPublikArtikel('/peta-situs'))->assertOk()->getContent();
        expect($xml)->toContain('/blog</loc>')->toContain('/blog/tips-kasir-kafe</loc>')->not->toContain('draf-rahasia');
    });

    it('domain terpisah: blog dilayani di domain pemasaran, dialihkan dari domain tenant', function (): void {
        config(['app.url' => 'https://dashboard.payoung.test', 'domain.Pemasaran' => 'payoung.test', 'domain.Tenant' => 'dashboard.payoung.test']);
        BuatArtikelUji();

        $this->get('https://payoung.test/blog/tips-kasir-kafe')->assertOk();
        $this->get('https://dashboard.payoung.test/blog')->assertRedirect('https://payoung.test/blog');
    });
});
