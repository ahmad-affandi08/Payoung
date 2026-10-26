<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Komunikasi;

use App\Domain\Pengelola\Komunikasi\Aksi\BuatKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\KirimUjiKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\UbahStatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PengirimKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PenyusunIsiKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaKampanyeProspek;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kampanye email ke calon pelanggan (D-216, `/kampanye-prospek`), semuanya dengan izin `komunikasi.kelola`: daftar, buat
 * dengan segmen sektor dan hitungan penerima, rincian per penerima, email uji ke diri sendiri, jalankan, jeda, dan
 * batalkan. Pengiriman sendiri berjalan di `prospek:kirim-kampanye`.
 */
final class KampanyeProspekKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(): Response
    {
        return Inertia::render('Pengelola/KampanyeProspek/Daftar', [
            'Kampanye' => array_values(KampanyeProspek::query()->orderByDesc('Id')->limit(100)->get()->map(fn (KampanyeProspek $k): array => self::Petakan($k))->all()),
            'EmailKampanyeAktif' => PengirimKampanyeProspek::Tersedia(),
            'Kontak' => self::RingkasKontak(),
        ]);
    }

    public function Buat(SegmenKampanyeProspek $segmen): Response
    {
        return Inertia::render('Pengelola/KampanyeProspek/Buat', [
            'PilihanSektor' => $segmen->HitungPerSektor(),
            'EmailKampanyeAktif' => PengirimKampanyeProspek::Tersedia(),
            'BatasBawaan' => BuatKampanyeProspek::BATAS_BAWAAN_PER_HARI,
            'MaksBatas' => BuatKampanyeProspek::MAKS_BATAS_PER_HARI,
            'PenandaSektor' => PenyusunIsiKampanyeProspek::PENANDA_SEKTOR,
        ]);
    }

    public function Simpan(Request $permintaan, BuatKampanyeProspek $buat): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Nama' => ['required', 'string', 'min:3', 'max:120'],
            'Subjek' => ['required', 'string', 'min:3', 'max:150'],
            'Isi' => ['required', 'string', 'min:20', 'max:3000'],
            'Sektor' => ['required', 'array', 'min:1', 'max:'.count(SektorProspek::cases())],
            'Sektor.*' => ['string', Rule::enum(SektorProspek::class)],
            'BatasPerHari' => ['required', 'integer', 'min:1', 'max:'.BuatKampanyeProspek::MAKS_BATAS_PER_HARI],
        ]);

        $kampanye = $buat->Jalankan(
            $this->AmbilPelaku(),
            (string) $valid['Nama'],
            (string) $valid['Subjek'],
            (string) $valid['Isi'],
            SegmenKampanyeProspek::BersihkanSektor($valid['Sektor']),
            (int) $valid['BatasPerHari'],
        );

        return redirect()->route('pengelola.kampanye-prospek.tampil', $kampanye->Uuid)->with('Kilat', 'Kampanye disimpan sebagai draf. Kirim email uji ke diri sendiri dulu, lalu jalankan.');
    }

    public function Tampil(KampanyeProspek $kampanyeProspek): Response
    {
        $penerima = PenerimaKampanyeProspek::query()
            ->with('Kontak')
            ->where('IdKampanyeProspek', $kampanyeProspek->Id)
            ->orderByRaw("FIELD(Status, 'Gagal', 'Dilewati', 'Diproses', 'Menunggu', 'Terkirim')")
            ->orderBy('Id')
            ->limit(200)
            ->get();

        return Inertia::render('Pengelola/KampanyeProspek/Tampil', [
            'Kampanye' => [...self::Petakan($kampanyeProspek), 'Isi' => $kampanyeProspek->Isi],
            'EmailKampanyeAktif' => PengirimKampanyeProspek::Tersedia(),
            'Penerima' => array_values($penerima->map(fn (PenerimaKampanyeProspek $p): array => [
                'Id' => $p->Id,
                'Email' => $p->Kontak?->Email ?? '',
                'Sektor' => $p->Kontak?->Sektor->AmbilLabel() ?? '',
                'Status' => $p->Status->value,
                'Alasan' => $p->Alasan,
                'DikirimPada' => $p->DikirimPada?->toIso8601String(),
            ])->all()),
        ]);
    }

    public function UbahStatus(Request $permintaan, KampanyeProspek $kampanyeProspek, UbahStatusKampanyeProspek $ubah): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Status' => ['required', Rule::in([StatusKampanyeProspek::Berjalan->value, StatusKampanyeProspek::Dijeda->value, StatusKampanyeProspek::Dibatalkan->value])],
        ]);
        $hasil = $ubah->Jalankan($this->AmbilPelaku(), $kampanyeProspek, StatusKampanyeProspek::from((string) $valid['Status']));

        return back()->with('Kilat', match ($hasil->Status) {
            StatusKampanyeProspek::Berjalan => 'Kampanye berjalan. Email dikirim sedikit demi sedikit pada jam kerja (08.00–17.00 WIB).',
            StatusKampanyeProspek::Dijeda => 'Kampanye dijeda.',
            default => 'Kampanye dibatalkan.',
        });
    }

    public function Uji(KampanyeProspek $kampanyeProspek, KirimUjiKampanyeProspek $uji): RedirectResponse
    {
        $alamat = $uji->Jalankan($this->AmbilPelaku(), $kampanyeProspek);

        return back()->with('Kilat', "Email uji dikirim ke {$alamat}. Periksa kotak masuk dan folder spam.");
    }

    /**
     * @return array{Aktif: int, Berhenti: int, Total: int}
     */
    public static function RingkasKontak(): array
    {
        return [
            'Aktif' => KontakProspek::query()->where('Status', StatusKontakProspek::Aktif->value)->count(),
            'Berhenti' => KontakProspek::query()->where('Status', '<>', StatusKontakProspek::Aktif->value)->count(),
            'Total' => KontakProspek::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function Petakan(KampanyeProspek $k): array
    {
        return [
            'Uuid' => $k->Uuid,
            'Nama' => $k->Nama,
            'Subjek' => $k->Subjek,
            'Status' => $k->Status->value,
            'LabelStatus' => $k->Status->AmbilLabel(),
            'AlasanJeda' => $k->AlasanJeda,
            'BatasPerHari' => $k->BatasPerHari,
            'Sektor' => array_values(array_map(fn (string $kode): string => SektorProspek::tryFrom($kode)?->AmbilLabel() ?? $kode, array_values(array_filter((array) ($k->Segmen['Sektor'] ?? []), 'is_string')))),
            'MulaiKirimPada' => $k->MulaiKirimPada?->toIso8601String(),
            'SelesaiPada' => $k->SelesaiPada?->toIso8601String(),
            'JumlahPenerima' => $k->JumlahPenerima,
            'JumlahTerkirim' => $k->JumlahTerkirim,
            'JumlahGagal' => $k->JumlahGagal,
            'JumlahDilewati' => $k->JumlahDilewati,
            'DapatDijalankan' => in_array(StatusKampanyeProspek::Berjalan, $k->Status->AmbilTujuan(), true),
            'DapatDijeda' => in_array(StatusKampanyeProspek::Dijeda, $k->Status->AmbilTujuan(), true),
            'DapatDibatalkan' => in_array(StatusKampanyeProspek::Dibatalkan, $k->Status->AmbilTujuan(), true),
        ];
    }
}
