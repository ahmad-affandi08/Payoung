<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Situs\Model\ArtikelBantuan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PLT-12: membuat (draf) atau menyimpan artikel Pusat Bantuan. Slug satu segmen & unik (kosong = dari judul). `Konteks`
 * hanya awalan jalur back-office (`/kelola/...`, paling banyak 10, tanpa duplikat); `UrlVideo` wajib https dari penyedia
 * video yang dikenal. Artikel terbit langsung berubah di situs dan di bantuan kontekstual setelah disimpan.
 */
final class SimpanArtikelBantuan
{
    /** Penyedia video yang boleh ditautkan. */
    public const HOST_VIDEO = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com'];

    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    /**
     * @param  array{Slug: string|null, Judul: string, Ringkasan: string|null, Isi: string, Kategori: string|null, UrlVideo: string|null, Konteks: list<string>, Urutan: int}  $data
     */
    public function Jalankan(PenggunaPengelola $pelaku, array $data, ?ArtikelBantuan $artikel = null): ArtikelBantuan
    {
        $slug = strtolower(trim((string) $data['Slug']));
        $slug = $slug === '' ? Str::slug($data['Judul']) : $slug;
        $slug = substr($slug, 0, 120);

        if (preg_match('#^'.ArtikelBantuan::POLA_SLUG.'$#', $slug) !== 1) {
            throw new PelanggaranAturanBisnis('SlugTidakValid', 'Slug hanya huruf kecil, angka, dan tanda hubung (misal "cara-tambah-produk").', 'Slug');
        }

        $video = self::Rapikan($data['UrlVideo']);

        if ($video !== null && ! self::CekVideoSah($video)) {
            throw new PelanggaranAturanBisnis('VideoTidakValid', 'Tautan video harus https dari YouTube atau Vimeo.', 'UrlVideo');
        }

        $konteks = self::RapikanKonteks($data['Konteks']);

        return DB::transaction(function () use ($pelaku, $data, $artikel, $slug, $video, $konteks): ArtikelBantuan {
            $kueriSlug = ArtikelBantuan::query()->where('Slug', $slug);

            if ($artikel !== null) {
                $kueriSlug->whereKeyNot($artikel->Id);
            }

            if ($kueriSlug->exists()) {
                throw new PelanggaranAturanBisnis('SlugSudahDipakai', 'Slug ini sudah dipakai artikel bantuan lain.', 'Slug');
            }

            $baru = $artikel === null;
            $artikel ??= new ArtikelBantuan;
            $lama = $baru ? null : $artikel->only(['Slug', 'Judul', 'Kategori', 'Konteks']);
            $artikel->fill([
                'Slug' => $slug,
                'Judul' => trim($data['Judul']),
                'Ringkasan' => self::Rapikan($data['Ringkasan']),
                'Isi' => trim($data['Isi']),
                'Kategori' => self::Rapikan($data['Kategori']),
                'UrlVideo' => $video,
                'Konteks' => $konteks,
                'Urutan' => max(0, min(9999, $data['Urutan'])),
                'IdPenggunaPengelolaPengubah' => $pelaku->Id,
            ])->save();

            $this->audit->Catat($baru ? 'situs.bantuan.buat' : 'situs.bantuan.ubah', $artikel, nilaiLama: $lama, nilaiBaru: [
                'Slug' => $artikel->Slug,
                'Judul' => $artikel->Judul,
                'Kategori' => $artikel->Kategori,
                'Konteks' => $artikel->Konteks,
            ]);

            return $artikel;
        });
    }

    public static function CekVideoSah(string $url): bool
    {
        $bagian = parse_url($url);

        return is_array($bagian)
            && ($bagian['scheme'] ?? '') === 'https'
            && in_array(strtolower((string) ($bagian['host'] ?? '')), self::HOST_VIDEO, true)
            && ! isset($bagian['user'])
            && ! isset($bagian['pass'])
            && strlen($url) <= 300;
    }

    /**
     * @param  list<string>  $konteks
     * @return list<string>
     */
    private static function RapikanKonteks(array $konteks): array
    {
        $hasil = [];

        foreach ($konteks as $jalur) {
            $jalur = '/'.trim(strtolower(trim($jalur)), '/');

            if (preg_match('#^'.ArtikelBantuan::POLA_KONTEKS.'$#', $jalur) !== 1) {
                throw new PelanggaranAturanBisnis('KonteksTidakValid', "Konteks \"{$jalur}\" tidak sah. Pakai jalur back-office seperti /kelola/produk.", 'Konteks');
            }

            if (! in_array($jalur, $hasil, true)) {
                $hasil[] = $jalur;
            }
        }

        if (count($hasil) > 10) {
            throw new PelanggaranAturanBisnis('KonteksTerlaluBanyak', 'Paling banyak 10 halaman konteks per artikel.', 'Konteks');
        }

        return $hasil;
    }

    private static function Rapikan(?string $teks): ?string
    {
        $teks = $teks === null ? null : trim($teks);

        return $teks === '' ? null : $teks;
    }
}
