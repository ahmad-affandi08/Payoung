<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Aksi\BuatDrafPoOtomatis;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * D-23 D: tiap pagi menyiapkan draf PO untuk stok di bawah minimum di tenant yang mengaktifkan "draf pesanan
 * pembelian otomatis" (bawaan aktif). Atas nama Owner tenant; kegagalan satu tenant tidak menghentikan tenant lain.
 */
final class BuatDrafPoOtomatisPerintah extends Command
{
    protected $signature = 'pembelian:draf-po-otomatis {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Menyiapkan draf pesanan pembelian untuk stok di bawah minimum (D-23 D).';

    public function handle(
        KeanggotaanPengguna $keanggotaan,
        KonteksTenant $konteks,
        TanggalBisnisOutlet $tanggalBisnis,
        PengaturanPembelianTenant $pengaturan,
        PemilikTenant $pemilik,
        BuatDrafPoOtomatis $buat,
    ): int {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $semua = $keanggotaan->AmbilSemuaIdTenant();
        $daftar = $diminta === [] ? $semua : array_values(array_intersect(array_unique($diminta), $semua));
        $sebelumnya = $konteks->Ambil();
        $jumlahPo = 0;
        $gagal = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);
                $idPemilik = $pemilik->AmbilIdPemilik($idTenant);

                $atur = $pengaturan->Ambil();

                if ($idPemilik === null || ! $atur->drafPoOtomatis) {
                    continue;
                }

                try {
                    $jumlahPo += $buat->Jalankan($idPemilik, $tanggalBisnis->Hitung(null), null, $atur->sumberDrafPo, $atur->hariCakupanDrafPo)['JumlahPo'];
                } catch (Throwable $galat) {
                    $gagal++;
                    report($galat);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$jumlahPo} draf PO dibuat, {$gagal} tenant gagal.");

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
