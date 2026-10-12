<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tagihan;

use App\Domain\Pengelola\Tagihan\Aksi\AjukanRefundLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\CatatPembayaranManualLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\HapusBukuTagihanLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\PutuskanRefundLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\SimpanRekeningTujuanPlatform;
use App\Domain\Pengelola\Tagihan\Aksi\TerbitkanTagihanManualLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\UbahBatasRefundLangganan;
use App\Domain\Pengelola\Tagihan\Aksi\UbahStatusPkpPlatform;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Tagihan\AjukanRefundPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\AlasanTagihanPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\CatatPembayaranManualPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\SimpanRekeningTujuanPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\TerbitkanTagihanManualPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\UbahBatasRefundPermintaan;
use App\Http\Permintaan\Pengelola\Tagihan\UbahStatusPkpPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * Tindakan tagihan langganan di luar verifikasi bukti (P-08, D-112, audit E4/E6): tagihan manual paket harga negosiasi,
 * pembayaran dicatat langsung, hapus-buku, dan refund beserta persetujuannya. Izin per tindakan ditegakkan di rute.
 */
final class TagihanManualKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Terbitkan(Tenant $tenant, TerbitkanTagihanManualPermintaan $permintaan, TerbitkanTagihanManualLangganan $terbitkan): RedirectResponse
    {
        $tagihan = $terbitkan->Jalankan(
            $this->AmbilPelaku(),
            $tenant,
            $permintaan->string('KodePaket')->toString(),
            $permintaan->AmbilSiklus(),
            $permintaan->string('Subtotal')->toString(),
            $permintaan->AmbilKeterangan(),
        );

        return redirect()->route('pengelola.tagihan.tampil', ['tagihan' => $tagihan->Uuid])
            ->with('Kilat', "Tagihan manual {$tagihan->Nomor} diterbitkan. Owner membayar dengan transfer dan mengunggah buktinya, atau catat pembayarannya di sini.");
    }

    public function CatatPembayaran(string $tagihan, CatatPembayaranManualPermintaan $permintaan, CatatPembayaranManualLangganan $catat): RedirectResponse
    {
        $catat->Jalankan(
            $this->AmbilPelaku(),
            $tagihan,
            $permintaan->string('JumlahDiterima')->toString(),
            $permintaan->AmbilTanggalTransfer(),
            $permintaan->string('NamaPengirim')->trim()->toString(),
            $permintaan->AmbilBankPengirim(),
            $permintaan->AmbilCatatan(),
        );

        return back()->with('Kilat', 'Pembayaran dicatat. Tagihan lunas dan langganan tenant aktif.');
    }

    public function HapusBuku(string $tagihan, AlasanTagihanPermintaan $permintaan, HapusBukuTagihanLangganan $hapus): RedirectResponse
    {
        $data = $hapus->Jalankan($this->AmbilPelaku(), $tagihan, $permintaan->AmbilAlasan());

        return back()->with('Kilat', "Tagihan {$data->Nomor} dihapus-buku.");
    }

    public function AjukanRefund(string $tagihan, AjukanRefundPermintaan $permintaan, AjukanRefundLangganan $ajukan): RedirectResponse
    {
        $refund = $ajukan->Jalankan($this->AmbilPelaku(), $tagihan, $permintaan->AmbilAlasan(), $permintaan->AmbilCatatanPengembalian());

        return back()->with('Kilat', $refund->Status === StatusRefundLangganan::Disetujui
            ? 'Refund disetujui dan dilaksanakan. Kembalikan dananya ke rekening tenant, lalu pemilik usaha diberi tahu lewat email.'
            : 'Refund diajukan. Karena di atas batas, Super Admin perlu menyetujuinya.');
    }

    public function SetujuiRefund(string $refund, PutuskanRefundLangganan $putuskan): RedirectResponse
    {
        $putuskan->Setujui($this->AmbilPelaku(), $refund);

        return back()->with('Kilat', 'Refund disetujui dan dilaksanakan. Kembalikan dananya ke rekening tenant.');
    }

    /** D-113: Super Admin mengubah batas refund tanpa persetujuan kedua dari konsol. */
    public function UbahBatasRefund(UbahBatasRefundPermintaan $permintaan, UbahBatasRefundLangganan $ubah): RedirectResponse
    {
        $baru = $ubah->Jalankan($this->AmbilPelaku(), $permintaan->string('Batas')->toString(), $permintaan->AmbilAlasan());

        return back()->with('Kilat', "Batas refund tanpa persetujuan kedua sekarang {$baru->FormatRupiah()}. Berlaku untuk pengajuan berikutnya.");
    }

    /** D-114: Super Admin mengatur rekening tujuan transfer dari konsol. */
    public function SimpanRekeningTujuan(SimpanRekeningTujuanPermintaan $permintaan, SimpanRekeningTujuanPlatform $simpan): RedirectResponse
    {
        $simpan->Jalankan($this->AmbilPelaku(), $permintaan->AmbilRekening(), $permintaan->AmbilAlasan());

        return back()->with('Kilat', 'Rekening tujuan transfer disimpan. Langsung dipakai di tagihan manual berikutnya.');
    }

    /** D-114: Super Admin mengubah status PKP untuk penagihan dari konsol. */
    public function UbahStatusPkp(UbahStatusPkpPermintaan $permintaan, UbahStatusPkpPlatform $ubah): RedirectResponse
    {
        $pkp = $ubah->Jalankan($this->AmbilPelaku(), $permintaan->AmbilPkp(), $permintaan->AmbilAlasan());

        return back()->with('Kilat', $pkp
            ? 'Status PKP dinyalakan. Tagihan berikutnya memuat PPN.'
            : 'Status PKP dimatikan. Tagihan berikutnya tanpa PPN.');
    }

    public function TolakRefund(string $refund, AlasanTagihanPermintaan $permintaan, PutuskanRefundLangganan $putuskan): RedirectResponse
    {
        $putuskan->Tolak($this->AmbilPelaku(), $refund, $permintaan->AmbilAlasan());

        return back()->with('Kilat', 'Pengajuan refund ditolak.');
    }
}
