<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Pembelian\Aksi\SetujuiPesananPembelian;
use App\Domain\Pembelian\Kueri\PesananMenungguPersetujuan;
use App\Domain\Persediaan\Aksi\SetujuiPenyesuaianStok;
use App\Domain\Persediaan\Aksi\TolakPenyesuaianStok;
use App\Domain\Persediaan\Kueri\PenyesuaianMenungguPersetujuan;
use App\Http\Permintaan\Pemilik\V1\TolakDokumenPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persetujuan dokumen jarak jauh di Aplikasi Owner (OWN-04): PO dan penyesuaian stok yang menunggu persetujuan, dengan
 * aturan yang sama persis dengan back-office (aksi `SetujuiPesananPembelian` / `SetujuiPenyesuaianStok`: pembuat tidak
 * boleh menyetujui dokumennya sendiri, status dicek di bawah kunci, posting stok + jurnal sinkron). Daftar hanya berisi
 * jenis dokumen yang izinnya dimiliki pengguna (`pembelian.po.setujui`, `persediaan.penyesuaian.setujui`); aksi
 * dijaga izin di rute. Dokumen di luar outlet akses = 404.
 */
final class PersetujuanDokumenKontroler extends DasarPemilikKontroler
{
    public function Daftar(Request $permintaan, PesananMenungguPersetujuan $po, PenyesuaianMenungguPersetujuan $penyesuaian): JsonResponse
    {
        $boleh = $this->IdOutletBoleh($permintaan);
        $idPengguna = $this->Pengguna($permintaan)->Id;

        return response()->json([
            'PesananPembelian' => $this->CekIzin($permintaan, IzinTenant::PembelianPoSetujui) ? $po->Daftar($boleh, $idPengguna) : [],
            'PenyesuaianStok' => $this->CekIzin($permintaan, IzinTenant::PersediaanPenyesuaianSetujui) ? $penyesuaian->Daftar($boleh, $idPengguna) : [],
        ]);
    }

    public function SetujuiPesanan(Request $permintaan, string $dokumen, PesananMenungguPersetujuan $kueri, SetujuiPesananPembelian $setujui): JsonResponse
    {
        $po = $kueri->Cari(strtoupper($dokumen), $this->IdOutletBoleh($permintaan));
        abort_if($po === null, 404);
        $hasil = $setujui->Jalankan($po, $this->Pengguna($permintaan)->Id);

        return response()->json(['Dokumen' => ['Uuid' => $hasil->Uuid, 'Nomor' => $hasil->Nomor, 'Status' => $hasil->Status->value]]);
    }

    public function TolakPesanan(TolakDokumenPermintaan $permintaan, string $dokumen, PesananMenungguPersetujuan $kueri, SetujuiPesananPembelian $setujui): JsonResponse
    {
        $po = $kueri->Cari(strtoupper($dokumen), $this->IdOutletBoleh($permintaan));
        abort_if($po === null, 404);
        $hasil = $setujui->Jalankan($po, $this->Pengguna($permintaan)->Id, false, (string) $permintaan->string('Alasan'));

        return response()->json(['Dokumen' => ['Uuid' => $hasil->Uuid, 'Nomor' => $hasil->Nomor, 'Status' => $hasil->Status->value]]);
    }

    public function SetujuiPenyesuaian(Request $permintaan, string $dokumen, PenyesuaianMenungguPersetujuan $kueri, SetujuiPenyesuaianStok $setujui): JsonResponse
    {
        $dok = $kueri->Cari(strtoupper($dokumen), $this->IdOutletBoleh($permintaan));
        abort_if($dok === null, 404);
        $hasil = $setujui->Jalankan($dok, $this->Pengguna($permintaan)->Id);

        return response()->json(['Dokumen' => ['Uuid' => $hasil->Uuid, 'Nomor' => $hasil->Nomor, 'Status' => $hasil->Status->value]]);
    }

    public function TolakPenyesuaian(TolakDokumenPermintaan $permintaan, string $dokumen, PenyesuaianMenungguPersetujuan $kueri, TolakPenyesuaianStok $tolak): JsonResponse
    {
        $dok = $kueri->Cari(strtoupper($dokumen), $this->IdOutletBoleh($permintaan));
        abort_if($dok === null, 404);
        $hasil = $tolak->Jalankan($dok, (string) $permintaan->string('Alasan'), $this->Pengguna($permintaan)->Id);

        return response()->json(['Dokumen' => ['Uuid' => $hasil->Uuid, 'Nomor' => $hasil->Nomor, 'Status' => $hasil->Status->value]]);
    }
}
