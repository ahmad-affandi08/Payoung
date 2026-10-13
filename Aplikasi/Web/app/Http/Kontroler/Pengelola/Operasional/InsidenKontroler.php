<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Operasional;

use App\Domain\Pengelola\Operasional\Aksi\KelolaInsiden;
use App\Domain\Pengelola\Operasional\Data\DataInsiden;
use App\Domain\Pengelola\Operasional\Kueri\DaftarInsiden;
use App\Domain\Tenant\Enum\KomponenLayanan;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Enum\TingkatInsiden;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manajemen insiden layanan (P-11 PGL-21, `/operasional/insiden`): lihat `operasional.lihat`; buka, perbarui, umumkan,
 * dan tulis post-mortem `operasional.kelola`. Waktu mulai dikirim peramban sebagai ISO UTC.
 */
final class InsidenKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(DaftarInsiden $kueri): Response
    {
        return Inertia::render('Pengelola/Operasional/Insiden', $kueri->Ambil());
    }

    public function Buka(Request $permintaan, KelolaInsiden $kelola): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Judul' => ['required', 'string', 'max:120'],
            'Tingkat' => ['required', Rule::enum(TingkatInsiden::class)],
            'Dampak' => ['required', 'string', 'max:1000'],
            'Komponen' => ['required', 'array', 'min:1', 'max:'.count(KomponenLayanan::cases())],
            'Komponen.*' => ['string', Rule::enum(KomponenLayanan::class)],
            'PersenTenantTerdampak' => ['nullable', 'integer', 'between:0,100'],
            'Diumumkan' => ['required', 'boolean'],
            'KronologiAwal' => ['required', 'string', 'max:1000'],
            'MulaiPada' => ['nullable', 'date'],
        ], [
            'Judul.*' => 'Isi judul insiden, paling panjang 120 huruf.',
            'Tingkat.*' => 'Pilih tingkat insiden.',
            'Dampak.*' => 'Jelaskan dampaknya bagi pengguna.',
            'Komponen.*' => 'Pilih sedikitnya satu komponen yang terdampak.',
            'PersenTenantTerdampak.*' => 'Persentase tenant terdampak antara 0 dan 100.',
            'KronologiAwal.*' => 'Tulis kronologi awal: apa yang diketahui sekarang.',
        ]);
        $insiden = $kelola->Buka($this->AmbilPelaku(), new DataInsiden(
            (string) $valid['Judul'],
            TingkatInsiden::from((string) $valid['Tingkat']),
            (string) $valid['Dampak'],
            array_values(array_map('strval', $valid['Komponen'])),
            isset($valid['PersenTenantTerdampak']) && $valid['PersenTenantTerdampak'] !== '' ? (int) $valid['PersenTenantTerdampak'] : null,
            (bool) $valid['Diumumkan'],
            (string) $valid['KronologiAwal'],
            isset($valid['MulaiPada']) && $valid['MulaiPada'] !== '' ? CarbonImmutable::parse((string) $valid['MulaiPada'])->utc() : null,
        ));

        return back()->with('Kilat', $insiden->DiumumkanPada === null
            ? "Insiden \"{$insiden->Judul}\" dicatat (internal)."
            : "Insiden \"{$insiden->Judul}\" dicatat dan tampil di halaman status.");
    }

    public function Perbarui(InsidenPlatform $insiden, Request $permintaan, KelolaInsiden $kelola): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Status' => ['required', Rule::enum(StatusInsiden::class)],
            'Isi' => ['required', 'string', 'max:1000'],
        ], ['Status.*' => 'Pilih status terbaru.', 'Isi.*' => 'Tulis isi pembaruan.']);
        $kelola->Perbarui($this->AmbilPelaku(), $insiden, StatusInsiden::from((string) $valid['Status']), (string) $valid['Isi']);

        return back()->with('Kilat', 'Pembaruan insiden dicatat.');
    }

    public function Umumkan(InsidenPlatform $insiden, KelolaInsiden $kelola): RedirectResponse
    {
        $kelola->Umumkan($this->AmbilPelaku(), $insiden);

        return back()->with('Kilat', 'Insiden tampil di halaman status.');
    }

    public function PostMortem(InsidenPlatform $insiden, Request $permintaan, KelolaInsiden $kelola): RedirectResponse
    {
        $valid = $permintaan->validate([
            'PostMortem' => ['required', 'string', 'max:5000'],
            'Terbitkan' => ['required', 'boolean'],
        ], ['PostMortem.*' => 'Tulis post-mortem, paling panjang 5.000 huruf.']);
        $kelola->TulisPostMortem($this->AmbilPelaku(), $insiden, (string) $valid['PostMortem'], (bool) $valid['Terbitkan']);

        return back()->with('Kilat', $valid['Terbitkan'] ? 'Post-mortem diterbitkan.' : 'Post-mortem disimpan sebagai draf.');
    }
}
