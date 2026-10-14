<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Kasir;

use App\Domain\Kasir\Aksi\UbahPengaturanKasir;
use App\Domain\Penjualan\Enum\ArahPembulatan;
use App\Domain\Tenant\Aksi\UbahBarcodeTimbanganTenant;
use App\Domain\Tenant\Kueri\PengaturanBarcodeTimbanganTenant;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Kasir\UbahBarcodeTimbanganPermintaan;
use App\Http\Permintaan\Kelola\Kasir\UbahPengaturanKasirPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman pengaturan kasir, izin `outlet.kelola`: batas kas keluar tanpa persetujuan (BR-06.4), shift bersama
 * (BR-06.2), batas diskon manual kasir & penyetuju (BR-07.3), pembulatan tunai (BR-08.6), serta tutup shift buta &
 * toleransi selisih kas (F-11), dan batas hari retur (F-09).
 */
final class PengaturanKasirKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(PengaturanKasirTenant $pengaturan, PengaturanBarcodeTimbanganTenant $timbangan): Response
    {
        $data = $pengaturan->Ambil();

        return Inertia::render('Kelola/Kasir/Pengaturan', [
            'BatasKasKeluar' => $data->batasKasKeluar->KeString(),
            'ShiftBersama' => $data->shiftBersama,
            'BatasDiskonManual' => (string) $data->batasDiskonManual,
            'BatasDiskonPenyetuju' => (string) $data->batasDiskonPenyetuju,
            'PembulatanTunai' => $data->AmbilPembulatanTunaiLarik(),
            'TutupShiftButa' => $data->tutupShiftButa,
            'ToleransiSelisihKas' => $data->toleransiSelisihKas->KeString(),
            'BatasHariRetur' => $data->batasHariRetur,
            'BatasHariLewatJatuhTempo' => $data->batasHariLewatJatuhTempo,
            'BukaLaciPerluPin' => $data->bukaLaciPerluPin,
            'BatasReturTanpaStrukHarian' => $data->batasReturTanpaStrukHarian->KeString(),
            'BatasNotifikasiTransaksi' => $data->batasNotifikasiTransaksi->KeString(),
            // v3.55 (§9.3): barcode timbangan berawalan 21–29 (berat/harga di EAN-13).
            'BarcodeTimbangan' => $timbangan->Ambil(),
            'OpsiArahPembulatan' => array_map(fn (ArahPembulatan $arah): array => ['Nilai' => $arah->value, 'Label' => $arah->AmbilLabel()], ArahPembulatan::cases()),
        ]);
    }

    public function Simpan(UbahPengaturanKasirPermintaan $permintaan, UbahPengaturanKasir $ubah, PengaturanKasirTenant $pengaturan): RedirectResponse
    {
        $ubah->Jalankan($permintaan->AmbilData($pengaturan->Ambil()));

        return to_route('kelola.kasir.pengaturan')->with('Kilat', 'Pengaturan kasir disimpan.');
    }

    public function SimpanBarcodeTimbangan(UbahBarcodeTimbanganPermintaan $permintaan, UbahBarcodeTimbanganTenant $ubah): RedirectResponse
    {
        $ubah->Jalankan($permintaan->boolean('Aktif'), $permintaan->AmbilAwalan(), (string) $permintaan->validated('Nilai'));

        return to_route('kelola.kasir.pengaturan')->with('Kilat', 'Pengaturan barcode timbangan disimpan.');
    }
}
