<?php

declare(strict_types=1);

use App\Domain\Pengelola\Konten\Aksi\SiapkanHalamanSitusBawaan;
use App\Domain\Situs\Kueri\PengaturanSitusBerlaku;
use App\Domain\Situs\Layanan\AturanSlugSitus;
use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Layanan\ValidatorBagianSitus;
use App\Domain\Situs\Model\HalamanSitus;
use App\Domain\Situs\Model\PengaturanSitus;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-21 situs pemasaran publik (payoung.id): halaman berblok dari konsol dengan isi bawaan, harga dari katalog P-04,
 * meta SEO dari server, peta situs, noindex domain tenant, pratinjau bertanda tangan, dan pembagian domain D-20.
 */

/** Domain terpisah seperti produksi (https). */
function AturDomainSitusUji(): void
{
    config(['app.url' => 'https://dashboard.payoung.test', 'domain.Pemasaran' => 'payoung.test', 'domain.Tenant' => 'dashboard.payoung.test']);
}

describe('D-21 halaman publik', function (): void {
    it('isi bawaan lolos validator blok & batas kolom SEO (bisa disiapkan ke tabel apa adanya)', function (): void {
        foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $halaman) {
            expect(app(ValidatorBagianSitus::class)->Periksa($halaman['Bagian']))->toHaveCount(count($halaman['Bagian']))
                ->and(mb_strlen($halaman['Judul']))->toBeLessThanOrEqual(150, $slug)
                ->and(mb_strlen((string) $halaman['JudulSeo']))->toBeLessThanOrEqual(70, $slug)
                ->and(mb_strlen((string) $halaman['DeskripsiSeo']))->toBeLessThanOrEqual(170, $slug);
        }

        $bawaan = PengaturanSitusBerlaku::AmbilBawaan();
        expect(mb_strlen($bawaan['JudulSeo']))->toBeLessThanOrEqual(70)
            ->and(mb_strlen($bawaan['DeskripsiSeo']))->toBeLessThanOrEqual(170);
    });

    it('semua halaman bawaan tampil sebelum konsol menyimpan apa pun; slug tak dikenal 404', function (): void {
        foreach (array_keys(KontenSitusBawaan::AmbilHalaman()) as $slug) {
            $jalur = $slug === HalamanSitus::SLUG_BERANDA ? '/' : '/'.$slug;
            $this->get($jalur)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Situs/Halaman')
                ->where('Halaman.Slug', $slug)
                ->has('Halaman.Bagian.0')
                ->has('Situs.Menu.0'));
        }

        $this->get('/halaman-tidak-ada')->assertNotFound();
        $this->get('/beranda')->assertNotFound();
    });

    it('blok Harga hanya berisi paket aktif dengan harga terbit yang berlaku (P-04)', function (): void {
        BantuanPendaftaran::SiapkanPrasyarat();
        // Harga katalog bawaan masih Draf: hanya paket harga negosiasi yang tampil.
        $this->get('/harga')->assertInertia(fn (AssertableInertia $h) => expect(
            collect(collect($h->toArray()['props']['Halaman']['Bagian'])->firstWhere('Jenis', 'Harga')['Paket'])->pluck('Kode')->all()
        )->toBe(['ENTERPRISE']));
        HargaPaket::query()->where('IdPaket', Paket::query()->where('Kode', 'PRO')->value('Id'))->update(['Status' => 'Terbit']);

        $this->get('/harga')->assertOk()->assertInertia(function (AssertableInertia $h): void {
            $bagian = collect($h->toArray()['props']['Halaman']['Bagian'])->firstWhere('Jenis', 'Harga');
            expect($bagian)->not->toBeNull()
                ->and($bagian['Paket'])->not->toBeEmpty()
                ->and(collect($bagian['Paket'])->pluck('Kode')->all())->toContain('PRO')
                ->and($bagian['TautanDaftar'])->toBe('/daftar');

            foreach ($bagian['Paket'] as $paket) {
                expect($paket['HargaNegosiasi'] || is_string($paket['HargaBulanan']))->toBeTrue();
            }
        });
    });

    it('meta SEO dirender server & di-escape; kanonik mengikuti domain pemasaran', function (): void {
        AturDomainSitusUji();
        PengaturanSitus::query()->create([
            'Kunci' => PengaturanSitus::KUNCI_UMUM,
            'Nilai' => [...PengaturanSitusBerlaku::AmbilBawaan(), 'VerifikasiGoogle' => 'abc123'],
        ]);
        HalamanSitus::query()->create([
            'Slug' => 'uji-seo',
            'Judul' => 'Uji',
            'BagianDraf' => [],
            'BagianTerbit' => [],
            'JudulTerbit' => 'Uji',
            'JudulSeoTerbit' => 'Kasir "Payoung" <b>',
            'DiterbitkanPada' => now(),
        ]);

        $isi = $this->get('https://payoung.test/uji-seo')->assertOk()->getContent();

        expect($isi)->toContain('<title inertia>Kasir &quot;Payoung&quot; &lt;b&gt;</title>')
            ->not->toContain('<b></title>')
            ->toContain('<link rel="canonical" href="https://payoung.test/uji-seo">')
            ->toContain('name="google-site-verification" content="abc123"')
            ->toContain('property="og:title"')
            ->not->toContain('noindex');
    });

    it('pengaturan konsol berlaku di data bersama: WhatsApp 08… → wa.me/62…, pintasan @whatsapp diterjemahkan', function (): void {
        PengaturanSitus::query()->create([
            'Kunci' => PengaturanSitus::KUNCI_UMUM,
            'Nilai' => [
                ...PengaturanSitusBerlaku::AmbilBawaan(),
                'Kontak' => [...PengaturanSitusBerlaku::AmbilBawaan()['Kontak'], 'WhatsApp' => '0812-3456-7890', 'PesanWhatsApp' => 'Halo Payoung'],
                'Menu' => [['Label' => 'Tanya', 'Tautan' => '@whatsapp']],
                'Pengumuman' => ['Aktif' => true, 'Teks' => 'Diskon 17 Agustus', 'Tautan' => '/harga'],
            ],
        ]);

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Situs.Kontak.TautanWhatsApp', 'https://wa.me/6281234567890?text=Halo%20Payoung')
            ->where('Situs.Menu.0.Tautan', 'https://wa.me/6281234567890?text=Halo%20Payoung')
            ->where('Situs.WhatsAppMelayang', true)
            ->where('Situs.Pengumuman.Teks', 'Diskon 17 Agustus'));
    });

    it('halaman belum terbit atau disembunyikan tidak tampil; pratinjau hanya lewat tautan bertanda tangan', function (): void {
        $halaman = HalamanSitus::query()->create([
            'Slug' => 'promo-lebaran',
            'Judul' => 'Promo Lebaran',
            'BagianDraf' => [['Jenis' => 'Cta', 'Judul' => 'Diskon THR', 'Teks' => null, 'TombolUtama' => null, 'TombolKedua' => null]],
        ]);
        $this->get('/promo-lebaran')->assertNotFound();

        $this->get('/pratinjau-situs/'.$halaman->Uuid)->assertForbidden();
        // Konsol menandatangani jalur relatif lalu memasang domain pemasaran (D-20).
        $tautan = URL::temporarySignedRoute('situs.pratinjau', now()->addMinutes(5), ['halamanSitus' => $halaman->Uuid], false);
        $isi = $this->get($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Halaman.Pratinjau', true)
            ->where('Halaman.Bagian.0.Judul', 'Diskon THR'))->getContent();
        expect($isi)->toContain('noindex');

        $halaman->forceFill(['BagianTerbit' => $halaman->BagianDraf, 'JudulTerbit' => 'Promo Lebaran', 'DiterbitkanPada' => now()])->save();
        $this->get('/promo-lebaran')->assertOk();

        $halaman->forceFill(['Aktif' => false])->save();
        $this->get('/promo-lebaran')->assertNotFound();
    });

    it('peta situs memuat halaman terbit & bawaan tanpa halaman tersembunyi; domain tenant tidak diindeks', function (): void {
        AturDomainSitusUji();
        app(SiapkanHalamanSitusBawaan::class)->Jalankan();
        HalamanSitus::query()->where('Slug', 'tentang')->update(['Aktif' => false]);

        $xml = $this->get('https://payoung.test/peta-situs')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        expect($xml)->toContain('<loc>https://payoung.test/</loc>')
            ->toContain('<loc>https://payoung.test/solusi/kafe-resto</loc>')
            ->not->toContain('https://payoung.test/tentang');

        $this->get('https://payoung.test/fitur')->assertHeaderMissing('X-Robots-Tag');
        $this->get('https://dashboard.payoung.test/masuk')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    it('D-89: robots.txt menunjuk ke peta situs dan JSON-LD memuat Organisasi + WebSite', function (): void {
        AturDomainSitusUji();
        app(SiapkanHalamanSitusBawaan::class)->Jalankan();

        expect((string) file_get_contents(public_path('robots.txt')))->toContain('Sitemap: https://payoung.id/peta-situs')
            ->and((string) file_get_contents(public_path('robots.txt')))->toContain('Disallow: /kelola');

        $html = $this->get('https://payoung.test/')->assertOk()->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $cocok);
        $graf = json_decode($cocok[1] ?? '{}', true)['@graph'] ?? [];

        expect(array_column($graf, '@type'))->toBe(['Organization', 'WebSite'])
            ->and($graf[0]['logo'])->toBe('https://payoung.test/apple-touch-icon.png')
            ->and($graf[1]['inLanguage'])->toBe('id-ID')
            ->and($graf[1]['publisher']['@id'])->toBe($graf[0]['@id']);
    });

    it('D-20: halaman situs di domain tenant dialihkan ke domain pemasaran', function (): void {
        AturDomainSitusUji();

        $this->get('https://payoung.test/fitur')->assertOk();
        $this->get('https://dashboard.payoung.test/fitur')->assertRedirect('https://payoung.test/fitur');
        $this->get('https://dashboard.payoung.test/solusi/kafe-resto?utm=x')->assertRedirect('https://payoung.test/solusi/kafe-resto?utm=x');
        $this->get('https://dashboard.payoung.test/peta-situs')->assertRedirect('https://payoung.test/peta-situs');
    });

    it('slug terlarang menutup semua segmen pertama rute aplikasi (halaman konsol tidak tertutup rute sistem)', function (): void {
        $segmen = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rute): bool => $rute->getDomain() === null && ! str_starts_with((string) $rute->getName(), 'situs.'))
            ->map(fn ($rute): string => explode('/', trim($rute->uri(), '/'))[0])
            ->filter(fn (string $s): bool => $s !== '' && ! str_starts_with($s, '{') && preg_match('#^'.AturanSlugSitus::POLA.'$#', $s) === 1)
            ->unique()
            ->values();

        foreach ($segmen as $s) {
            expect(AturanSlugSitus::Periksa($s))->not->toBeNull("Segmen rute \"{$s}\" belum ada di AturanSlugSitus::TERLARANG");
        }
    });
});
