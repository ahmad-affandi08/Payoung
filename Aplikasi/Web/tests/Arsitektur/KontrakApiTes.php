<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RuteLaravel;
use Illuminate\Support\Facades\Route;

/*
 * Audit F-13 (aturan emas #16, §16): gerbang kompatibilitas kontrak API POS, Pemilik, dan (sejak v3.82) Open API v1
 * publik. Baseline `Spesifikasi/KontrakApi/RuteApi.json` berisi setiap rute `/api/pos/v1/*`, `/api/pemilik/v1/*`, dan
 * `/api/v1/*` (metode, jalur, nama).
 * Menghapus, mengganti jalur/metode, atau mengganti nama rute yang sudah ada = merusak aplikasi lama → test gagal
 * (perubahan kontrak = versi API baru, misal `/v2`). Rute baru boleh; tambahkan ke baseline dengan menjalankan test ini
 * sekali dengan PERBARUI_KONTRAK_API=1 lalu commit berkasnya.
 */

function AmbilKontrakRuteApi(): array
{
    $hasil = [];

    foreach (Route::getRoutes()->getRoutes() as $rute) {
        /** @var RuteLaravel $rute */
        $jalur = $rute->uri();

        if (! str_starts_with($jalur, 'api/pos/v1/') && ! str_starts_with($jalur, 'api/pemilik/v1/') && ! str_starts_with($jalur, 'api/v1/')) {
            continue;
        }

        foreach (array_diff($rute->methods(), ['HEAD']) as $metode) {
            $hasil["{$metode} {$jalur}"] = ['Metode' => $metode, 'Jalur' => $jalur, 'Nama' => $rute->getName()];
        }
    }

    ksort($hasil);

    return $hasil;
}

it('rute API POS & Pemilik yang sudah dirilis tidak dihapus/diubah. Rute baru tercatat di baseline', function (): void {
    $berkas = base_path('../../Spesifikasi/KontrakApi/RuteApi.json');
    $sekarang = AmbilKontrakRuteApi();

    if (getenv('PERBARUI_KONTRAK_API') === '1') {
        $lama = is_file($berkas) ? json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR) : [];
        file_put_contents($berkas, json_encode([...$lama, ...$sekarang], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    expect(is_file($berkas))->toBeTrue('Baseline kontrak belum ada: jalankan dengan PERBARUI_KONTRAK_API=1.');
    $baseline = json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);

    $rusak = [];

    foreach ($baseline as $kunci => $rute) {
        if (! isset($sekarang[$kunci])) {
            $rusak[] = "{$kunci} dihapus/diubah (aplikasi lama masih memanggilnya)";
        } elseif ($sekarang[$kunci]['Nama'] !== $rute['Nama']) {
            $rusak[] = "{$kunci} berganti nama rute {$rute['Nama']} → {$sekarang[$kunci]['Nama']}";
        }
    }

    expect($rusak)->toBe([])
        ->and(array_keys(array_diff_key($sekarang, $baseline)))->toBe([], 'Rute API baru belum dicatat di baseline: jalankan test ini dengan PERBARUI_KONTRAK_API=1 lalu commit Spesifikasi/KontrakApi/RuteApi.json.');
});
