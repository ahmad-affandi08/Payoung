<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Grosir;

use App\Domain\Penjualan\Aksi\SimpanArmada;
use App\Domain\Penjualan\Aksi\UbahStatusArmada;
use App\Domain\Penjualan\Model\Armada;
use App\Http\Permintaan\Kelola\Grosir\SimpanArmadaPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Armada pengiriman grosir (F-12 §9.7, `/kelola/grosir/armada`, izin `grosir.kelola`): daftar, tambah, ubah, nonaktifkan.
 * Halaman ini diatur sekali lalu jarang disentuh, jadi rumahnya di Pengaturan, bukan menu samping. Armada tidak dihapus
 * karena surat jalan lama menunjuknya.
 */
final class ArmadaKontroler extends DasarGrosirKontroler
{
    public function Daftar(): Response
    {
        return Inertia::render('Kelola/Grosir/Armada/Daftar', [
            'Armada' => array_values(Armada::query()->orderByDesc('Aktif')->orderBy('Nama')->limit(200)->get()->map(fn (Armada $a): array => self::Petakan($a))->all()),
            'Izin' => $this->AmbilIzinGrosir(),
        ]);
    }

    public function Simpan(SimpanArmadaPermintaan $permintaan, SimpanArmada $simpan): RedirectResponse
    {
        $armada = $simpan->Jalankan($permintaan->AmbilData(), null, $this->Pelaku()->Id);

        return back()->with('Kilat', "Armada {$armada->Nama} ({$armada->NomorKendaraan}) ditambahkan.");
    }

    public function Ubah(SimpanArmadaPermintaan $permintaan, string $armada, SimpanArmada $simpan): RedirectResponse
    {
        $baris = $simpan->Jalankan($permintaan->AmbilData(), $this->Cari($armada), $this->Pelaku()->Id);

        return back()->with('Kilat', "Armada {$baris->Nama} disimpan.");
    }

    public function Nonaktifkan(string $armada, UbahStatusArmada $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->Cari($armada), false, $this->Pelaku()->Id);

        return back()->with('Kilat', "Armada {$baris->Nama} dinonaktifkan.");
    }

    public function Aktifkan(string $armada, UbahStatusArmada $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->Cari($armada), true, $this->Pelaku()->Id);

        return back()->with('Kilat', "Armada {$baris->Nama} aktif kembali.");
    }

    private function Cari(string $uuid): Armada
    {
        return Armada::query()->where('Uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public static function Petakan(Armada $a): array
    {
        return ['Uuid' => $a->Uuid, 'Nama' => $a->Nama, 'NomorKendaraan' => $a->NomorKendaraan, 'NamaPengemudi' => $a->NamaPengemudi, 'Keterangan' => $a->Keterangan, 'Aktif' => $a->Aktif];
    }
}
