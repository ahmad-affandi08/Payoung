<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Grosir;

use App\Domain\Laporan\Kueri\RekapKanvasHarian;
use App\Domain\Organisasi\Aksi\BuatOutletKanvas;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\DaftarOutletKanvas;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Kueri\TransferKanvasHarian;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\Grosir\BuatOutletKanvasPermintaan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kanvas (Modul Salesman bagian 3, §9.7, Grosir › Kanvas, `/kelola/grosir/kanvas`): daftar kendaraan kanvas (outlet
 * bertanda `Kanvas`) dan rekap harian satu kendaraan — muat, terjual, retur, bongkar, sisa per produk serta uang
 * tunai/tempo dan setoran shift. Halaman hanya membaca; muat & bongkar memakai transfer stok F-05b, penjualan & setoran
 * memakai aplikasi kasir. Izin `grosir.kelola` dijaga rute; "Tambah kendaraan kanvas" memakai izin tambah outlet
 * (`outlet.kelola`). Batas outlet pelaku berlaku (kendaraan di luar akses tidak tampil).
 */
final class KanvasKontroler extends DasarGrosirKontroler
{
    public function Daftar(
        Request $permintaan,
        DaftarOutletKanvas $daftar,
        RekapKanvasHarian $rekap,
        TanggalBisnisOutlet $tanggalBisnis,
        PemakaianBatasOrganisasi $pemakaian,
        PastikanBatasPaket $batasPaket,
        TransferKanvasHarian $transferHarian,
    ): Response {
        $kanvas = $daftar->Ambil($this->IdOutletBoleh());
        $uuidDiminta = $permintaan->query('outlet');
        $terpilih = null;

        foreach ($kanvas as $baris) {
            if ($baris['Uuid'] === $uuidDiminta) {
                $terpilih = $baris;
            }
        }

        $terpilih ??= $kanvas[0] ?? null;
        $tanggal = null;
        $tanggalDiminta = $permintaan->query('tanggal');

        if (is_string($tanggalDiminta) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalDiminta) === 1 && checkdate((int) substr($tanggalDiminta, 5, 2), (int) substr($tanggalDiminta, 8, 2), (int) substr($tanggalDiminta, 0, 4))) {
            $tanggal = CarbonImmutable::parse($tanggalDiminta);
        }

        // Bawaan: hari bisnis kendaraan terpilih (zona waktu & jam tutup buku outletnya).
        $tanggal ??= $tanggalBisnis->Hitung($terpilih['Id'] ?? null);

        return Inertia::render('Kelola/Grosir/Kanvas/Daftar', [
            'Kanvas' => array_map(fn (array $b): array => [
                'Uuid' => $b['Uuid'],
                'Kode' => $b['Kode'],
                'Nama' => $b['Nama'],
                'NomorKendaraan' => $b['NomorKendaraan'],
                'Status' => $b['Status'],
                'UuidGudang' => $b['UuidGudang'],
                'NamaGudang' => $b['NamaGudang'],
            ], $kanvas),
            'UuidTerpilih' => $terpilih['Uuid'] ?? null,
            'Tanggal' => $tanggal->toDateString(),
            'Rekap' => $terpilih === null ? null : $rekap->Ambil($terpilih['Id'], $terpilih['IdGudang'], $tanggal),
            'Transfer' => ($terpilih['IdGudang'] ?? null) === null ? [] : $transferHarian->Ambil($terpilih['IdGudang'], $tanggal),
            'BatasOutlet' => $batasPaket->AmbilRingkasan($this->IdTenant(), 'BatasOutlet', $pemakaian->HitungOutlet()),
            'Izin' => $this->AmbilIzinGrosir(),
            'IzinKanvas' => [
                'TambahKendaraan' => $this->CekIzin(IzinTenant::OutletKelola),
                'Transfer' => $this->CekIzin(IzinTenant::PersediaanKelola),
            ],
        ]);
    }

    public function Simpan(BuatOutletKanvasPermintaan $permintaan, BuatOutletKanvas $buat): RedirectResponse
    {
        $outlet = $buat->Jalankan($permintaan->AmbilNomorKendaraan());

        return redirect()->route('kelola.grosir.kanvas.daftar', ['outlet' => $outlet->Uuid])
            ->with('Kilat', "{$outlet->Nama} ditambahkan beserta lokasi stok Toko-nya (bak kendaraan). Daftarkan HP salesman sebagai perangkat Kasir di outlet ini, lalu muat stok lewat transfer.");
    }
}
