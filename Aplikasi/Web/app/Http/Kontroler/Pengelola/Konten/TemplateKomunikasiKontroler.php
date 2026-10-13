<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Konten;

use App\Domain\Pengelola\Konten\Aksi\CatatStatusBspTemplat;
use App\Domain\Pengelola\Konten\Aksi\HapusDrafTemplatKomunikasi;
use App\Domain\Pengelola\Konten\Aksi\SimpanDrafTemplatKomunikasi;
use App\Domain\Pengelola\Konten\Aksi\TerbitkanTemplatKomunikasi;
use App\Domain\Pengelola\Konten\Kueri\DaftarTemplatKomunikasi;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusBspTemplat;
use App\Domain\Tenant\Model\TemplateKomunikasi;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PGL-07 (P-06): templat komunikasi platform ke Owner tenant (saat ini dunning tagihan langganan). Lihat: `legal.lihat`;
 * simpan draf, terbitkan, hapus draf, catat status BSP: `legal.kelola`. Kombinasi diidentifikasi kode peristiwa + kanal
 * (`email`|`whatsapp`) + bahasa (`id`|`en`) di URL.
 */
final class TemplateKomunikasiKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(DaftarTemplatKomunikasi $daftar): Response
    {
        return Inertia::render('Pengelola/Legal/TemplatKomunikasi/Daftar', [
            'Peristiwa' => $daftar->AmbilDaftar(),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function Ubah(string $kodePeristiwa, string $kanal, string $bahasa, DaftarTemplatKomunikasi $daftar): Response
    {
        [$peristiwa, $kanalEnum, $bahasaEnum] = $this->UraiKombinasi($kodePeristiwa, $kanal, $bahasa);

        return Inertia::render('Pengelola/Legal/TemplatKomunikasi/Ubah', [
            'Editor' => $daftar->AmbilEditor($peristiwa, $kanalEnum, $bahasaEnum),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function SimpanDraf(Request $permintaan, string $kodePeristiwa, string $kanal, string $bahasa, SimpanDrafTemplatKomunikasi $simpan): RedirectResponse
    {
        [$peristiwa, $kanalEnum, $bahasaEnum] = $this->UraiKombinasi($kodePeristiwa, $kanal, $bahasa);
        $valid = $permintaan->validate([
            'Subjek' => ['nullable', 'string', 'max:'.SimpanDrafTemplatKomunikasi::BATAS_SUBJEK * 2],
            'Judul' => ['nullable', 'string', 'max:'.SimpanDrafTemplatKomunikasi::BATAS_JUDUL * 2],
            'Isi' => ['required', 'string', 'max:'.SimpanDrafTemplatKomunikasi::BATAS_ISI_EMAIL * 2],
        ], attributes: ['Subjek' => 'subjek', 'Judul' => 'judul', 'Isi' => 'isi pesan']);

        $simpan->Jalankan($this->AmbilPelaku(), $peristiwa, $kanalEnum, $bahasaEnum, $valid['Subjek'] ?? null, $valid['Judul'] ?? null, (string) $valid['Isi']);

        return back()->with('Kilat', 'Draf templat disimpan. Terbitkan agar dipakai untuk pesan berikutnya.');
    }

    public function Terbitkan(string $templatKomunikasi, TerbitkanTemplatKomunikasi $terbitkan): RedirectResponse
    {
        $templat = $terbitkan->Jalankan($this->AmbilPelaku(), $this->AmbilTemplat($templatKomunikasi));

        return back()->with('Kilat', "Templat versi {$templat->Versi} diterbitkan dan dipakai untuk pesan berikutnya.");
    }

    public function HapusDraf(string $templatKomunikasi, HapusDrafTemplatKomunikasi $hapus): RedirectResponse
    {
        $hapus->Jalankan($this->AmbilPelaku(), $this->AmbilTemplat($templatKomunikasi));

        return back()->with('Kilat', 'Draf templat dihapus.');
    }

    public function CatatStatusBsp(Request $permintaan, string $templatKomunikasi, CatatStatusBspTemplat $catat): RedirectResponse
    {
        $valid = $permintaan->validate([
            'StatusBsp' => ['nullable', Rule::enum(StatusBspTemplat::class)],
            'CatatanBsp' => ['nullable', 'string', 'max:500'],
        ], attributes: ['StatusBsp' => 'status BSP', 'CatatanBsp' => 'catatan']);

        $catat->Jalankan($this->AmbilPelaku(), $this->AmbilTemplat($templatKomunikasi), isset($valid['StatusBsp']) ? StatusBspTemplat::from((string) $valid['StatusBsp']) : null, $valid['CatatanBsp'] ?? null);

        return back()->with('Kilat', 'Status BSP dicatat.');
    }

    /**
     * @return array{0: PeristiwaKomunikasi, 1: KanalKomunikasi, 2: BahasaKomunikasi}
     */
    private function UraiKombinasi(string $kodePeristiwa, string $kanal, string $bahasa): array
    {
        $peristiwa = PeristiwaKomunikasi::tryFrom($kodePeristiwa) ?? abort(404);
        $kanalEnum = match ($kanal) {
            'email' => KanalKomunikasi::Email,
            'whatsapp' => KanalKomunikasi::WhatsApp,
            default => abort(404),
        };
        $bahasaEnum = BahasaKomunikasi::tryFrom($bahasa) ?? abort(404);

        if (! in_array($kanalEnum, $peristiwa->AmbilKanal(), true)) {
            abort(404);
        }

        return [$peristiwa, $kanalEnum, $bahasaEnum];
    }

    private function AmbilTemplat(string $uuid): TemplateKomunikasi
    {
        return TemplateKomunikasi::query()->where('Uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array{Kelola: bool}
     */
    private function AmbilIzin(): array
    {
        return ['Kelola' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::LegalKelola)];
    }
}
