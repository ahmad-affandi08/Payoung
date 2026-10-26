<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Unduhan\Aksi\AntrekanBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Aksi\ProsesBerkasUnduhan;
use App\Http\Respons\PenyajiLaporan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekspor laporan besar lewat Pusat Unduhan (D-124): bila rute ekspor dipanggil dengan `?latarbelakang=1` (Excel atau
 * CSV), peminta langsung dialihkan ke halaman Pusat Unduhan, sedangkan penangan ekspor yang sebenarnya dijalankan
 * setelah respons dikirim (fase `terminating`) dengan batas baris yang lebih besar. Berkas hasilnya diunduh dari sana.
 *
 * Dipasang di tingkat rute SETELAH middleware izin & cakupan outlet, sehingga otorisasi sudah diperiksa sebelum apa pun
 * ditunda, dan penangan yang ditunda berjalan dengan pengguna, tenant, dan saringan yang sama dengan permintaan aslinya.
 * Cetak/PDF tidak didukung di latar belakang (halaman itu dirender peramban) dan jatuh ke perilaku biasa.
 */
final class EksporDiLatarBelakang
{
    public function __construct(
        private readonly AntrekanBerkasUnduhan $antrekan,
        private readonly ProsesBerkasUnduhan $proses,
    ) {}

    public function handle(Request $permintaan, Closure $lanjut): Response
    {
        $format = PenyajiLaporan::AmbilFormat($permintaan);

        if (! $permintaan->isMethod('GET') || ! $permintaan->boolean('latarbelakang') || $format === PenyajiLaporan::FORMAT_CETAK) {
            return $lanjut($permintaan);
        }

        $pelaku = $permintaan->user();
        abort_if($pelaku === null, 403);

        try {
            $berkas = $this->antrekan->Jalankan((int) $pelaku->getAuthIdentifier(), $format, $this->JudulAwal($permintaan));
        } catch (PelanggaranAturanBisnis $galat) {
            return redirect()->route('kelola.unduhan.daftar')->withErrors(['Umum' => $galat->getMessage()]);
        }

        // Satu kali saja: pada proses yang melayani banyak permintaan (Octane, test), callback `terminating` yang
        // terdaftar tetap ikut dijalankan di akhir permintaan berikutnya.
        $sudahJalan = false;
        app()->terminating(function () use (&$sudahJalan, $berkas, $lanjut, $permintaan): void {
            if ($sudahJalan) {
                return;
            }

            $sudahJalan = true;
            $this->proses->Jalankan($berkas, fn (): Response => $lanjut($permintaan));
        });

        return redirect()->route('kelola.unduhan.daftar')->with('Kilat', 'Berkas sedang disiapkan. Halaman ini diperbarui otomatis. Unduh begitu statusnya Siap.');
    }

    /** "kelola.laporan.penjualan.ekspor" → "Laporan penjualan"; diganti nama berkas sebenarnya setelah selesai. */
    private function JudulAwal(Request $permintaan): string
    {
        $nama = (string) $permintaan->route()?->getName();
        $bagian = array_values(array_filter(explode('.', $nama), fn (string $b): bool => $b !== 'kelola' && $b !== 'ekspor'));

        return $bagian === [] ? 'Laporan' : ucfirst(str_replace('-', ' ', implode(' ', $bagian)));
    }
}
