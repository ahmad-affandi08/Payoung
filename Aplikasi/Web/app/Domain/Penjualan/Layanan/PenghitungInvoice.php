<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Data\HasilHitungInvoice;
use Carbon\CarbonImmutable;

/**
 * Angka invoice (F-21, D-166) dihitung dengan **mesin kalkulasi yang sama** dengan kasir dan grosir lewat
 * `PenghitungGrosir`, bukan rumus ketiga: aturan inklusif/eksklusif, pembulatan pajak, dan DPP nilai lain harus identik,
 * kalau tidak satu tenant melaporkan PPN berbeda untuk jasa yang sama hanya karena dokumennya berbeda.
 *
 * Tambahannya hanya rincian per baris untuk jurnal: pajak baris, pendapatan baris (bruto − pajak inklusif, persis pola
 * J-07.1), dan DPP baris. Tarif diambil dari `TarifPajak` terbit yang berlaku pada **tanggal invoice** (saat PPN
 * terutang bagi pembayaran/penyerahan jasa), bukan hari ini.
 */
final class PenghitungInvoice
{
    public function __construct(private readonly PenghitungGrosir $penghitung) {}

    /**
     * @param  list<array{Jumlah: Kuantitas, HargaSatuan: Uang, Diskon: Uang, IdKelompokPajak: int|null, HargaTermasukPajak: bool|null}>  $baris
     */
    public function Hitung(int $idOutlet, ?string $kodeKota, array $baris, CarbonImmutable $tanggal): HasilHitungInvoice
    {
        $dokumen = $this->penghitung->Hitung($idOutlet, $kodeKota, $baris, $tanggal);

        if ($baris === []) {
            return new HasilHitungInvoice($dokumen, []);
        }

        [$hasil] = $this->penghitung->HitungRinci($idOutlet, $kodeKota, $baris, $tanggal);
        $rincian = [];

        foreach ($hasil->baris as $satuHasil) {
            $pajakInklusif = $satuHasil->pajak->Kurangi($satuHasil->pajakEksklusif);
            $rincian[] = [
                'Pajak' => $satuHasil->pajak,
                'Pendapatan' => $satuHasil->bruto->Kurangi($pajakInklusif),
                'Dpp' => $satuHasil->bruto->Kurangi($satuHasil->diskon)->Kurangi($pajakInklusif),
            ];
        }

        return new HasilHitungInvoice($dokumen, $rincian);
    }
}
