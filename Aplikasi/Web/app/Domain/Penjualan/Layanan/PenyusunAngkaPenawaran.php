<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;
use Carbon\CarbonImmutable;

/**
 * Menghitung ulang angka satu penawaran dari barisnya (F-21 bagian 3, D-168) dengan mesin kalkulasi yang sama dengan
 * invoice (`PenghitungInvoice`), pada **tanggal penawaran**: pajak di penawaran harus sama dengan pajak invoice yang kelak
 * lahir darinya bila tarifnya tidak berubah. Dipakai saat draf disimpan dan saat diterbitkan. Angka di penawaran adalah
 * perkiraan yang dijanjikan kepada pelanggan, bukan dokumen pembukuan; invoice menghitung ulang pada tanggalnya sendiri.
 */
final class PenyusunAngkaPenawaran
{
    public function __construct(private readonly PenghitungInvoice $penghitung) {}

    /**
     * @throws PelanggaranAturanBisnis OutletTidakDikenal
     */
    public function Terapkan(Penawaran $penawaran): void
    {
        $outlet = Outlet::query()->whereKey($penawaran->IdOutlet)->first()
            ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet penawaran ini tidak ditemukan.', 'IdOutlet');
        $detail = PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->orderBy('Urutan')->get()->values();
        $hasil = $this->penghitung->Hitung($outlet->Id, $outlet->KodeKota, array_values(array_map(
            fn (PenawaranDetail $d): array => [
                'Jumlah' => Kuantitas::Dari($d->Jumlah),
                'HargaSatuan' => Uang::Dari($d->Harga),
                'Diskon' => Uang::Dari($d->Diskon),
                'IdKelompokPajak' => $d->IdKelompokPajak,
                // Selalu eksplisit, sama seperti invoice: isian tingkat dokumen menggantikan pengaturan outlet.
                'HargaTermasukPajak' => $d->HargaTermasukPajak ?? $penawaran->HargaTermasukPajak,
            ],
            $detail->all(),
        )), CarbonImmutable::parse($penawaran->Tanggal->format('Y-m-d')));

        foreach ($detail as $indeks => $d) {
            $d->fill(['Pajak' => $hasil->baris[$indeks]['Pajak']->KeString()])->save();
        }

        $dokumen = $hasil->dokumen;
        $penawaran->fill([
            'Subtotal' => $dokumen->subtotal->KeString(),
            'Diskon' => $dokumen->diskon->KeString(),
            'DasarPengenaanPajak' => $dokumen->dasarPengenaanPajak->KeString(),
            'Pajak' => $dokumen->pajak->KeString(),
            'Total' => $dokumen->total->KeString(),
            'TarifPpn' => $dokumen->tarifPpn,
            'PengaliDppPembilang' => $dokumen->pengaliDppPembilang,
            'PengaliDppPenyebut' => $dokumen->pengaliDppPenyebut,
            'RincianPajak' => array_map(fn (Uang $n): string => $n->KeString(), $dokumen->rincianPajak),
        ])->save();
    }
}
