<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Aksi\BalasTiketDukungan;
use App\Domain\Dukungan\Aksi\BuatTiketDukungan;
use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Kueri\TiketDukunganAplikasi;
use App\Domain\Dukungan\Layanan\PenyusunKonteksTiketAplikasi;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Aplikasi\BalasTiketKasirPermintaan;
use App\Http\Permintaan\Aplikasi\BuatTiketKasirPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tombol Bantuan di Aplikasi Kasir (P-09): tiket dengan konteks otomatis (outlet, perangkat, versi aplikasi, outbox
 * tertunda, layar asal, log singkat). Pelapor = staf yang masuk di perangkat (`UuidPengguna`, anggota outlet perangkat);
 * staf hanya melihat dan membalas tiketnya sendiri. Tetap terbuka saat langganan ditangguhkan: tenant yang tertahan
 * justru paling perlu menghubungi dukungan.
 */
final class BantuanKontroler extends Kontroler
{
    public function __construct(private readonly AnggotaOutlet $anggota) {}

    public function Daftar(Request $permintaan, TiketDukunganAplikasi $kueri): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $pelapor = $this->AmbilPelapor($perangkat, $permintaan->query('UuidPengguna'));

        return response()->json(['Tiket' => $kueri->Daftar($pelapor->id)]);
    }

    public function Buat(BuatTiketKasirPermintaan $permintaan, BuatTiketDukungan $buat, PenyusunKonteksTiketAplikasi $penyusun, TiketDukunganAplikasi $kueri): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $pelapor = $this->AmbilPelapor($perangkat, $permintaan->input('UuidPengguna'));
        $outlet = Outlet::query()->find($perangkat->IdOutlet);
        $versi = $permintaan->header('X-Versi-Aplikasi');
        $versi = is_string($versi) && $versi !== '' ? $versi : $perangkat->VersiAplikasi;
        $outbox = $perangkat->JumlahOutboxTertunda;
        $konteks = $penyusun->Susun([
            'Aplikasi' => 'Aplikasi Kasir',
            'Outlet' => $outlet === null ? null : "{$outlet->Kode} | {$outlet->Nama}",
            'Perangkat' => "{$perangkat->Nama} | {$perangkat->Kode}",
            'Platform' => trim(($perangkat->Platform === null ? '' : $perangkat->Platform->value).' '.($perangkat->VersiOs ?? '')),
            'Versi aplikasi' => $versi,
            'Outbox tertunda' => $outbox > 0
                ? "{$outbox} transaksi"
                    .($perangkat->OutboxTertundaSejak === null ? '' : ' sejak '.$perangkat->OutboxTertundaSejak->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB')
                : 'Kosong',
            'Terakhir aktif' => $perangkat->TerakhirAktifPada?->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB',
            'Staf pelapor' => $pelapor->nama,
        ], $permintaan->AmbilLayarAsal(), $permintaan->AmbilLog());
        $tiket = $buat->Jalankan($pelapor->id, $pelapor->nama, new DataTiketBaru(
            kategori: $permintaan->AmbilKategori(),
            prioritas: $permintaan->AmbilPrioritas(),
            judul: $permintaan->AmbilJudul(),
            isi: $permintaan->AmbilIsi(),
            konteks: $konteks,
            kanal: KanalTiketDukungan::AplikasiKasir,
        ));

        return response()->json(['Tiket' => $kueri->Ringkas($tiket)], 201);
    }

    public function Tampilkan(string $tiket, Request $permintaan, TiketDukunganAplikasi $kueri): JsonResponse
    {
        $pelapor = $this->AmbilPelapor(AutentikasiPerangkat::AmbilPerangkat($permintaan), $permintaan->query('UuidPengguna'));

        return response()->json(['Tiket' => $kueri->Detail($kueri->Cari($tiket, $pelapor->id))]);
    }

    public function Balas(string $tiket, BalasTiketKasirPermintaan $permintaan, TiketDukunganAplikasi $kueri, BalasTiketDukungan $balas): JsonResponse
    {
        $pelapor = $this->AmbilPelapor(AutentikasiPerangkat::AmbilPerangkat($permintaan), $permintaan->input('UuidPengguna'));
        $tiketBaru = $balas->Jalankan($pelapor->id, $pelapor->nama, $kueri->Cari($tiket, $pelapor->id), $permintaan->AmbilIsi());

        return response()->json(['Tiket' => $kueri->Detail($tiketBaru)]);
    }

    /** Staf anggota outlet perangkat; tanpa `UuidPengguna` yang sah ditolak 422/403. */
    private function AmbilPelapor(Perangkat $perangkat, mixed $uuidPengguna): DataAnggotaOutlet
    {
        if (! is_string($uuidPengguna) || preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $uuidPengguna) !== 1) {
            throw new PelanggaranAturanBisnis('UuidPenggunaWajib', 'Pilih staf yang meminta bantuan.', 'UuidPengguna', 422);
        }

        $pelapor = $this->anggota->Cari($perangkat->IdTenant, strtoupper($uuidPengguna), $perangkat->IdOutlet);

        if ($pelapor === null) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Staf ini bukan anggota outlet perangkat.', 'UuidPengguna', 403);
        }

        return $pelapor;
    }
}
