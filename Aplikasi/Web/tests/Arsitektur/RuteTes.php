<?php

declare(strict_types=1);

/**
 * `php artisan optimize` (route:cache) tidak memuat ulang berkas `routes/`, jadi apa pun yang didaftarkan di sana selain
 * rute itu sendiri hilang di produksi: binder `Route::bind` yang hilang membuat halaman 404 (halaman versi template
 * sektor Pengelola). Binder, model binding, dan pola parameter wajib didaftarkan di `PenyediaAplikasi`.
 */
describe('Berkas rute aman untuk route:cache', function (): void {
    it('tidak memuat Route::bind, Route::model, atau Route::pattern', function (): void {
        $pelanggar = [];

        foreach (glob(dirname(__DIR__, 2).'/routes/*.php') ?: [] as $berkas) {
            $isi = (string) file_get_contents($berkas);

            if (preg_match('/Route::(bind|model|pattern)\(/', $isi, $cocok) === 1) {
                $pelanggar[basename($berkas)] = $cocok[0];
            }
        }

        expect($pelanggar)->toBe([], 'Pindahkan ke PenyediaAplikasi::boot().');
    });
});
