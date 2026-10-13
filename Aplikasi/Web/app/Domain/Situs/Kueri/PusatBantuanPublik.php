<?php

declare(strict_types=1);

namespace App\Domain\Situs\Kueri;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;
use App\Domain\Situs\Model\GambarSitus;
use Illuminate\Database\Eloquent\Builder;

/**
 * PLT-12 Pusat Bantuan publik: `/pusat-bantuan` (artikel terbit dikelompokkan per kategori, cari judul/ringkasan/isi,
 * saring kategori), `/pusat-bantuan/{slug}` (isi + artikel terkait sekategori), dan peta situs. Props memuat `Seo` yang
 * dibaca view root untuk meta & JSON-LD.
 */
final class PusatBantuanPublik
{
    public const BATAS_DAFTAR = 200;

    public function __construct(private readonly PengaturanSitusBerlaku $pengaturan) {}

    /**
     * @return array{Kelompok: list<array{Kategori: string, Artikel: list<array<string, mixed>>}>, Kategori: list<string>, KategoriAktif: string|null, Cari: string, Total: int, Seo: array<string, mixed>}
     */
    public function AmbilDaftar(?string $cari, ?string $kategori): array
    {
        $cari = $cari === null ? '' : trim(mb_substr($cari, 0, 80));
        $kueri = $this->KueriTerbit();

        if ($kategori !== null && $kategori !== '') {
            $kueri->where('Kategori', $kategori);
        }

        if ($cari !== '') {
            $pola = '%'.addcslashes($cari, '\\%_').'%';
            $kueri->where(fn (Builder $b) => $b->where('Judul', 'like', $pola)->orWhere('Ringkasan', 'like', $pola)->orWhere('Isi', 'like', $pola));
        }

        $baris = $kueri->orderBy('Urutan')->orderBy('Judul')->limit(self::BATAS_DAFTAR)->get(['Slug', 'Judul', 'Ringkasan', 'Kategori', 'UrlVideo']);
        $kelompok = [];

        foreach ($baris as $a) {
            $nama = $a->Kategori ?? 'Lainnya';
            $kelompok[$nama][] = ['Slug' => $a->Slug, 'Judul' => $a->Judul, 'Ringkasan' => $a->Ringkasan, 'UrlVideo' => $a->UrlVideo];
        }

        ksort($kelompok);
        $p = $this->pengaturan->Ambil();

        return [
            'Kelompok' => array_values(array_map(fn (string $nama, array $artikel): array => ['Kategori' => $nama, 'Artikel' => $artikel], array_keys($kelompok), $kelompok)),
            'Kategori' => array_values(array_filter($this->KueriTerbit()->whereNotNull('Kategori')->distinct()->orderBy('Kategori')->pluck('Kategori')->all(), 'is_string')),
            'KategoriAktif' => $kategori !== null && $kategori !== '' ? $kategori : null,
            'Cari' => $cari,
            'Total' => $baris->count(),
            'Seo' => $this->BuatSeo('Pusat Bantuan | '.$p['NamaSitus'], 'Panduan memakai '.$p['NamaSitus'].': kasir, stok, laporan, dan pengaturan usaha Anda.', '/pusat-bantuan', 'website', $cari !== '' || ($kategori !== null && $kategori !== '')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function AmbilArtikel(string $slug): ?array
    {
        $artikel = $this->KueriTerbit()->where('Slug', $slug)->first();

        if ($artikel === null) {
            return null;
        }

        $terkait = $this->KueriTerbit()->whereKeyNot($artikel->Id)
            ->when($artikel->Kategori !== null, fn (Builder $k) => $k->where('Kategori', $artikel->Kategori))
            ->orderBy('Urutan')->orderBy('Judul')->limit(4)->get(['Slug', 'Judul', 'Ringkasan']);
        $p = $this->pengaturan->Ambil();

        return [
            'Artikel' => [
                'Slug' => $artikel->Slug,
                'Judul' => $artikel->Judul,
                'Ringkasan' => $artikel->Ringkasan,
                'Kategori' => $artikel->Kategori,
                'UrlVideo' => $artikel->UrlVideo,
                'Isi' => $artikel->Isi,
                'DiperbaruiPada' => $artikel->DiubahPada?->toIso8601String(),
            ],
            'Terkait' => array_values($terkait->map(fn (ArtikelBantuan $a): array => ['Slug' => $a->Slug, 'Judul' => $a->Judul, 'Ringkasan' => $a->Ringkasan])->all()),
            'Seo' => $this->BuatSeo($artikel->Judul.' | Pusat Bantuan '.$p['NamaSitus'], $artikel->Ringkasan ?? $p['DeskripsiSeo'], '/pusat-bantuan/'.$artikel->Slug, 'article'),
        ];
    }

    /**
     * Artikel terbit untuk peta situs.
     *
     * @return list<array{Slug: string, DiubahPada: string|null}>
     */
    public function AmbilUntukPetaSitus(): array
    {
        return array_values($this->KueriTerbit()->orderBy('Urutan')->orderBy('Judul')->get(['Slug', 'DiubahPada'])
            ->map(fn (ArtikelBantuan $a): array => ['Slug' => $a->Slug, 'DiubahPada' => $a->DiubahPada?->toDateString()])
            ->all());
    }

    /**
     * @return Builder<ArtikelBantuan>
     */
    private function KueriTerbit(): Builder
    {
        return ArtikelBantuan::query()->where('Status', StatusArtikel::Terbit->value)->whereNotNull('DiterbitkanPada');
    }

    /**
     * @return array<string, mixed>
     */
    private function BuatSeo(string $judul, string $deskripsi, string $jalur, string $jenis, bool $tanpaIndeks = false): array
    {
        $p = $this->pengaturan->Ambil();
        $uuidOg = $p['UuidGambarOg'] ?? null;
        $og = is_string($uuidOg) && GambarSitus::query()->where('Uuid', $uuidOg)->exists()
            ? AlamatDomain::BuatUrlAbsolutPemasaran('/gambar-situs/'.$uuidOg)
            : null;

        return [
            'Judul' => mb_substr($judul, 0, 120),
            'Deskripsi' => mb_substr($deskripsi, 0, 300),
            'KataKunci' => $p['KataKunci'],
            'Gambar' => $og,
            'Kanonik' => AlamatDomain::BuatUrlAbsolutPemasaran($jalur),
            'NamaSitus' => $p['NamaSitus'],
            'VerifikasiGoogle' => $p['VerifikasiGoogle'],
            'Jenis' => $jenis,
            'Artikel' => null,
            'TanpaIndeks' => $tanpaIndeks,
        ];
    }
}
