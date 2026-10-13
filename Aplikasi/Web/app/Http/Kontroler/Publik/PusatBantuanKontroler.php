<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Situs\Kueri\PusatBantuanPublik;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PLT-12 Pusat Bantuan publik di domain pemasaran: `/pusat-bantuan` (daftar per kategori, `?cari=`, `?kategori=`) dan
 * `/pusat-bantuan/{slug}`. Hanya artikel terbit; draf tidak pernah tampil.
 */
final class PusatBantuanKontroler extends Kontroler
{
    public function Daftar(Request $permintaan, PusatBantuanPublik $bantuan): Response
    {
        $cari = $permintaan->query('cari');
        $kategori = $permintaan->query('kategori');
        $data = $bantuan->AmbilDaftar(is_string($cari) ? $cari : null, is_string($kategori) ? mb_substr($kategori, 0, 60) : null);

        return Inertia::render('Situs/PusatBantuan', [
            'Halaman' => ['Seo' => $data['Seo']],
            'Kelompok' => $data['Kelompok'],
            'Kategori' => $data['Kategori'],
            'KategoriAktif' => $data['KategoriAktif'],
            'Cari' => $data['Cari'],
            'Total' => $data['Total'],
        ]);
    }

    public function Artikel(string $slugBantuan, PusatBantuanPublik $bantuan): Response
    {
        $data = $bantuan->AmbilArtikel($slugBantuan);
        abort_if($data === null, 404);

        return Inertia::render('Situs/ArtikelBantuan', [
            'Halaman' => ['Seo' => $data['Seo']],
            'Artikel' => $data['Artikel'],
            'Terkait' => $data['Terkait'],
        ]);
    }
}
