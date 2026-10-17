<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\PanduanAwal\Aksi\GantiSektorUsaha;
use App\Domain\PanduanAwal\Kueri\PilihanGantiSektor;
use App\Http\Permintaan\Kelola\GantiSektorPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan › Ganti jenis usaha (D-165, izin `data.kelola` khusus Pemilik): pratinjau lalu penerapan jenis usaha baru
 * untuk satu outlet, dengan pembersihan sisa template lama yang belum dipakai.
 */
final class GantiSektorKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(Request $permintaan, PilihanGantiSektor $pilihan, GantiSektorUsaha $ganti): Response
    {
        $uuidOutlet = $permintaan->query('UuidOutlet');
        $kodeTemplate = $permintaan->query('KodeTemplate');
        $pratinjau = is_string($uuidOutlet) && $uuidOutlet !== '' && is_string($kodeTemplate) && $kodeTemplate !== ''
            ? $ganti->Pratinjau($uuidOutlet, $kodeTemplate)
            : null;

        return Inertia::render('Kelola/Pengaturan/GantiSektor', [...$pilihan->Ambil(), 'Pratinjau' => $pratinjau]);
    }

    public function Terapkan(GantiSektorPermintaan $permintaan, GantiSektorUsaha $ganti): RedirectResponse
    {
        $hasil = $ganti->Jalankan(
            $permintaan->string('UuidOutlet')->toString(),
            $permintaan->string('KodeTemplate')->toString(),
            $permintaan->boolean('Bersihkan'),
        );
        $bersih = array_filter([
            count($hasil['Dibersihkan']['Akun']) > 0 ? count($hasil['Dibersihkan']['Akun']).' akun' : null,
            count($hasil['Dibersihkan']['Kategori']) > 0 ? count($hasil['Dibersihkan']['Kategori']).' kategori' : null,
            count($hasil['Dibersihkan']['StasiunDapur']) > 0 ? count($hasil['Dibersihkan']['StasiunDapur']).' stasiun dapur diarsipkan' : null,
            count($hasil['Dibersihkan']['Fitur']) > 0 ? count($hasil['Dibersihkan']['Fitur']).' fitur' : null,
        ]);

        return redirect()->route('kelola.pengaturan.ganti-sektor')->with(
            'Kilat',
            "Jenis usaha {$hasil['NamaOutlet']} kini {$hasil['Baru']['Nama']}. ".($bersih === [] ? 'Tidak ada sisa jenis usaha lama yang dibersihkan.' : 'Dibersihkan karena belum dipakai: '.implode(', ', $bersih).'.').' Menu yang bergantung jenis usaha ikut menyesuaikan; ringkasannya ada di halaman ini.',
        );
    }
}
