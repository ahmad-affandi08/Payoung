<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Komunikasi;

use App\Domain\Pengelola\Komunikasi\Aksi\HapusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\HentikanKontakProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\ImporKontakProspek;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontak prospek (D-216, `/kampanye-prospek/kontak`): daftar, impor CSV (hanya kolom Email dan Sektor), tandai berhenti
 * atas permintaan, dan hapus. Izin `komunikasi.kelola`.
 */
final class KontakProspekKontroler extends Kontroler
{
    use PelakuPengelola;

    private const MAKS_BARIS_DAFTAR = 2000;

    public function Daftar(): Response
    {
        $kontak = KontakProspek::query()->orderByDesc('Id')->limit(self::MAKS_BARIS_DAFTAR)->get();

        return Inertia::render('Pengelola/KampanyeProspek/Kontak', [
            'Kontak' => array_values($kontak->map(fn (KontakProspek $k): array => [
                'Uuid' => $k->Uuid,
                'Email' => $k->Email,
                'Sektor' => $k->Sektor->value,
                'LabelSektor' => $k->Sektor->AmbilLabel(),
                'Sumber' => $k->Sumber,
                'Status' => $k->Status->value,
                'LabelStatus' => $k->Status->AmbilLabel(),
                'BerhentiPada' => $k->BerhentiPada?->toIso8601String(),
                'DibuatPada' => $k->DibuatPada->toIso8601String(),
            ])->all()),
            'Ringkasan' => KampanyeProspekKontroler::RingkasKontak(),
            'MaksBarisDaftar' => self::MAKS_BARIS_DAFTAR,
            'MaksBarisImpor' => ImporKontakProspek::MAKS_BARIS,
            'PilihanSektor' => array_map(fn (SektorProspek $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], SektorProspek::cases()),
            'PilihanStatus' => array_map(fn (StatusKontakProspek $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusKontakProspek::cases()),
        ]);
    }

    public function Impor(Request $permintaan, ImporKontakProspek $impor): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Berkas' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'Sumber' => ['required', 'string', 'min:5', 'max:160'],
        ]);
        $berkas = $permintaan->file('Berkas');
        $isi = $berkas === null ? '' : (string) file_get_contents($berkas->getRealPath());

        $hasil = $impor->Jalankan($this->AmbilPelaku(), $isi, (string) $valid['Sumber']);

        return back()->with('Kilat', "Impor selesai: {$hasil['Ditambah']} kontak ditambahkan, {$hasil['SudahAda']} sudah ada, {$hasil['TidakValid']} email tidak valid, {$hasil['SektorTidakDikenal']} sektor tidak dikenal.");
    }

    public function Henti(KontakProspek $kontakProspek, HentikanKontakProspek $hentikan): RedirectResponse
    {
        $hentikan->Jalankan($kontakProspek, StatusKontakProspek::Berhenti, $this->AmbilPelaku());

        return back()->with('Kilat', 'Kontak ditandai berhenti. Alamat ini tidak akan dikirimi lagi.');
    }

    public function Hapus(KontakProspek $kontakProspek, HapusKontakProspek $hapus): RedirectResponse
    {
        $hapus->Jalankan($this->AmbilPelaku(), $kontakProspek);

        return back()->with('Kilat', 'Kontak dihapus beserta riwayat kirimnya.');
    }
}
