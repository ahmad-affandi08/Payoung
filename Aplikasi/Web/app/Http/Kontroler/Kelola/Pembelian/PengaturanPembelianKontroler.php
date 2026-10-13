<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Pembelian\Aksi\UbahPengaturanPembelian;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use App\Http\Permintaan\Kelola\Pembelian\UbahPengaturanPembelianPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Pengaturan pembelian (F-04 fase 1): batas persetujuan PO & toleransi penerimaan (izin `pembelian.po.setujui`). */
final class PengaturanPembelianKontroler extends DasarPembelianKontroler
{
    public function Tampilkan(PengaturanPembelianTenant $pengaturan): Response
    {
        $data = $pengaturan->Ambil();

        return Inertia::render('Kelola/Pembelian/Pengaturan', [
            'Pengaturan' => [
                'BatasPersetujuanPo' => $data->batasPersetujuanPo->KeString(),
                'ToleransiPenerimaanPersen' => (string) $data->toleransiPenerimaanPersen,
                'DrafPoOtomatis' => $data->drafPoOtomatis,
                'SumberDrafPo' => $data->sumberDrafPo,
                'HariCakupanDrafPo' => $data->hariCakupanDrafPo,
            ],
        ]);
    }

    public function Simpan(UbahPengaturanPembelianPermintaan $permintaan, UbahPengaturanPembelian $ubah): RedirectResponse
    {
        $ubah->Jalankan($permintaan->AmbilData(), $this->Pelaku()->Id);

        return back()->with('Kilat', 'Pengaturan pembelian disimpan. Berlaku untuk pengajuan PO dan penerimaan berikutnya.');
    }
}
