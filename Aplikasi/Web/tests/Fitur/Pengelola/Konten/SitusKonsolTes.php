<?php

declare(strict_types=1);

use App\Domain\Pengelola\Konten\Layanan\TemplatHalamanSitus;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Situs\Kueri\PengaturanSitusBerlaku;
use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Model\GambarSitus;
use App\Domain\Situs\Model\HalamanSitus;
use App\Domain\Situs\Model\PengaturanSitus;
use App\Domain\Situs\Model\RevisiHalamanSitus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/*
 * D-21 konsol situs pemasaran: halaman berblok (draf → pratinjau → terbit), pengaturan situs, pustaka gambar, izin
 * `situs.lihat`/`situs.kelola`, dan audit.
 */

function MasukSebagaiKontenSitus(TestCase $tes, PeranPengelolaBawaan $peran = PeranPengelolaBawaan::KontenLegal): PenggunaPengelola
{
    $pengguna = BantuanPengelola::BuatAnggota($peran);
    $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

    return $pengguna;
}

/** @return list<array<string, mixed>> */
function BagianUjiSitus(string $judul = 'Kasir untuk warung kopi'): array
{
    return [
        ['Jenis' => 'Hero', 'Judul' => $judul, 'Subjudul' => 'Tetap jalan saat offline.', 'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar']],
        ['Jenis' => 'Faq', 'Judul' => 'Tanya jawab', 'Item' => [['Pertanyaan' => 'Bisa offline?', 'Jawaban' => 'Bisa.']]],
    ];
}

/** URL situs publik lengkap: jalur relatif akan memakai host permintaan terakhir (domain konsol). */
function UrlSitusPublik(string $jalur): string
{
    return rtrim((string) config('app.url'), '/').$jalur;
}

beforeEach(function (): void {
    Storage::fake('public');
});

describe('D-21 izin konsol situs', function (): void {
    it('Konten & Legal bisa membuka & mengelola; Dukungan ditolak', function (): void {
        MasukSebagaiKontenSitus($this, PeranPengelolaBawaan::Dukungan);
        $this->get(BantuanPengelola::Url('/situs/halaman'))->assertForbidden();
        $this->put(BantuanPengelola::Url('/situs/pengaturan'), [])->assertForbidden();

        MasukSebagaiKontenSitus($this);
        $this->get(BantuanPengelola::Url('/situs/halaman'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Halaman/Daftar')
            ->where('Izin.Kelola', true)
            ->has('Halaman', count(KontenSitusBawaan::AmbilHalaman())));
        $this->get(BantuanPengelola::Url('/situs/pengaturan'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Pengaturan')
            ->where('Pengaturan.NamaSitus', 'Payoung'));
        $this->get(BantuanPengelola::Url('/situs/gambar'))->assertOk();
    });

    it('membuka daftar menyiapkan halaman bawaan sekali (terbit, idempoten)', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->get(BantuanPengelola::Url('/situs/halaman'))->assertOk();
        $this->get(BantuanPengelola::Url('/situs/halaman'))->assertOk();

        expect(HalamanSitus::query()->count())->toBe(count(KontenSitusBawaan::AmbilHalaman()))
            ->and(HalamanSitus::query()->where('Slug', 'beranda')->sole()->CekTerbit())->toBeTrue();
    });
});

describe('D-21 halaman berblok', function (): void {
    it('buat → simpan draf (publik belum berubah) → terbitkan → sembunyikan → hapus, semua tercatat di audit', function (): void {
        MasukSebagaiKontenSitus($this);

        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'solusi/apotek', 'Judul' => 'Apotek'])->assertRedirect();
        $halaman = HalamanSitus::query()->where('Slug', 'solusi/apotek')->sole();
        $url = BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}");

        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Halaman/Ubah')
            ->where('Halaman.Bawaan', false)
            ->has('Skema.Hero')
            ->has('LabelBlok.Harga'));

        $this->put($url, ['Slug' => 'solusi/apotek', 'Judul' => 'Apotek', 'TampilDiSitemap' => true, 'Bagian' => BagianUjiSitus()])
            ->assertSessionHasNoErrors();
        $this->get(UrlSitusPublik('/solusi/apotek'))->assertNotFound();

        $this->post("{$url}/terbitkan")->assertSessionHasNoErrors();
        $this->get(UrlSitusPublik('/solusi/apotek'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Halaman.Bagian.0.Judul', 'Kasir untuk warung kopi')
            ->where('Halaman.Bagian.0.TombolUtama.Tautan', '/daftar'));

        // Draf baru tidak mengubah versi terbit sampai diterbitkan lagi.
        $this->put($url, ['Slug' => 'solusi/apotek', 'Judul' => 'Apotek', 'Bagian' => BagianUjiSitus('Judul baru')])->assertSessionHasNoErrors();
        $this->get(UrlSitusPublik('/solusi/apotek'))->assertInertia(fn (AssertableInertia $h) => $h->where('Halaman.Bagian.0.Judul', 'Kasir untuk warung kopi'));
        expect($halaman->refresh()->CekAdaPerubahan())->toBeTrue();

        $this->post("{$url}/aktif", ['Aktif' => false])->assertSessionHasNoErrors();
        $this->get(UrlSitusPublik('/solusi/apotek'))->assertNotFound();

        $this->delete($url)->assertRedirect(route('pengelola.situs.halaman.daftar'));
        expect(HalamanSitus::query()->where('Slug', 'solusi/apotek')->exists())->toBeFalse()
            ->and(LogAuditPengelola::query()->where('Aksi', 'like', 'situs.halaman.%')->pluck('Aksi')->unique()->count())->toBeGreaterThanOrEqual(4);
    });

    it('pratinjau mengalihkan ke tautan bertanda tangan yang menampilkan draf', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'promo', 'Judul' => 'Promo']);
        $halaman = HalamanSitus::query()->where('Slug', 'promo')->sole();

        $lokasi = $this->get(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/pratinjau"))->assertRedirect()->headers->get('Location');
        expect($lokasi)->toContain('/pratinjau-situs/'.$halaman->Uuid)->toContain('signature=');

        $this->get((string) $lokasi)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Halaman.Pratinjau', true));
    });

    it('menolak blok & slug tidak valid dengan masalah per bidang', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'masuk', 'Judul' => 'X'])->assertSessionHasErrors('Slug');
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'Harga Spesial', 'Judul' => 'X'])->assertSessionHasErrors('Slug');
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'a/b/c', 'Judul' => 'X'])->assertSessionHasErrors('Slug');
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'promo', 'Judul' => 'Promo'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'promo', 'Judul' => 'Promo 2'])->assertSessionHasErrors('Slug');

        $url = BantuanPengelola::Url('/situs/halaman/'.HalamanSitus::query()->where('Slug', 'promo')->value('Uuid'));
        $this->put($url, ['Slug' => 'promo', 'Judul' => 'Promo', 'Bagian' => [
            ['Jenis' => 'Hero', 'Judul' => '', 'TombolUtama' => ['Label' => 'Klik', 'Tautan' => 'javascript:alert(1)']],
            ['Jenis' => 'Skrip'],
            ['Jenis' => 'Video', 'UrlYoutube' => 'https://vimeo.com/123'],
            ['Jenis' => 'Hero', 'Judul' => 'Ok', 'Gambar' => '01K5AAAAAAAAAAAAAAAAAAAAAA'],
        ]])->assertSessionHasErrors(['Bagian.0.Judul', 'Bagian.0.TombolUtama.Tautan', 'Bagian.1.Jenis', 'Bagian.2.UrlYoutube', 'Bagian']);
    });

    it('halaman bawaan: slug tetap, tidak bisa dihapus; beranda tidak bisa disembunyikan', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->get(BantuanPengelola::Url('/situs/halaman'));
        $fitur = HalamanSitus::query()->where('Slug', 'fitur')->sole();
        $beranda = HalamanSitus::query()->where('Slug', 'beranda')->sole();

        $this->put(BantuanPengelola::Url("/situs/halaman/{$fitur->Uuid}"), ['Slug' => 'fitur-baru', 'Judul' => 'Fitur', 'Bagian' => BagianUjiSitus()])
            ->assertSessionHasErrors('Slug');
        $this->delete(BantuanPengelola::Url("/situs/halaman/{$fitur->Uuid}"))->assertSessionHasErrors();
        $this->post(BantuanPengelola::Url("/situs/halaman/{$beranda->Uuid}/aktif"), ['Aktif' => false])->assertSessionHasErrors();
        expect($fitur->refresh()->exists)->toBeTrue()->and($beranda->refresh()->Aktif)->toBeTrue();
    });
});

describe('D-63 penyunting visual halaman situs', function (): void {
    function BuatHalamanEditor(TestCase $tes): array
    {
        $tes->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'editor-uji', 'Judul' => 'Editor uji']);
        $halaman = HalamanSitus::query()->where('Slug', 'editor-uji')->sole();

        return [$halaman, BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}")];
    }

    it('membuka penyunting dengan alamat pratinjau bertanda tangan', function (): void {
        MasukSebagaiKontenSitus($this);
        [, $url] = BuatHalamanEditor($this);

        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Halaman/Ubah')
            ->where('UrlPratinjauEditor', fn ($u) => is_string($u) && str_ends_with($u, '/kanvas') && ! str_contains($u, 'signature='))
            ->has('NamaSitus')
            ->has('AlamatSitus'));
    });

    it('kanvas editor dilayani dari asal konsol yang sama dan menandai pratinjau (D-73)', function (): void {
        MasukSebagaiKontenSitus($this);
        [, $url] = BuatHalamanEditor($this);

        $this->get("{$url}/kanvas")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/Halaman')
            ->where('Halaman.Pratinjau', true)
            ->where('Halaman.AsalEditor', fn ($asal) => is_string($asal) && $asal !== '' && str_starts_with(url('/'), $asal))
            ->has('Halaman.Bagian')
            ->has('Situs'));
    });

    it('pratinjau langsung menerima blok belum lengkap sebagai penanda, bukan masalah', function (): void {
        MasukSebagaiKontenSitus($this);
        [, $url] = BuatHalamanEditor($this);

        $bagian = [...BagianUjiSitus(), ['Jenis' => 'Hero', 'Judul' => '']];
        $respons = $this->postJson("{$url}/pratinjau-langsung", ['Judul' => 'Editor uji', 'Bagian' => $bagian])->assertOk();

        $respons->assertJsonPath('Halaman.Bagian.0.Judul', 'Kasir untuk warung kopi')
            ->assertJsonPath('Halaman.Bagian.2.Jenis', 'BelumLengkap');
        expect($respons->json('Galat'))->not->toBeEmpty();
    });

    it('simpan otomatis menyimpan draf tanpa menerbitkan dan menolak blok tidak sah', function (): void {
        MasukSebagaiKontenSitus($this);
        [$halaman, $url] = BuatHalamanEditor($this);

        $this->putJson("{$url}/draf-otomatis", ['Slug' => 'editor-uji', 'Judul' => 'Editor uji', 'Bagian' => BagianUjiSitus('Otomatis')])
            ->assertOk()->assertJsonStructure(['DisimpanPada', 'AdaPerubahan']);
        expect($halaman->refresh()->BagianDraf[0]['Judul'])->toBe('Otomatis');
        $this->get(UrlSitusPublik('/editor-uji'))->assertNotFound();

        $this->putJson("{$url}/draf-otomatis", ['Slug' => 'editor-uji', 'Judul' => 'Editor uji', 'Bagian' => [['Jenis' => 'Hero', 'Judul' => '']]])
            ->assertUnprocessable();
    });

    it('izin: yang hanya boleh melihat tidak bisa simpan otomatis', function (): void {
        MasukSebagaiKontenSitus($this);
        [, $url] = BuatHalamanEditor($this);
        MasukSebagaiKontenSitus($this, PeranPengelolaBawaan::Dukungan);

        $this->putJson("{$url}/draf-otomatis", ['Slug' => 'editor-uji', 'Judul' => 'x', 'Bagian' => []])->assertForbidden();
    });

    it('tautan pratinjau hanya boleh dibingkai oleh konsol', function (): void {
        MasukSebagaiKontenSitus($this);
        [$halaman] = BuatHalamanEditor($this);

        $lokasi = (string) $this->get(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/pratinjau"))->headers->get('Location');
        $respons = $this->get($lokasi)->assertOk();

        expect((string) $respons->headers->get('Content-Security-Policy'))->toContain('frame-ancestors')->not->toContain('*')
            ->and((string) $respons->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('D-63 revisi, jadwal, gandakan, templat', function (): void {
    it('menerbitkan mencatat revisi; memulihkan menaruhnya di draf dan menyimpan draf lama dulu', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'riwayat', 'Judul' => 'Riwayat']);
        $halaman = HalamanSitus::query()->where('Slug', 'riwayat')->sole();
        $url = BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}");

        $this->put($url, ['Slug' => 'riwayat', 'Judul' => 'Riwayat', 'Bagian' => BagianUjiSitus('Versi satu')])->assertSessionHasNoErrors();
        $this->post("{$url}/terbitkan")->assertSessionHasNoErrors();
        $this->put($url, ['Slug' => 'riwayat', 'Judul' => 'Riwayat', 'Bagian' => BagianUjiSitus('Versi dua')])->assertSessionHasNoErrors();

        $revisi = RevisiHalamanSitus::query()->where('IdHalamanSitus', $halaman->Id)->sole();
        expect($revisi->Jenis)->toBe('Terbit')->and($revisi->Bagian[0]['Judul'])->toBe('Versi satu');

        $this->get($url)->assertInertia(fn (AssertableInertia $h) => $h->has('Revisi', 1)->where('Revisi.0.Jenis', 'Terbit'));

        $this->post("{$url}/revisi/{$revisi->Uuid}/pulihkan")->assertSessionHasNoErrors();
        expect($halaman->refresh()->BagianDraf[0]['Judul'])->toBe('Versi satu')
            ->and(RevisiHalamanSitus::query()->where('Jenis', 'SebelumPulih')->sole()->Bagian[0]['Judul'])->toBe('Versi dua');
    });

    it('revisi milik halaman lain tidak bisa dipulihkan', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'satu', 'Judul' => 'Satu']);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'dua', 'Judul' => 'Dua']);
        $satu = HalamanSitus::query()->where('Slug', 'satu')->sole();
        $dua = HalamanSitus::query()->where('Slug', 'dua')->sole();
        $this->post(BantuanPengelola::Url("/situs/halaman/{$satu->Uuid}/terbitkan"));
        $revisi = RevisiHalamanSitus::query()->where('IdHalamanSitus', $satu->Id)->sole();

        $this->post(BantuanPengelola::Url("/situs/halaman/{$dua->Uuid}/revisi/{$revisi->Uuid}/pulihkan"))->assertSessionHasErrors();
    });

    it('revisi dibatasi per halaman', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'sering', 'Judul' => 'Sering']);
        $halaman = HalamanSitus::query()->where('Slug', 'sering')->sole();

        for ($i = 0; $i < RevisiHalamanSitus::BATAS_PER_HALAMAN + 4; $i++) {
            $this->post(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/terbitkan"));
        }

        expect(RevisiHalamanSitus::query()->where('IdHalamanSitus', $halaman->Id)->count())->toBe(RevisiHalamanSitus::BATAS_PER_HALAMAN);
    });

    it('jadwal terbit: harus di masa depan, bisa dibatalkan, dan perintah menerbitkan saat tiba', function (): void {
        $pelaku = MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'nanti', 'Judul' => 'Nanti']);
        $halaman = HalamanSitus::query()->where('Slug', 'nanti')->sole();
        $url = BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}");

        $this->put("{$url}/jadwal", ['JadwalTerbitPada' => now()->subHour()->toIso8601String()])->assertSessionHasErrors('JadwalTerbitPada');

        $this->put("{$url}/jadwal", ['JadwalTerbitPada' => now()->addDay()->toIso8601String()])->assertSessionHasNoErrors();
        expect($halaman->refresh()->JadwalTerbitPada)->not->toBeNull()->and($halaman->IdPenggunaPengelolaPenjadwal)->toBe($pelaku->Id);
        $this->artisan('situs:terbitkan-terjadwal')->assertSuccessful();
        expect($halaman->refresh()->CekTerbit())->toBeFalse();

        $this->put("{$url}/jadwal", ['JadwalTerbitPada' => null])->assertSessionHasNoErrors();
        expect($halaman->refresh()->JadwalTerbitPada)->toBeNull();

        $halaman->forceFill(['JadwalTerbitPada' => now()->subMinute(), 'IdPenggunaPengelolaPenjadwal' => $pelaku->Id])->save();
        $this->artisan('situs:terbitkan-terjadwal')->expectsOutput('1 halaman diterbitkan.')->assertSuccessful();
        expect($halaman->refresh()->CekTerbit())->toBeTrue()->and($halaman->JadwalTerbitPada)->toBeNull();
        $this->get(UrlSitusPublik('/nanti'))->assertOk();
    });

    it('menggandakan halaman menjadi draf baru dengan slug unik', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'asli', 'Judul' => 'Asli']);
        $asli = HalamanSitus::query()->where('Slug', 'asli')->sole();
        $url = BantuanPengelola::Url("/situs/halaman/{$asli->Uuid}/gandakan");

        $this->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();

        expect(HalamanSitus::query()->whereIn('Slug', ['asli-salinan', 'asli-salinan-2'])->count())->toBe(2)
            ->and(HalamanSitus::query()->where('Slug', 'asli-salinan')->sole()->CekTerbit())->toBeFalse();
    });

    it('halaman baru dari setiap templat lolos validasi blok', function (): void {
        MasukSebagaiKontenSitus($this);

        foreach (array_keys(TemplatHalamanSitus::Daftar()) as $kunci) {
            $slug = 'templat-'.strtolower($kunci);
            $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => $slug, 'Judul' => 'Uji '.$kunci, 'Templat' => $kunci])->assertSessionHasNoErrors();
            $halaman = HalamanSitus::query()->where('Slug', $slug)->sole();
            $this->postJson(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/pratinjau-langsung"), ['Judul' => $halaman->Judul, 'Bagian' => $halaman->BagianDraf])
                ->assertOk()->assertJsonPath('Galat', []);
        }

        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'templat-ngawur', 'Judul' => 'X', 'Templat' => 'Tidak ada'])->assertSessionHasErrors('Templat');
    });

    it('izin: hanya yang boleh mengelola bisa menjadwalkan dan menggandakan', function (): void {
        MasukSebagaiKontenSitus($this);
        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'izin-uji', 'Judul' => 'Izin']);
        $halaman = HalamanSitus::query()->where('Slug', 'izin-uji')->sole();
        MasukSebagaiKontenSitus($this, PeranPengelolaBawaan::Dukungan);

        $this->post(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/gandakan"))->assertForbidden();
        $this->put(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}/jadwal"), ['JadwalTerbitPada' => null])->assertForbidden();
    });
});

describe('D-21 pengaturan situs', function (): void {
    it('menyimpan pengaturan (langsung berlaku) dan menolak tautan & URL berbahaya', function (): void {
        MasukSebagaiKontenSitus($this);
        $isian = [...PengaturanSitusBerlaku::AmbilBawaan(), 'NamaSitus' => 'Payoung Indonesia'];
        $isian['Kontak']['WhatsApp'] = '081234567890';

        $this->put(BantuanPengelola::Url('/situs/pengaturan'), $isian)->assertSessionHasNoErrors();
        expect(PengaturanSitus::query()->sole()->Nilai['NamaSitus'])->toBe('Payoung Indonesia');
        $this->get(UrlSitusPublik('/'))->assertInertia(fn (AssertableInertia $h) => $h->where('Situs.NamaSitus', 'Payoung Indonesia'));
        expect(LogAuditPengelola::query()->where('Aksi', 'situs.pengaturan.ubah')->exists())->toBeTrue();

        $buruk = $isian;
        $buruk['Menu'] = [['Label' => 'X', 'Tautan' => 'javascript:alert(1)'], ['Label' => 'Y', 'Tautan' => '//evil.test']];
        $buruk['MediaSosial']['Instagram'] = 'http://instagram.com/payoung';
        $buruk['VerifikasiGoogle'] = '"><script>';
        $this->put(BantuanPengelola::Url('/situs/pengaturan'), $buruk)
            ->assertSessionHasErrors(['Menu.0.Tautan', 'Menu.1.Tautan', 'MediaSosial.Instagram', 'VerifikasiGoogle']);
    });
});

describe('D-91 menu Solusi di pengaturan situs', function (): void {
    it('butir menu utama boleh @solusi (dropdown sektor), tetapi pintasan itu ditolak di tautan lain', function (): void {
        MasukSebagaiKontenSitus($this);
        $isian = [...PengaturanSitusBerlaku::AmbilBawaan()];
        $isian['Menu'] = [['Label' => 'Solusi', 'Tautan' => '@solusi'], ['Label' => 'Harga', 'Tautan' => '/harga']];

        $this->put(BantuanPengelola::Url('/situs/pengaturan'), $isian)->assertSessionHasNoErrors();
        expect(PengaturanSitus::query()->sole()->Nilai['Menu'][0]['Tautan'])->toBe('@solusi');

        $salah = $isian;
        $salah['MenuKaki'] = [['Judul' => 'Produk', 'Tautan' => [['Label' => 'Solusi', 'Tautan' => '@solusi']]]];
        $salah['Pengumuman'] = ['Aktif' => true, 'Teks' => 'Promo', 'Tautan' => '@solusi'];
        $this->put(BantuanPengelola::Url('/situs/pengaturan'), $salah)
            ->assertSessionHasErrors(['MenuKaki.0.Tautan.0.Tautan', 'Pengumuman.Tautan']);
    });
});

describe('D-21 pustaka gambar', function (): void {
    it('unggah PNG → dilayani publik dengan cache; SVG ditolak; gambar terpakai tidak bisa dihapus', function (): void {
        MasukSebagaiKontenSitus($this);

        $this->post(BantuanPengelola::Url('/situs/gambar'), ['Berkas' => UploadedFile::fake()->image('kasir.png', 320, 200), 'TeksAlternatif' => 'Layar kasir'])
            ->assertSessionHasNoErrors();
        $gambar = GambarSitus::query()->sole();
        expect($gambar->Lebar)->toBe(320)->and($gambar->Tinggi)->toBe(200)->and($gambar->TipeMime)->toBe('image/png');
        Storage::disk('public')->assertExists($gambar->Path);

        $this->get('/gambar-situs/'.$gambar->Uuid)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post(BantuanPengelola::Url('/situs/gambar'), ['Berkas' => $svg])->assertSessionHasErrors('Berkas');

        $this->post(BantuanPengelola::Url('/situs/halaman'), ['Slug' => 'galeri', 'Judul' => 'Galeri']);
        $halaman = HalamanSitus::query()->where('Slug', 'galeri')->sole();
        $this->put(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}"), ['Slug' => 'galeri', 'Judul' => 'Galeri', 'Bagian' => [
            ['Jenis' => 'Hero', 'Judul' => 'Galeri', 'Gambar' => strtolower($gambar->Uuid)],
        ]])->assertSessionHasNoErrors();
        expect($halaman->refresh()->BagianDraf[0]['Gambar'])->toBe($gambar->Uuid);

        $this->delete(BantuanPengelola::Url('/situs/gambar/'.$gambar->Uuid))->assertSessionHasErrors();
        expect(GambarSitus::query()->count())->toBe(1);

        $this->put(BantuanPengelola::Url("/situs/halaman/{$halaman->Uuid}"), ['Slug' => 'galeri', 'Judul' => 'Galeri', 'Bagian' => BagianUjiSitus()]);
        $this->delete(BantuanPengelola::Url('/situs/gambar/'.$gambar->Uuid))->assertSessionHasNoErrors();
        expect(GambarSitus::query()->count())->toBe(0);
        Storage::disk('public')->assertMissing($gambar->Path);
    });
});
