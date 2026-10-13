<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Model\LogPerangkatDiunggah;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Support\Facades\DB;

/**
 * Menerima log lokal yang diunggah aplikasi kasir atas permintaan dukungan (PGL-16 bagian 2). Hanya diterima bila ada
 * permintaan yang berlaku untuk perangkat itu (`Perangkat::CekPermintaanLogBerjalan`), jadi perangkat
 * tidak bisa menimbun log tanpa diminta (409 `LogTidakDiminta`). Teks disaring lagi dengan aturan yang sama dengan laporan
 * galat (email, token, deret angka panjang) dan dipotong `MAKS_BYTE`. Permintaan dianggap terpenuhi setelah log tersimpan.
 */
final class TerimaLogPerangkat
{
    public const MAKS_BARIS = 500;

    public const MAKS_BYTE = 200_000;

    /**
     * @param  list<string>  $baris
     */
    public function Jalankan(Perangkat $perangkat, array $baris, ?string $versi): LogPerangkatDiunggah
    {
        return DB::transaction(function () use ($perangkat, $baris, $versi): LogPerangkatDiunggah {
            $terkini = Perangkat::query()->lockForUpdate()->findOrFail($perangkat->Id);
            if (! $terkini->CekPermintaanLogBerjalan()) {
                throw new PelanggaranAturanBisnis('LogTidakDiminta', 'Tidak ada permintaan unggah log untuk perangkat ini.', 'Umum', 409);
            }

            $baris = array_slice(array_map(fn (string $b): string => CatatLaporanGalatPerangkat::Saring($b), $baris), -self::MAKS_BARIS);
            $isi = implode("\n", $baris);

            while (strlen($isi) > self::MAKS_BYTE && $baris !== []) {
                array_shift($baris);
                $isi = implode("\n", $baris);
            }

            $log = LogPerangkatDiunggah::query()->create([
                'IdPerangkat' => $terkini->Id,
                'VersiAplikasi' => $versi === null ? null : mb_substr($versi, 0, 30),
                'JumlahBaris' => count($baris),
                'Isi' => $isi,
            ]);
            $terkini->LogDiunggahPada = now();
            $terkini->save();

            return $log;
        });
    }
}
