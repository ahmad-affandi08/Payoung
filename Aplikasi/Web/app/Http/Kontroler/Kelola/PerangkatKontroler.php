<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Aksi\BuatKodeAktivasi;
use App\Domain\Organisasi\Aksi\BuatPerangkat;
use App\Domain\Organisasi\Aksi\CabutPerangkat;
use App\Domain\Organisasi\Aksi\UbahNamaPerangkat;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\DaftarPerangkat;
use App\Domain\Organisasi\Kueri\PemakaianPerangkat;
use App\Domain\Organisasi\Kueri\PenyaringSektor;
use App\Domain\Organisasi\Layanan\PembuatQrKodeAktivasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\SimpanPerangkatPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perangkat POS di back-office (F-02 langkah 5, BR-02.1, BR-02.2, BR-02.3): daftar, tambah (langsung dapat kode
 * aktivasi + QR), ubah nama, buat ulang kode, cabut. Kode aktivasi asli hanya ada di flash sesi sekali tampil.
 * Perangkat di outlet di luar akses pelaku atau milik tenant lain diperlakukan sebagai tidak ada (404).
 */
final class PerangkatKontroler extends DasarKelolaKontroler
{
    private const KUNCI_KODE_BARU = 'KodeAktivasiBaru';

    public function Daftar(Request $permintaan, DaftarPerangkat $daftar, PemakaianPerangkat $pemakaian, PastikanBatasPaket $batasPaket, PembuatQrKodeAktivasi $qr, PenyaringSektor $sektor): Response
    {
        $boleh = $this->IdOutletBoleh();
        $idTenant = $this->IdTenant();
        $kodeBaru = $permintaan->session()->get(self::KUNCI_KODE_BARU);

        return Inertia::render('Kelola/Perangkat/Daftar', [
            'Perangkat' => $daftar->Ambil($boleh),
            'Outlet' => Outlet::query()
                ->where('Status', StatusOrganisasi::Aktif->value)
                ->when($boleh !== null, fn ($kueri) => $kueri->whereKey($boleh ?? []))
                ->orderBy('Nama')
                ->get()
                ->map(fn (Outlet $outlet): array => [
                    'Uuid' => $outlet->Uuid,
                    'Kode' => $outlet->Kode,
                    'Nama' => $outlet->Nama,
                    'BatasPerangkat' => $batasPaket->AmbilRingkasan($idTenant, 'BatasPerangkatPerOutlet', $pemakaian->HitungAktifDiOutlet($outlet->Id)),
                ])
                ->values(),
            // D-183: layar dapur & pelayan hanya untuk F&B, salesman hanya untuk grosir/distribusi.
            'JenisPerangkat' => array_values(array_map(fn (JenisPerangkat $jenis): array => ['Nilai' => $jenis->value, 'Label' => $jenis->AmbilLabel()], array_filter(JenisPerangkat::cases(), fn (JenisPerangkat $jenis): bool => $sektor->CekJenisPerangkatTampil($jenis)))),
            'KodeAktivasiBaru' => is_array($kodeBaru) && is_string($kodeBaru['Kode'] ?? null) ? [
                ...$kodeBaru,
                'QrSvg' => $qr->BuatSvg($kodeBaru['Kode']),
            ] : null,
        ]);
    }

    public function Simpan(SimpanPerangkatPermintaan $permintaan, BuatPerangkat $buat): RedirectResponse
    {
        $outlet = $this->CariOutletAktif($permintaan->string('Outlet')->toString());
        $hasil = $buat->Jalankan($outlet, $permintaan->string('Nama')->toString(), JenisPerangkat::from($permintaan->string('Jenis')->toString()), $this->Pelaku()->Id);

        return $this->KembaliDenganKode($hasil['Perangkat'], $hasil['Kode'], $hasil['KedaluwarsaPada']);
    }

    public function Ubah(string $perangkat, SimpanPerangkatPermintaan $permintaan, UbahNamaPerangkat $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->CariPerangkat($perangkat), $permintaan->string('Nama')->toString());

        return back()->with('Kilat', "Nama perangkat {$baris->Kode} disimpan.");
    }

    public function BuatKodeAktivasi(string $perangkat, BuatKodeAktivasi $buatKode): RedirectResponse
    {
        $baris = $this->CariPerangkat($perangkat);
        $kode = $buatKode->Jalankan($baris, $this->Pelaku()->Id);

        return $this->KembaliDenganKode($baris, $kode['Kode'], $kode['KedaluwarsaPada']);
    }

    public function Cabut(string $perangkat, CabutPerangkat $cabut): RedirectResponse
    {
        $baris = $cabut->Jalankan($this->CariPerangkat($perangkat));

        return back()->with('Kilat', "Perangkat {$baris->Nama} ({$baris->Kode}) dicabut dan tidak bisa dipakai lagi.");
    }

    private function KembaliDenganKode(Perangkat $perangkat, string $kode, Carbon $kedaluwarsa): RedirectResponse
    {
        return redirect()->route('kelola.perangkat.daftar')
            ->with(self::KUNCI_KODE_BARU, [
                'UuidPerangkat' => $perangkat->Uuid,
                'NamaPerangkat' => $perangkat->Nama,
                'KodePerangkat' => $perangkat->Kode,
                'Kode' => $kode,
                'KedaluwarsaPada' => $kedaluwarsa->toIso8601String(),
            ]);
    }

    private function CariOutletAktif(string $uuid): Outlet
    {
        $outlet = Outlet::query()->where('Uuid', $uuid)->first();
        $boleh = $this->IdOutletBoleh();

        if ($outlet === null || ($boleh !== null && ! in_array($outlet->Id, $boleh, true))) {
            throw new PelanggaranAturanBisnis('OutletTidakDitemukan', 'Pilih outlet dari daftar.', 'Outlet');
        }

        return $outlet;
    }

    private function CariPerangkat(string $uuid): Perangkat
    {
        $perangkat = Perangkat::query()->where('Uuid', $uuid)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($perangkat->IdOutlet, $boleh, true), 404);

        return $perangkat;
    }
}
