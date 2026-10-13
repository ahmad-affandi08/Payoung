<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Laporan;

use App\Domain\Bersama\Laporan\PembuatDefinisiLaporan;
use App\Domain\Laporan\Kueri\LaporanPembelian;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Laporan pembelian (§10.10; `/kelola/laporan/pembelian`, izin `pembelian.kelola`, outlet akses pelaku): pembelian per
 * pemasok, per produk, dan pesanan pembelian yang masih menunggu barang. Ekspor D-43 ikut tab dan saringan.
 */
final class LaporanPembelianKontroler extends DasarKelolaKontroler
{
    private const JUDUL_TAB = ['pemasok' => 'Per Pemasok', 'produk' => 'Per Produk', 'po' => 'PO Outstanding'];

    public function Halaman(Request $permintaan, LaporanPembelian $laporan, TanggalBisnisOutlet $tanggal): Response
    {
        $hariIni = $tanggal->Hitung(null);
        $saring = $laporan->BacaSaring($permintaan->query(), $this->IdOutletBoleh(), $hariIni);

        return Inertia::render('Kelola/Laporan/Pembelian', $laporan->AmbilHalaman($saring, $this->IdOutletBoleh(), $hariIni));
    }

    public function Ekspor(Request $permintaan, LaporanPembelian $laporan, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): SymfonyResponse
    {
        $hariIni = $tanggal->Hitung(null);
        $saring = $laporan->BacaSaring($permintaan->query(), $this->IdOutletBoleh(), $hariIni);
        [$kolom, $baris] = $laporan->AmbilEkspor($saring, $hariIni);
        $periode = $saring['Periode'];
        $namaOutlet = '';

        foreach ($outlet->AmbilRingkas($this->IdOutletBoleh()) as $o) {
            if ($saring['UuidOutlet'] !== '' && $o['Uuid'] === $saring['UuidOutlet']) {
                $namaOutlet = $o['Nama'];
            }
        }

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan Pembelian | '.self::JUDUL_TAB[$saring['Tab']],
            PembuatDefinisiLaporan::NamaBerkas('laporan-pembelian', $saring['Tab'], $periode->dari->toDateString(), $periode->sampai->toDateString()),
            $kolom,
            $baris,
            $saring['Tab'] === 'po'
                ? [['Keadaan pada', $this->LabelPeriode($hariIni, $hariIni)]]
                : [['Periode (tanggal faktur)', $this->LabelPeriode($periode->dari, $periode->sampai)]],
            null,
            $this->LabelCakupan($namaOutlet),
        );
    }
}
