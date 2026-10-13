<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Konten;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Pengelola\Konten\Aksi\HapusArtikelBantuan;
use App\Domain\Pengelola\Konten\Aksi\SimpanArtikelBantuan;
use App\Domain\Pengelola\Konten\Aksi\UbahStatusArtikelBantuan;
use App\Domain\Pengelola\Konten\Kueri\DaftarArtikelBantuan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PLT-12: artikel Pusat Bantuan di konsol. Lihat: `situs.lihat`; tulis, terbitkan, tarik, hapus: `situs.kelola`.
 */
final class ArtikelBantuanKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(Request $permintaan, DaftarArtikelBantuan $daftar): JsonResponse|Response
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarArtikelBantuan::KOLOM_URUT, '', DaftarArtikelBantuan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Situs/Bantuan/Daftar', 'Artikel', fn (): array => $daftar->AmbilTabel($tabel), fn (): array => [
            'PilihanKategori' => $daftar->AmbilKategori(),
            'UrlPusatBantuan' => AlamatDomain::BuatUrlAbsolutPemasaran('/pusat-bantuan'),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function Buat(Request $permintaan, SimpanArtikelBantuan $simpan): RedirectResponse
    {
        $valid = $permintaan->validate(['Judul' => ['required', 'string', 'max:150']], attributes: ['Judul' => 'judul']);
        $artikel = $simpan->Jalankan($this->AmbilPelaku(), [
            'Slug' => null, 'Judul' => (string) $valid['Judul'], 'Ringkasan' => null, 'Isi' => '', 'Kategori' => null,
            'UrlVideo' => null, 'Konteks' => [], 'Urutan' => 0,
        ]);

        return redirect()->route('pengelola.situs.bantuan.ubah', $artikel->Uuid)->with('Kilat', 'Artikel bantuan dibuat sebagai draf. Tulis isinya lalu terbitkan.');
    }

    public function Ubah(string $artikelBantuan, DaftarArtikelBantuan $daftar): Response
    {
        $artikel = $this->AmbilArtikel($artikelBantuan);

        return Inertia::render('Pengelola/Situs/Bantuan/Ubah', [
            'Artikel' => $daftar->PetakanLengkap($artikel),
            'PilihanKategori' => $daftar->AmbilKategori(),
            'UrlArtikel' => AlamatDomain::BuatUrlAbsolutPemasaran('/pusat-bantuan/'.$artikel->Slug),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function Simpan(Request $permintaan, string $artikelBantuan, SimpanArtikelBantuan $simpan): RedirectResponse
    {
        $artikel = $this->AmbilArtikel($artikelBantuan);
        $valid = $permintaan->validate([
            'Slug' => ['nullable', 'string', 'max:120'],
            'Judul' => ['required', 'string', 'max:150'],
            'Ringkasan' => ['nullable', 'string', 'max:300'],
            'Isi' => ['nullable', 'string', 'max:60000'],
            'Kategori' => ['nullable', 'string', 'max:60'],
            'UrlVideo' => ['nullable', 'string', 'max:300'],
            'Konteks' => ['nullable', 'array', 'max:10'],
            'Konteks.*' => ['string', 'max:200'],
            'Urutan' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], attributes: ['Judul' => 'judul', 'Ringkasan' => 'ringkasan', 'Isi' => 'isi', 'UrlVideo' => 'tautan video', 'Konteks' => 'halaman konteks', 'Urutan' => 'urutan']);

        if ($artikel->CekTerbit() && trim((string) ($valid['Isi'] ?? '')) === '') {
            return back()->withErrors(['Isi' => 'Artikel yang sudah terbit wajib punya isi.']);
        }

        $simpan->Jalankan($this->AmbilPelaku(), [
            'Slug' => $valid['Slug'] ?? null,
            'Judul' => (string) $valid['Judul'],
            'Ringkasan' => $valid['Ringkasan'] ?? null,
            'Isi' => (string) ($valid['Isi'] ?? ''),
            'Kategori' => $valid['Kategori'] ?? null,
            'UrlVideo' => $valid['UrlVideo'] ?? null,
            'Konteks' => array_values(array_map('strval', $valid['Konteks'] ?? [])),
            'Urutan' => (int) ($valid['Urutan'] ?? 0),
        ], $artikel);

        return back()->with('Kilat', $artikel->CekTerbit() ? 'Artikel disimpan dan langsung berubah di Pusat Bantuan.' : 'Draf artikel disimpan.');
    }

    public function Terbitkan(string $artikelBantuan, UbahStatusArtikelBantuan $ubah): RedirectResponse
    {
        $artikel = $this->AmbilArtikel($artikelBantuan);

        if (trim($artikel->Isi) === '') {
            return back()->withErrors(['Isi' => 'Tulis isi artikel dan simpan dulu sebelum menerbitkan.']);
        }

        $ubah->Jalankan($this->AmbilPelaku(), $artikel, StatusArtikel::Terbit);

        return back()->with('Kilat', "Artikel {$artikel->Judul} diterbitkan.");
    }

    public function Tarik(string $artikelBantuan, UbahStatusArtikelBantuan $ubah): RedirectResponse
    {
        $ubah->Jalankan($this->AmbilPelaku(), $this->AmbilArtikel($artikelBantuan), StatusArtikel::Draf);

        return back()->with('Kilat', 'Artikel ditarik ke draf dan tidak tampil lagi di Pusat Bantuan.');
    }

    public function Hapus(string $artikelBantuan, HapusArtikelBantuan $hapus): RedirectResponse
    {
        $hapus->Jalankan($this->AmbilPelaku(), $this->AmbilArtikel($artikelBantuan));

        return redirect()->route('pengelola.situs.bantuan.daftar')->with('Kilat', 'Artikel bantuan dihapus.');
    }

    private function AmbilArtikel(string $uuid): ArtikelBantuan
    {
        return ArtikelBantuan::query()->where('Uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array{Kelola: bool}
     */
    private function AmbilIzin(): array
    {
        return ['Kelola' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::SitusKelola)];
    }
}
