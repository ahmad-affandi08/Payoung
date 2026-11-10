<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Katalog\Aksi;

use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PaketFitur;
use Illuminate\Support\Facades\DB;

/**
 * Menyusul fitur katalog yang ditambahkan rilis setelah server dipasang (P-04). `SiapkanKatalogBawaan` hanya membuat
 * paket yang belum ada, jadi paket yang sudah terpasang tidak pernah mendapat fitur baru dari `KatalogPaket.json`
 * (contoh: `persetujuan.jarak-jauh` pada Bisnis & Enterprise). Aksi ini **hanya menambah**, dan secara bawaan hanya
 * untuk fitur yang **baru dibuat pada pemanggilan ini** (barisnya belum ada di tabel `Fitur`): fitur baru itu dipasang
 * ke paket yang menyebutnya di berkas data. Fitur yang barisnya sudah ada dianggap sudah pernah dikenal paket, jadi bila
 * sebuah paket tidak memilikinya, itu keputusan yang diambil lewat konsol (dicabut) dan TIDAK dipasang ulang, walau
 * berkas data masih mencantumkannya. Berkas data hanyalah titik awal, konsol adalah sumber kebenaran setelah terpasang.
 * `$termasukFiturLama` (opt-in sadar, `--termasuk-lama`) memulihkan perilaku lama: semua fitur berkas yang belum dimiliki
 * paket ditambahkan, termasuk yang pernah dicabut. Tidak ada yang pernah dihapus. Idempoten. Paket yang tidak ada di
 * server dilewati (pembuatannya urusan seeder).
 */
final class LengkapiFiturPaketBawaan
{
    /**
     * @return array{FiturBaru: list<string>, Penambahan: list<array{Paket: string, Fitur: string}>}
     */
    public function Jalankan(bool $terapkan = true, ?string $pathFitur = null, ?string $pathPaket = null, bool $termasukFiturLama = false): array
    {
        $dataFitur = SiapkanKatalogBawaan::BacaJson($pathFitur ?? database_path('Data/KatalogFitur.json'), 'Fitur');
        $dataPaket = SiapkanKatalogBawaan::BacaJson($pathPaket ?? database_path('Data/KatalogPaket.json'), 'Paket');

        return DB::transaction(function () use ($terapkan, $dataFitur, $dataPaket, $termasukFiturLama): array {
            $fiturBaru = [];
            $penambahan = [];
            $adaFitur = Fitur::query()->pluck('Kunci')->flip()->all();
            $kunciBaru = [];

            foreach ($dataFitur as $fitur) {
                $kunci = SiapkanKatalogBawaan::AmbilTeks($fitur, 'Kunci');

                if (isset($adaFitur[$kunci])) {
                    continue;
                }

                $fiturBaru[] = $kunci;
                $kunciBaru[$kunci] = true;
                $adaFitur[$kunci] = true;

                if ($terapkan) {
                    Fitur::query()->create(['Kunci' => $kunci, 'Nama' => SiapkanKatalogBawaan::AmbilTeks($fitur, 'Nama'), 'Modul' => SiapkanKatalogBawaan::AmbilTeks($fitur, 'Modul')]);
                }
            }

            foreach ($dataPaket as $data) {
                $kode = SiapkanKatalogBawaan::AmbilTeks($data, 'Kode');
                $paket = Paket::query()->where('Kode', $kode)->first();

                if ($paket === null) {
                    continue;
                }

                $dimiliki = array_flip($paket->AmbilKunciFitur());

                foreach (is_array($data['Fitur'] ?? null) ? $data['Fitur'] : [] as $kunci) {
                    $kunci = (string) $kunci;

                    if (isset($dimiliki[$kunci]) || ! isset($adaFitur[$kunci])) {
                        continue;
                    }

                    // Fitur lama yang tidak dimiliki paket = sengaja dicabut di konsol, jangan dipasang ulang.
                    if (! $termasukFiturLama && ! isset($kunciBaru[$kunci])) {
                        continue;
                    }

                    $penambahan[] = ['Paket' => $kode, 'Fitur' => $kunci];

                    if ($terapkan) {
                        PaketFitur::query()->create(['IdPaket' => $paket->Id, 'KunciFitur' => $kunci]);
                    }
                }
            }

            return ['FiturBaru' => $fiturBaru, 'Penambahan' => $penambahan];
        });
    }
}
