<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Katalog\Harga\Aksi\SimpanHargaOtomatisUmum;
use App\Domain\Katalog\Harga\Aksi\TerapkanHargaDariModalMassal;
use App\Domain\Katalog\Harga\Aksi\TerapkanUsulanHarga;
use App\Domain\Katalog\Harga\Aksi\TolakUsulanHarga;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Kueri\HalamanHargaOtomatis;
use App\Domain\Katalog\Harga\Kueri\PratinjauHargaDariModal;
use App\Domain\Katalog\Harga\Tugas\TerapkanHargaOtomatisSemuaTugas;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Permintaan\Kelola\Katalog\HargaDariModalPermintaan;
use App\Http\Permintaan\Kelola\Katalog\UbahPengaturanHargaOtomatisPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Harga jual otomatis mengikuti harga beli (D-266, F-03 + F-04): satu formulir pengaturan (termasuk untung khusus) dan usulan; serta
 * "Harga dari harga beli" massal dari daftar Produk (pratinjau lalu terapkan). Lihat = produk.lihat, semua perubahan =
 * produk.harga.ubah, dan fitur `harga.otomatis` (paket Pro ke atas) ditegakkan `WajibFiturPaket` lewat `PetaFiturRute`.
 */
final class HargaOtomatisKontroler extends DasarKatalogKontroler
{
    private const ALAMAT = 'kelola.produk.harga-otomatis';

    public function Tampilkan(HalamanHargaOtomatis $halaman): Response
    {
        return Inertia::render('Kelola/Produk/HargaOtomatis', [
            ...$halaman->Ambil(),
            'Izin' => ['Ubah' => $this->CekIzin(IzinTenant::ProdukHargaUbah)],
        ]);
    }

    public function UbahPengaturan(UbahPengaturanHargaOtomatisPermintaan $permintaan, SimpanHargaOtomatisUmum $simpan): RedirectResponse
    {
        $pengaturan = $simpan->Jalankan(
            $permintaan->boolean('Aktif'),
            (string) $permintaan->validated('BatasPerubahanPersen'),
            (string) $permintaan->validated('PersenUntung'),
            (int) $permintaan->validated('Pembulatan'),
            ArahHargaOtomatis::from((string) $permintaan->validated('Arah')),
            $permintaan->AmbilKhusus(),
            $this->Pelaku()->Id,
        );

        return redirect()->route(self::ALAMAT)->with('Kilat', $pengaturan->Aktif
            ? 'Harga otomatis menyala. Harga jual akan mengikuti harga beli setiap ada penerimaan barang.'
            : 'Harga otomatis dimatikan. Harga yang sudah berubah tetap seperti sekarang.');
    }

    public function TerapkanUsulan(string $usulan, TerapkanUsulanHarga $terapkan): RedirectResponse
    {
        $berhasil = $terapkan->Jalankan($usulan, $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT)->with('Kilat', $berhasil
            ? 'Harga baru diterapkan.'
            : 'Harga produk sudah berubah sejak usulan dibuat, jadi usulan ini ditutup. Hitungan berikutnya akan membuat usulan baru bila perlu.');
    }

    public function TolakUsulan(string $usulan, TolakUsulanHarga $tolak): RedirectResponse
    {
        $tolak->Jalankan($usulan, $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT)->with('Kilat', 'Usulan ditolak. Harga produk tetap seperti sekarang.');
    }

    /**
     * "Terapkan sekarang": hitung ulang semua produk berstok dari harga beli terakhirnya. Berjalan di antrean, hasilnya
     * dikabarkan lewat notifikasi.
     */
    public function JalankanSekarang(): RedirectResponse
    {
        TerapkanHargaOtomatisSemuaTugas::dispatch($this->IdTenant(), (string) Str::ulid(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT)->with('Kilat', 'Harga sedang dihitung ulang dari harga beli terakhir. Hasilnya muncul di notifikasi dan di daftar usulan.');
    }

    /** "Harga dari harga beli" langkah 1: tampilkan harga lama, modal, dan harga baru tanpa menyimpan. */
    public function PratinjauDariModal(HargaDariModalPermintaan $permintaan, PratinjauHargaDariModal $pratinjau): JsonResponse
    {
        return response()->json(['Data' => $pratinjau->Ambil($permintaan->AmbilUuid(), (string) $permintaan->validated('PersenUntung'), (int) $permintaan->validated('Pembulatan'))]);
    }

    /** "Harga dari harga beli" langkah 2: simpan harga baru produk yang berubah. */
    public function TerapkanDariModal(HargaDariModalPermintaan $permintaan, TerapkanHargaDariModalMassal $terapkan): RedirectResponse
    {
        $hasil = $terapkan->Jalankan($permintaan->AmbilUuid(), (string) $permintaan->validated('PersenUntung'), (int) $permintaan->validated('Pembulatan'), $this->Pelaku()->Id);
        $dilewati = $hasil['Dilewati'] > 0 ? " {$hasil['Dilewati']} produk dilewati (belum pernah dibeli, belum punya harga, atau harganya sudah pas)." : '';

        return redirect()->route('kelola.produk.daftar')->with('Kilat', "Harga {$hasil['Produk']} produk diperbarui dari harga beli.{$dilewati}");
    }
}
