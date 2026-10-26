<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\HapusPemetaanAkunOutlet;
use App\Domain\Akuntansi\Aksi\UbahPemetaanAkun;
use App\Domain\Akuntansi\Kueri\DaftarPemetaanAkun;
use App\Domain\Organisasi\Kueri\OutletUtama;
use App\Domain\PanduanAwal\Aksi\LengkapiAkunDariTemplate;
use App\Http\Permintaan\Kelola\Akuntansi\UbahPemetaanAkunPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pemetaan akun (F-13a): lihat `laporan.keuangan.lihat`, ubah `akuntansi.kelola`. Override outlet hanya untuk outlet
 * dalam akses pelaku (di luar akses = 404). Perubahan berlaku untuk jurnal berikutnya.
 */
final class PemetaanAkunKontroler extends DasarAkuntansiKontroler
{
    public function Daftar(DaftarPemetaanAkun $daftar): Response
    {
        return Inertia::render('Kelola/Akuntansi/Pemetaan/Daftar', [...$daftar->Ambil($this->IdOutletBoleh()), 'Izin' => [
            'Kelola' => $this->CekIzinKelola(),
            // Pemetaan umum berlaku untuk semua outlet: hanya pelaku tanpa batas outlet yang boleh mengubahnya.
            'UbahSemuaOutlet' => $this->IdOutletBoleh() === null,
        ]]);
    }

    public function Simpan(UbahPemetaanAkunPermintaan $permintaan, UbahPemetaanAkun $ubah): RedirectResponse
    {
        $uuidOutlet = $permintaan->AmbilUuidOutlet();
        // Pemetaan tingkat tenant berlaku untuk semua outlet: hanya pelaku tanpa batas outlet yang boleh mengubahnya.
        abort_if($uuidOutlet === null && $this->IdOutletBoleh() !== null, 403);
        $peran = $permintaan->AmbilPeran();
        $ubah->Jalankan($peran, $uuidOutlet === null ? null : $this->CariOutlet($uuidOutlet)->Id, (string) $permintaan->validated('UuidAkun'));

        return to_route('kelola.akuntansi.pemetaan.daftar')->with('Kilat', "Pemetaan {$peran->AmbilLabel()} disimpan. Berlaku untuk jurnal berikutnya.");
    }

    /**
     * Audit kemudahan pakai #12: akun & pemetaan tingkat tenant yang belum ada dilengkapi dari template sektor outlet
     * utama (atau template umum). Pemetaan yang sudah diatur tidak berubah. Hanya pelaku tanpa batas outlet.
     */
    public function PerbaikiOtomatis(LengkapiAkunDariTemplate $lengkapi, OutletUtama $outletUtama): RedirectResponse
    {
        abort_if($this->IdOutletBoleh() !== null, 403);
        $hasil = $lengkapi->Jalankan($outletUtama->CariAktifRingkas($outletUtama->AmbilId())?->idTemplateSektorVersi);
        $jumlah = count($hasil['Pemetaan']);

        return back()->with('Kilat', $jumlah === 0 && $hasil['Akun'] === []
            ? 'Pemetaan akun sudah lengkap. Tidak ada yang diubah.'
            : "Akun jurnal dilengkapi dari {$hasil['Template']}: {$jumlah} pemetaan baru. Pemetaan yang sudah ada tidak diubah.");
    }

    public function Hapus(UbahPemetaanAkunPermintaan $permintaan, HapusPemetaanAkunOutlet $hapus): RedirectResponse
    {
        $peran = $permintaan->AmbilPeran();
        $outlet = $this->CariOutlet((string) $permintaan->AmbilUuidOutlet());
        $hapus->Jalankan($peran, $outlet->Id);

        return to_route('kelola.akuntansi.pemetaan.daftar')->with('Kilat', "Pemetaan khusus {$outlet->Nama} untuk {$peran->AmbilLabel()} dihapus. Outlet ini kembali memakai pemetaan umum.");
    }
}
