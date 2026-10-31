<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Harga\Aksi\AturHargaKanal;
use App\Domain\Katalog\Harga\Aksi\SimpanDaftarHarga;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaDaftarHarga;
use App\Domain\Katalog\Harga\Aksi\UbahStatusDaftarHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Kueri\DaftarDaftarHarga;
use App\Domain\Katalog\Harga\Kueri\DetailDaftarHarga;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Pelanggan\Kueri\DaftarTierPelanggan;
use App\Http\Permintaan\Kelola\Katalog\AturHargaKanalPermintaan;
use App\Http\Permintaan\Kelola\Katalog\SimpanDaftarHargaPermintaan;
use App\Http\Permintaan\Kelola\Katalog\SimpanHargaDaftarHargaPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar harga F-03 (E.7): daftar, buat/ubah pengaturan, aktif/nonaktif (tidak pernah dihapus), dan harga produk di
 * dalam daftar. Daftar dicari lewat ULID di tenant aktif (tenant lain = 404). Pelaku yang dibatasi outlet hanya
 * boleh mengubah daftar khusus outletnya (`OutletDiLuarAkses`).
 */
final class DaftarHargaKontroler extends DasarKatalogKontroler
{
    public function Daftar(Request $permintaan, DaftarDaftarHarga $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDaftarHarga::KOLOM_URUT, '-Prioritas', DaftarDaftarHarga::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/DaftarHarga/Daftar', 'DaftarHarga', fn (): array => $daftar->AmbilTabel($tabel), fn (): array => [
            'Outlet' => $daftar->AmbilOpsiOutlet($this->IdOutletBoleh()),
            'Kanal' => DaftarDaftarHarga::AmbilOpsiKanal(),
            // F-16b: opsi tier pelanggan (kode) dari domain Pelanggan.
            'OpsiTier' => array_map(fn (array $t): array => ['Nilai' => $t['Nilai'], 'Label' => $t['Label']], app(DaftarTierPelanggan::class)->AmbilOpsi()),
            'ZonaWaktu' => $daftar->AmbilZonaWaktu(),
            'Izin' => $this->AmbilIzinKatalog(),
        ]);
    }

    /** Halaman penuh "Buat daftar harga" (pola sama dengan Tambah produk). */
    public function Buat(DaftarDaftarHarga $daftar): Response
    {
        return Inertia::render('Kelola/DaftarHarga/Buat', [
            'Outlet' => $daftar->AmbilOpsiOutlet($this->IdOutletBoleh()),
            'Kanal' => DaftarDaftarHarga::AmbilOpsiKanal(),
            // F-16b: opsi tier pelanggan (kode) dari domain Pelanggan.
            'OpsiTier' => array_map(fn (array $t): array => ['Nilai' => $t['Nilai'], 'Label' => $t['Label']], app(DaftarTierPelanggan::class)->AmbilOpsi()),
            'ZonaWaktu' => $daftar->AmbilZonaWaktu(),
        ]);
    }

    public function Simpan(SimpanDaftarHargaPermintaan $permintaan, SimpanDaftarHarga $simpan, DaftarDaftarHarga $daftar): RedirectResponse
    {
        $baris = $simpan->Jalankan(null, $permintaan->AmbilData($daftar->AmbilZonaWaktu()), $this->IdOutletBoleh());

        return redirect()->route('kelola.daftar-harga.detail', ['daftarHarga' => $baris->Uuid])->with('Kilat', "Daftar harga {$baris->Nama} dibuat.");
    }

    public function Detail(string $daftarHarga, Request $permintaan, DetailDaftarHarga $detail, DaftarDaftarHarga $daftar): Response|JsonResponse
    {
        $baris = $this->CariDaftarHarga($daftarHarga);
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), [], '');

        return ResponsTabel::Kirim($permintaan, 'Kelola/DaftarHarga/Detail', 'Baris', fn (): array => $detail->AmbilBaris($baris, $tabel), fn (): array => [
            'DaftarHarga' => $detail->AmbilForm($baris),
            'Outlet' => $daftar->AmbilOpsiOutlet($this->IdOutletBoleh()),
            'Kanal' => DaftarDaftarHarga::AmbilOpsiKanal(),
            // F-16b: opsi tier pelanggan (kode) dari domain Pelanggan.
            'OpsiTier' => array_map(fn (array $t): array => ['Nilai' => $t['Nilai'], 'Label' => $t['Label']], app(DaftarTierPelanggan::class)->AmbilOpsi()),
            'ZonaWaktu' => $daftar->AmbilZonaWaktu(),
            'Izin' => $this->AmbilIzinKatalog(),
        ]);
    }

    public function Ubah(string $daftarHarga, SimpanDaftarHargaPermintaan $permintaan, SimpanDaftarHarga $simpan, DaftarDaftarHarga $daftar): RedirectResponse
    {
        $baris = $simpan->Jalankan($this->CariDaftarHarga($daftarHarga), $permintaan->AmbilData($daftar->AmbilZonaWaktu()), $this->IdOutletBoleh());

        return back()->with('Kilat', "Daftar harga {$baris->Nama} disimpan.");
    }

    public function Nonaktifkan(string $daftarHarga, UbahStatusDaftarHarga $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->CariDaftarHargaUntukDiubah($daftarHarga), false);

        return back()->with('Kilat', "Daftar harga {$baris->Nama} dinonaktifkan. Kasir tidak memakainya lagi.");
    }

    public function Aktifkan(string $daftarHarga, UbahStatusDaftarHarga $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->CariDaftarHargaUntukDiubah($daftarHarga), true);

        return back()->with('Kilat', "Daftar harga {$baris->Nama} aktif kembali.");
    }

    public function SimpanHarga(string $daftarHarga, SimpanHargaDaftarHargaPermintaan $permintaan, SimpanHargaDaftarHarga $simpan): RedirectResponse
    {
        $baris = $this->CariDaftarHargaUntukDiubah($daftarHarga);
        $perSatuan = $permintaan->AmbilPerSatuanDariBaris();

        try {
            $hasil = $simpan->Jalankan($baris, $perSatuan, SumberPerubahanHarga::Manual);
        } catch (PelanggaranAturanBisnis $galat) {
            throw $permintaan->PetakanGalat($galat);
        }

        return back()->with('Kilat', $hasil->CekAdaPerubahan() ? "Harga di {$baris->Nama} disimpan." : 'Tidak ada harga yang berubah.');
    }

    /**
     * "Atur harga ojol" langkah 1: contoh harga baru dan jumlah produk yang akan diisi, tanpa menyimpan apa pun.
     */
    public function PratinjauHargaKanal(AturHargaKanalPermintaan $permintaan, AturHargaKanal $atur): JsonResponse
    {
        $outlet = $permintaan->AmbilOutlet();

        return response()->json(['Data' => $atur->Pratinjau(
            $permintaan->AmbilKanal(),
            (string) $permintaan->validated('Mode'),
            (string) $permintaan->validated('Nilai'),
            (int) $permintaan->validated('Pembulatan'),
            $permintaan->boolean('Timpa'),
            $outlet['Id'],
        )]);
    }

    /**
     * "Atur harga ojol" langkah 2: membuat/mengisi daftar harga tiap platform dalam satu transaksi.
     */
    public function TerapkanHargaKanal(AturHargaKanalPermintaan $permintaan, AturHargaKanal $atur): RedirectResponse
    {
        $outlet = $permintaan->AmbilOutlet();
        $hasil = $atur->Terapkan(
            $permintaan->AmbilKanal(),
            (string) $permintaan->validated('Mode'),
            (string) $permintaan->validated('Nilai'),
            (int) $permintaan->validated('Pembulatan'),
            $permintaan->boolean('Timpa'),
            $outlet['Id'],
            $outlet['Nama'],
            $this->IdOutletBoleh(),
        );
        $ringkas = implode(', ', array_map(fn (array $k): string => "{$k['Label']} {$k['Diisi']} produk", $hasil['Kanal']));

        return redirect()->route('kelola.daftar-harga.daftar')->with('Kilat', "Harga ojol diatur: {$ringkas}.");
    }

    private function CariDaftarHarga(string $uuid): DaftarHarga
    {
        return DaftarHarga::query()->where('Uuid', $uuid)->firstOrFail();
    }

    private function CariDaftarHargaUntukDiubah(string $uuid): DaftarHarga
    {
        $daftar = $this->CariDaftarHarga($uuid);
        SimpanDaftarHarga::PastikanAksesOutlet($daftar->IdOutlet, $this->IdOutletBoleh());

        return $daftar;
    }
}
