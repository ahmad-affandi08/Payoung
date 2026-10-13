<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\TemplateSektor;

use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\TemplateSektor\Aksi\BuatTemplateSektor;
use App\Domain\Pengelola\TemplateSektor\Aksi\DuplikasiVersiTemplate;
use App\Domain\Pengelola\TemplateSektor\Aksi\HapusDrafTemplate;
use App\Domain\Pengelola\TemplateSektor\Aksi\SimpanIsiTemplate;
use App\Domain\Pengelola\TemplateSektor\Aksi\TerbitkanTemplate;
use App\Domain\Pengelola\TemplateSektor\Aksi\UbahKeaktifanTemplate;
use App\Domain\Pengelola\TemplateSektor\Aksi\UjiCobaTemplateSektor;
use App\Domain\Pengelola\TemplateSektor\Aksi\ValidasiVersiTemplate;
use App\Domain\Pengelola\TemplateSektor\Enum\BagianTemplate;
use App\Domain\Pengelola\TemplateSektor\Kueri\DaftarTemplateSektor;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\TemplateSektor\BuatTemplateSektorPermintaan;
use App\Http\Permintaan\Pengelola\TemplateSektor\SimpanAkunTemplatePermintaan;
use App\Http\Permintaan\Pengelola\TemplateSektor\SimpanIsiBisnisTemplatePermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template sektor berversi (P-03).
 */
final class TemplateSektorKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(DaftarTemplateSektor $kueri): Response
    {
        return Inertia::render('Pengelola/TemplateSektor/Daftar', ['Template' => $kueri->AmbilDaftar()]);
    }

    public function Buat(BuatTemplateSektorPermintaan $permintaan, BuatTemplateSektor $buat): RedirectResponse
    {
        $template = $buat->Jalankan($this->AmbilPelaku(), $permintaan->AmbilData());

        return redirect()
            ->route('pengelola.template-sektor.versi.tampil', [$template, 1])
            ->with('Kilat', "Template {$template->Kode} dibuat sebagai draf versi 1.");
    }

    public function Tampilkan(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, DaftarTemplateSektor $kueri): Response
    {
        return Inertia::render('Pengelola/TemplateSektor/Editor', [
            ...$kueri->AmbilVersi($templateSektor, $templateSektorVersi),
            'Pilihan' => $kueri->AmbilPilihanEditor(),
        ]);
    }

    public function Duplikasi(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, DuplikasiVersiTemplate $duplikasi): RedirectResponse
    {
        $draf = $duplikasi->Jalankan($this->AmbilPelaku(), $templateSektorVersi);

        return redirect()
            ->route('pengelola.template-sektor.versi.tampil', [$templateSektor, $draf->Versi])
            ->with('Kilat', "Draf versi {$draf->Versi} dibuat dari versi {$templateSektorVersi->Versi}.");
    }

    public function SimpanIsiBisnis(
        TemplateSektor $templateSektor,
        TemplateSektorVersi $templateSektorVersi,
        SimpanIsiBisnisTemplatePermintaan $permintaan,
        SimpanIsiTemplate $simpan,
    ): RedirectResponse {
        $versi = $simpan->Jalankan($this->AmbilPelaku(), $templateSektorVersi, BagianTemplate::IsiBisnis, $permintaan->AmbilIsi());

        return back()->with('Kilat', self::AmbilPesanSimpan($versi));
    }

    public function SimpanAkun(
        TemplateSektor $templateSektor,
        TemplateSektorVersi $templateSektorVersi,
        SimpanAkunTemplatePermintaan $permintaan,
        SimpanIsiTemplate $simpan,
    ): RedirectResponse {
        $versi = $simpan->Jalankan($this->AmbilPelaku(), $templateSektorVersi, BagianTemplate::Akun, $permintaan->AmbilIsi());

        return back()->with('Kilat', self::AmbilPesanSimpan($versi));
    }

    public function Validasi(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, ValidasiVersiTemplate $validasi): RedirectResponse
    {
        $hasil = $validasi->Jalankan($templateSektorVersi);

        return back()->with('Kilat', $hasil['Lolos'] ? 'Validasi lolos. Template siap diterbitkan.' : 'Validasi menemukan '.count($hasil['Galat']).' masalah.');
    }

    /** P-03 langkah 4: pratinjau sandbox. Jawaban JSON; semua data uji dibatalkan di server (tidak ada yang tersimpan). */
    public function UjiCoba(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, UjiCobaTemplateSektor $uji): JsonResponse
    {
        return response()->json($uji->Jalankan($templateSektorVersi));
    }

    public function Terbitkan(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, TerbitkanTemplate $terbitkan): RedirectResponse
    {
        $versi = $terbitkan->Jalankan($this->AmbilPelaku(), $templateSektorVersi);

        return back()->with('Kilat', "Template {$templateSektor->Kode} versi {$versi->Versi} terbit. Tenant baru memakai versi ini.");
    }

    public function HapusDraf(TemplateSektor $templateSektor, TemplateSektorVersi $templateSektorVersi, HapusDrafTemplate $hapus): RedirectResponse
    {
        $templateIkutTerhapus = $hapus->Jalankan($this->AmbilPelaku(), $templateSektorVersi);
        $pesan = $templateIkutTerhapus ? "Template {$templateSektor->Kode} dihapus." : "Draf versi {$templateSektorVersi->Versi} dihapus.";

        return redirect()->route('pengelola.template-sektor.daftar')->with('Kilat', $pesan);
    }

    public function Nonaktifkan(TemplateSektor $templateSektor, UbahKeaktifanTemplate $ubah): RedirectResponse
    {
        $ubah->Jalankan($this->AmbilPelaku(), $templateSektor, false);

        return redirect()->route('pengelola.template-sektor.daftar')
            ->with('Kilat', "Template {$templateSektor->Kode} dinonaktifkan. Tidak ditawarkan ke tenant baru; tenant lama tidak berubah.");
    }

    public function Aktifkan(TemplateSektor $templateSektor, UbahKeaktifanTemplate $ubah): RedirectResponse
    {
        $ubah->Jalankan($this->AmbilPelaku(), $templateSektor, true);

        return redirect()->route('pengelola.template-sektor.daftar')->with('Kilat', "Template {$templateSektor->Kode} diaktifkan kembali.");
    }

    private static function AmbilPesanSimpan(TemplateSektorVersi $versi): string
    {
        return $versi->CekLolosValidasi()
            ? 'Draf disimpan. Validasi lolos.'
            : 'Draf disimpan. Masih ada '.count($versi->HasilValidasi['Galat'] ?? []).' masalah validasi.';
    }
}
