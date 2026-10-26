<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\PanduanAwal\Aksi\TerapkanPembaruanTemplate;
use App\Domain\PanduanAwal\Kueri\PembaruanTemplateSektor;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P-03 langkah 6: Owner melihat dan menerapkan pembaruan template jenis usaha per outlet (izin `outlet.kelola`).
 */
final class PembaruanTemplateKontroler extends Kontroler
{
    public function Tampilkan(PembaruanTemplateSektor $tawaran): Response
    {
        return Inertia::render('Kelola/Pengaturan/PembaruanTemplate', ['Tawaran' => $tawaran->AmbilTawaran()]);
    }

    public function Terapkan(string $uuidOutlet, TerapkanPembaruanTemplate $terapkan): RedirectResponse
    {
        $hasil = $terapkan->Jalankan($uuidOutlet);
        $bagian = array_filter([
            $hasil->jumlahAkun > 0 ? "{$hasil->jumlahAkun} akun" : null,
            $hasil->jumlahPemetaan > 0 ? "{$hasil->jumlahPemetaan} pemetaan akun" : null,
            $hasil->jumlahKategori > 0 ? "{$hasil->jumlahKategori} kategori" : null,
            $hasil->jumlahSatuan > 0 ? "{$hasil->jumlahSatuan} satuan" : null,
            $hasil->jumlahKelompokPajak > 0 ? "{$hasil->jumlahKelompokPajak} kelompok pajak" : null,
            $hasil->jumlahFitur > 0 ? "{$hasil->jumlahFitur} fitur" : null,
            $hasil->jumlahStasiunDapur > 0 ? "{$hasil->jumlahStasiunDapur} stasiun dapur" : null,
        ]);

        return back()->with('Kilat', "Template {$hasil->namaTemplate} diperbarui ke versi {$hasil->versi}. ".($bagian === [] ? 'Tidak ada data baru yang perlu ditambahkan, yang sudah Anda miliki tidak diubah.' : 'Ditambahkan: '.implode(', ', $bagian).'. Data yang sudah ada tidak diubah.'));
    }
}
