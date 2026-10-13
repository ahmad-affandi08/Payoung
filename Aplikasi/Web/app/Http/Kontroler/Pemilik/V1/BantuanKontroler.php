<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Dukungan\Aksi\BalasTiketDukungan;
use App\Domain\Dukungan\Aksi\BuatTiketDukungan;
use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Kueri\TiketDukunganAplikasi;
use App\Domain\Dukungan\Layanan\PenyusunKonteksTiketAplikasi;
use App\Http\Permintaan\Aplikasi\BalasTiketAplikasiPermintaan;
use App\Http\Permintaan\Aplikasi\BuatTiketAplikasiPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tombol Bantuan di Aplikasi Owner (P-09): buat tiket dengan konteks otomatis (aplikasi, platform, versi, layar asal,
 * log singkat), lihat tiket tenant, dan balas. Tiket tenant yang sama dengan back-office, jadi Owner melihat semua
 * tiket tenantnya. Tanpa izin khusus: setiap pengguna Owner boleh meminta bantuan.
 */
final class BantuanKontroler extends DasarPemilikKontroler
{
    public function Daftar(TiketDukunganAplikasi $kueri): JsonResponse
    {
        return response()->json(['Tiket' => $kueri->Daftar(null)]);
    }

    public function Buat(BuatTiketAplikasiPermintaan $permintaan, BuatTiketDukungan $buat, PenyusunKonteksTiketAplikasi $penyusun, TiketDukunganAplikasi $kueri): JsonResponse
    {
        $pengguna = $this->Pengguna($permintaan);
        $konteks = $penyusun->Susun([
            'Aplikasi' => 'Aplikasi Owner',
            'Platform' => $this->Header($permintaan, 'X-Platform-Aplikasi'),
            'Versi aplikasi' => $this->Header($permintaan, 'X-Versi-Aplikasi'),
        ], $permintaan->AmbilLayarAsal(), $permintaan->AmbilLog());
        $tiket = $buat->Jalankan($pengguna->Id, $pengguna->Nama, new DataTiketBaru(
            kategori: $permintaan->AmbilKategori(),
            prioritas: $permintaan->AmbilPrioritas(),
            judul: $permintaan->AmbilJudul(),
            isi: $permintaan->AmbilIsi(),
            konteks: $konteks,
            kanal: KanalTiketDukungan::AplikasiPemilik,
        ));

        return response()->json(['Tiket' => $kueri->Ringkas($tiket)], 201);
    }

    public function Tampilkan(string $tiket, TiketDukunganAplikasi $kueri): JsonResponse
    {
        return response()->json(['Tiket' => $kueri->Detail($kueri->Cari($tiket, null))]);
    }

    public function Balas(string $tiket, BalasTiketAplikasiPermintaan $permintaan, TiketDukunganAplikasi $kueri, BalasTiketDukungan $balas): JsonResponse
    {
        $pengguna = $this->Pengguna($permintaan);
        $tiketBaru = $balas->Jalankan($pengguna->Id, $pengguna->Nama, $kueri->Cari($tiket, null), $permintaan->AmbilIsi());

        return response()->json(['Tiket' => $kueri->Detail($tiketBaru)]);
    }

    private function Header(Request $permintaan, string $nama): ?string
    {
        $nilai = $permintaan->header($nama);

        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }
}
