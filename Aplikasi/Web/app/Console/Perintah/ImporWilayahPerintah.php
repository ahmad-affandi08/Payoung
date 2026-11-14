<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Referensi\Aksi\ImporWilayah;
use App\Domain\Pengelola\Referensi\Data\DataWilayah;
use App\Domain\Referensi\Enum\TingkatWilayah;
use App\Domain\Referensi\Enum\ZonaWaktu;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Memuat kode wilayah resmi dari CSV (P-02). Kolom: Kode,Nama,Tingkat,KodeInduk,ZonaWaktu
 * (Tingkat = Provinsi|KabupatenKota, ZonaWaktu = WIB|WITA|WIT). Semua baris valid atau tidak ada yang tersimpan.
 */
final class ImporWilayahPerintah extends Command
{
    private const KOLOM = ['Kode', 'Nama', 'Tingkat', 'KodeInduk', 'ZonaWaktu'];

    protected $signature = 'pengelola:impor-wilayah {berkas : Path file CSV}';

    protected $description = 'Memuat/menyelaraskan data wilayah resmi dari CSV (P-02).';

    public function handle(ImporWilayah $impor): int
    {
        $path = Str::of(is_string($this->argument('berkas')) ? $this->argument('berkas') : '')->toString();
        $berkas = is_readable($path) ? fopen($path, 'r') : false;

        if ($berkas === false) {
            $this->error("Berkas tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $daftar = [];
        $nomorBaris = 1;
        $kepala = fgetcsv($berkas, escape: '');

        if ($kepala !== self::KOLOM) {
            $this->error('Baris pertama harus: '.implode(',', self::KOLOM));
            fclose($berkas);

            return self::FAILURE;
        }

        while (($baris = fgetcsv($berkas, escape: '')) !== false) {
            $nomorBaris++;

            if ($baris === [null]) {
                continue;
            }

            [$kode, $nama, $tingkat, $kodeInduk, $zonaWaktu] = array_pad(array_map(fn ($nilai) => trim((string) $nilai), $baris), 5, '');
            $enumTingkat = TingkatWilayah::tryFrom($tingkat);
            $enumZona = ZonaWaktu::tryFrom($zonaWaktu);

            if ($enumTingkat === null || $enumZona === null || $kode === '' || $nama === '') {
                $this->error("Baris {$nomorBaris}: Kode, Nama, Tingkat (Provinsi/KabupatenKota), dan ZonaWaktu (WIB/WITA/WIT) wajib valid.");
                fclose($berkas);

                return self::FAILURE;
            }

            $daftar[] = new DataWilayah($kode, $nama, $enumTingkat, $kodeInduk === '' ? null : $kodeInduk, $enumZona);
        }

        fclose($berkas);

        try {
            $hasil = $impor->Jalankan($daftar, basename($path));
        } catch (PelanggaranAturanBisnis $galat) {
            $this->error($galat->getMessage());

            return self::FAILURE;
        }

        $this->info("Wilayah dimuat: {$hasil['Baru']} baru, {$hasil['Diubah']} diperbarui.");

        return self::SUCCESS;
    }
}
