<?php

declare(strict_types=1);

namespace App\Domain\Situs\Kueri;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Layanan\SektorSitus;
use App\Domain\Situs\Layanan\ValidatorBagianSitus;
use App\Domain\Situs\Model\GambarSitus;
use App\Domain\Situs\Model\HalamanSitus;
use App\Domain\Tenant\Kueri\PaketPublik;

/**
 * Menyusun props halaman situs pemasaran (D-21) untuk React: blok terbit (atau draf saat pratinjau), pintasan tautan
 * diterjemahkan (`@daftar` → domain tenant, `@whatsapp` → wa.me dari pengaturan), gambar → `{Url, Alt, Lebar, Tinggi}`,
 * blok Harga diisi paket aktif, Video → id YouTube, dan data SEO (judul, deskripsi, gambar OG, URL kanonik).
 * Halaman tanpa baris di tabel memakai isi bawaan (`KontenSitusBawaan`) agar situs langsung tampil setelah dipasang.
 */
final class PenyusunHalamanSitus
{
    public function __construct(
        private readonly PengaturanSitusBerlaku $pengaturan,
        private readonly PaketPublik $paket,
        private readonly MenuSolusiSitus $menuSolusi,
    ) {}

    /**
     * Halaman publik (versi terbit) untuk slug; null bila tidak ada, belum terbit, atau nonaktif.
     *
     * @return array<string, mixed>|null
     */
    public function AmbilTerbit(string $slug): ?array
    {
        $halaman = HalamanSitus::query()->where('Slug', $slug)->first();

        if ($halaman === null) {
            $bawaan = KontenSitusBawaan::AmbilHalaman()[$slug] ?? null;

            return $bawaan === null ? null : $this->Susun($slug, $bawaan['Judul'], $bawaan['JudulSeo'], $bawaan['DeskripsiSeo'], null, $bawaan['Bagian']);
        }

        if (! $halaman->Aktif || ! $halaman->CekTerbit()) {
            return null;
        }

        return $this->Susun($slug, (string) $halaman->JudulTerbit, $halaman->JudulSeoTerbit, $halaman->DeskripsiSeoTerbit, $halaman->UuidGambarOgTerbit, $halaman->BagianTerbit ?? []);
    }

    /**
     * Pratinjau draf dari konsol.
     *
     * @return array<string, mixed>
     */
    public function AmbilDraf(HalamanSitus $halaman): array
    {
        return [...$this->Susun($halaman->Slug, $halaman->Judul, $halaman->JudulSeo, $halaman->DeskripsiSeo, $halaman->UuidGambarOg, $halaman->BagianDraf), 'Pratinjau' => true, 'AsalEditor' => self::AmbilAsalEditor()];
    }

    /**
     * Pratinjau langsung editor visual: draf yang belum disimpan (blok sudah diperiksa pemanggil).
     *
     * @param  list<array<string, mixed>>  $bagian
     * @return array<string, mixed>
     */
    public function AmbilDrafSementara(string $slug, string $judul, ?string $judulSeo, ?string $deskripsiSeo, ?string $uuidGambarOg, array $bagian): array
    {
        return [...$this->Susun($slug, $judul, $judulSeo, $deskripsiSeo, $uuidGambarOg, $bagian), 'Pratinjau' => true, 'AsalEditor' => self::AmbilAsalEditor()];
    }

    /**
     * Asal (skema + host) konsol pengelola; satu-satunya pihak yang boleh membingkai pratinjau dan mengirim isi
     * langsung lewat `postMessage` (editor visual).
     */
    public static function AmbilAsalEditor(): string
    {
        $url = AlamatDomain::BuatUrl((string) config('pengelola.Domain'), '/');
        $skema = (string) parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $skema.'://'.$host.(is_int($port) ? ':'.$port : '');
    }

    /**
     * Data bersama semua halaman situs: identitas, menu, kaki, kontak, media sosial, pengumuman, tombol.
     *
     * @return array<string, mixed>
     */
    public function AmbilDataBersama(): array
    {
        $p = $this->pengaturan->Ambil();
        $logo = is_string($p['UuidLogo'] ?? null) ? $this->AmbilGambar([$p['UuidLogo']])[$p['UuidLogo']] ?? null : null;
        $tautan = fn (array $daftar): array => array_values(array_map(fn (array $t): array => ['Label' => (string) $t['Label'], 'Tautan' => $this->TerjemahkanTautan((string) $t['Tautan'], $p)], $daftar));

        return [
            'NamaSitus' => $p['NamaSitus'],
            'Slogan' => $p['Slogan'],
            'Logo' => $logo,
            'Menu' => $this->SusunMenu($p['Menu'] ?? [], $tautan),
            'MenuKaki' => array_values(array_map(fn (array $kolom): array => ['Judul' => (string) $kolom['Judul'], 'Tautan' => $tautan($kolom['Tautan'] ?? [])], $p['MenuKaki'] ?? [])),
            'TeksKaki' => $p['TeksKaki'],
            'Kontak' => [
                'WhatsApp' => $p['Kontak']['WhatsApp'],
                'TautanWhatsApp' => self::BuatTautanWhatsApp($p),
                'Email' => $p['Kontak']['Email'],
                'Telepon' => $p['Kontak']['Telepon'],
                'Alamat' => $p['Kontak']['Alamat'],
                'JamLayanan' => $p['Kontak']['JamLayanan'],
            ],
            'MediaSosial' => array_filter($p['MediaSosial'], fn ($url): bool => is_string($url) && $url !== ''),
            'Pengumuman' => ($p['Pengumuman']['Aktif'] ?? false) && is_string($p['Pengumuman']['Teks'] ?? null) && $p['Pengumuman']['Teks'] !== ''
                ? ['Teks' => $p['Pengumuman']['Teks'], 'Tautan' => is_string($p['Pengumuman']['Tautan'] ?? null) && $p['Pengumuman']['Tautan'] !== '' ? $this->TerjemahkanTautan($p['Pengumuman']['Tautan'], $p) : null]
                : null,
            'TautanUnduh' => array_filter($p['TautanUnduh'], fn ($url): bool => is_string($url) && $url !== ''),
            'TombolDaftar' => ['Label' => $p['TeksTombolDaftar'], 'Tautan' => AlamatDomain::BuatUrlTenant('/daftar')],
            'TombolMasuk' => ['Label' => $p['TeksTombolMasuk'], 'Tautan' => AlamatDomain::BuatUrlTenant('/masuk')],
            'WhatsAppMelayang' => (bool) $p['TombolWhatsAppMelayang'] && self::BuatTautanWhatsApp($p) !== null,
            'Tahun' => (int) now('Asia/Jakarta')->format('Y'),
            // Bagian B: dimuat di peramban hanya setelah pengunjung menerima cookie analitik.
            'Analitik' => [
                'IdGoogleAnalytics' => is_string($p['Analitik']['IdGoogleAnalytics'] ?? null) ? $p['Analitik']['IdGoogleAnalytics'] : null,
                'IdMetaPixel' => is_string($p['Analitik']['IdMetaPixel'] ?? null) ? $p['Analitik']['IdMetaPixel'] : null,
            ],
        ];
    }

    /**
     * Menu utama kepala situs. Butir `@solusi` menjadi dropdown sektor (`MenuSolusiSitus`, D-91) yang membawa `Solusi`
     * (kolom per kelompok). Pengaturan lama yang masih memuat tiga butir kelompok datar (Kafe & Resto, Toko & Retail,
     * Jasa) dilipat menjadi satu dropdown "Solusi" supaya pemasangan yang menyimpan menunya di konsol ikut berubah
     * tanpa harus menyunting menu. Bila tidak ada sektor tampil, butir `@solusi` dihilangkan.
     *
     * @param  list<array<string, mixed>>  $menu
     * @param  callable(list<array<string, mixed>>): list<array{Label: string, Tautan: string}>  $tautan
     * @return list<array<string, mixed>>
     */
    private function SusunMenu(array $menu, callable $tautan): array
    {
        $kelompokDatar = array_map(static fn (string $k): string => '/solusi/'.$k, array_keys(SektorSitus::KELOMPOK));
        $tautanMenu = array_map(static fn (array $m): string => (string) $m['Tautan'], $menu);
        $lama = ! in_array('@solusi', $tautanMenu, true) && array_diff($kelompokDatar, $tautanMenu) === [];
        $solusi = null;
        $hasil = [];
        $sudah = false;

        foreach ($menu as $butir) {
            $nilai = (string) $butir['Tautan'];

            if ($nilai === '@solusi' || ($lama && in_array($nilai, $kelompokDatar, true))) {
                if ($sudah) {
                    continue;
                }

                $sudah = true;
                $solusi ??= $this->menuSolusi->Ambil();

                if ($solusi !== []) {
                    $hasil[] = ['Label' => $nilai === '@solusi' ? (string) $butir['Label'] : 'Solusi', 'Tautan' => '@solusi', 'Solusi' => $solusi];
                }

                continue;
            }

            $hasil[] = $tautan([$butir])[0];
        }

        return $hasil;
    }

    /**
     * @param  list<array<string, mixed>>  $bagian
     * @return array<string, mixed>
     */
    private function Susun(string $slug, string $judul, ?string $judulSeo, ?string $deskripsiSeo, ?string $uuidGambarOg, array $bagian): array
    {
        $p = $this->pengaturan->Ambil();
        $uuidGambar = $this->KumpulkanGambar($bagian);
        $ogBawaan = is_string($p['UuidGambarOg'] ?? null) ? $p['UuidGambarOg'] : null;
        $og = $uuidGambarOg ?? $ogBawaan;

        if ($og !== null) {
            $uuidGambar[] = $og;
        }

        $gambar = $this->AmbilGambar($uuidGambar);
        $paket = null;
        $hasil = [];

        foreach ($bagian as $blok) {
            if (($blok['Jenis'] ?? null) === 'Harga') {
                $paket ??= $this->paket->Ambil();
                $blok['Paket'] = $paket;
                $blok['TautanDaftar'] = AlamatDomain::BuatUrlTenant('/daftar');
            }

            if (($blok['Jenis'] ?? null) === 'Video') {
                $blok['IdYoutube'] = ValidatorBagianSitus::AmbilIdYoutube((string) ($blok['UrlYoutube'] ?? ''));
            }

            $hasil[] = $this->TerjemahkanNilai($blok, $gambar, $p);
        }

        $jalur = $slug === HalamanSitus::SLUG_BERANDA ? '/' : '/'.$slug;

        return [
            'Slug' => $slug,
            'Judul' => $judul,
            'Bagian' => $hasil,
            'Seo' => [
                'Judul' => $judulSeo !== null && $judulSeo !== '' ? $judulSeo : ($slug === HalamanSitus::SLUG_BERANDA ? $p['JudulSeo'] : $judul.' | '.$p['NamaSitus']),
                'Deskripsi' => $deskripsiSeo !== null && $deskripsiSeo !== '' ? $deskripsiSeo : $p['DeskripsiSeo'],
                'KataKunci' => $p['KataKunci'],
                'Gambar' => $og !== null ? ($gambar[$og]['UrlAbsolut'] ?? null) : null,
                'Kanonik' => AlamatDomain::BuatUrlAbsolutPemasaran($jalur),
                'NamaSitus' => $p['NamaSitus'],
                'VerifikasiGoogle' => $p['VerifikasiGoogle'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public static function BuatTautanWhatsApp(array $p): ?string
    {
        $nomor = preg_replace('/\D/', '', (string) ($p['Kontak']['WhatsApp'] ?? '')) ?? '';

        if ($nomor === '') {
            return null;
        }

        if (str_starts_with($nomor, '0')) {
            $nomor = '62'.substr($nomor, 1);
        }

        $pesan = (string) ($p['Kontak']['PesanWhatsApp'] ?? '');

        return 'https://wa.me/'.$nomor.($pesan !== '' ? '?text='.rawurlencode($pesan) : '');
    }

    /**
     * Terjemahkan pintasan tautan ke alamat nyata.
     *
     * @param  array<string, mixed>  $p
     */
    public function TerjemahkanTautan(string $tautan, array $p): string
    {
        return match ($tautan) {
            '@daftar' => AlamatDomain::BuatUrlTenant('/daftar'),
            '@masuk' => AlamatDomain::BuatUrlTenant('/masuk'),
            '@whatsapp' => self::BuatTautanWhatsApp($p) ?? '/kontak',
            '@unduh-android' => (string) ($p['TautanUnduh']['Android'] ?? '') ?: '/kontak',
            '@unduh-ios' => (string) ($p['TautanUnduh']['Ios'] ?? '') ?: '/kontak',
            '@unduh-windows' => (string) ($p['TautanUnduh']['Windows'] ?? '') ?: '/kontak',
            default => $tautan,
        };
    }

    /**
     * Terjemahkan rekursif: bidang `Gambar`/`Foto` → objek gambar, `Tautan` → alamat nyata.
     *
     * @param  array<int|string, mixed>  $nilai
     * @param  array<string, array<string, mixed>>  $gambar
     * @param  array<string, mixed>  $p
     * @return array<int|string, mixed>
     */
    private function TerjemahkanNilai(array $nilai, array $gambar, array $p): array
    {
        foreach ($nilai as $kunci => $isi) {
            if (($kunci === 'Gambar' || $kunci === 'Foto') && is_string($isi)) {
                $nilai[$kunci] = $gambar[$isi] ?? null;
            } elseif ($kunci === 'Tautan' && is_string($isi)) {
                $nilai[$kunci] = $this->TerjemahkanTautan($isi, $p);
            } elseif (is_array($isi) && $kunci !== 'Paket') {
                $nilai[$kunci] = $this->TerjemahkanNilai($isi, $gambar, $p);
            }
        }

        return $nilai;
    }

    /**
     * @param  array<int|string, mixed>  $nilai
     * @return list<string>
     */
    private function KumpulkanGambar(array $nilai): array
    {
        $hasil = [];

        foreach ($nilai as $kunci => $isi) {
            if (($kunci === 'Gambar' || $kunci === 'Foto') && is_string($isi)) {
                $hasil[] = $isi;
            } elseif (is_array($isi)) {
                array_push($hasil, ...$this->KumpulkanGambar($isi));
            }
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $uuid
     * @return array<string, array{Uuid: string, Url: string, UrlAbsolut: string, Alt: string, Lebar: int|null, Tinggi: int|null}>
     */
    private function AmbilGambar(array $uuid): array
    {
        if ($uuid === []) {
            return [];
        }

        $hasil = [];

        foreach (GambarSitus::query()->whereIn('Uuid', array_unique($uuid))->get() as $g) {
            $hasil[$g->Uuid] = [
                'Uuid' => $g->Uuid,
                'Url' => '/gambar-situs/'.$g->Uuid,
                'UrlAbsolut' => AlamatDomain::BuatUrlAbsolutPemasaran('/gambar-situs/'.$g->Uuid),
                'Alt' => (string) ($g->TeksAlternatif ?? ''),
                'Lebar' => $g->Lebar,
                'Tinggi' => $g->Tinggi,
            ];
        }

        return $hasil;
    }
}
