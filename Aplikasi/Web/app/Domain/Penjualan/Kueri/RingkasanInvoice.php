<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;

/**
 * Ringkasan penagihan invoice satu periode (F-21) untuk kartu di Beranda dan daftar Invoice: berapa yang **ditagihkan**
 * (jumlah tagihan invoice terbit bertanggal dalam periode, termasuk invoice uang muka; invoice akhir hanya sisa setelah
 * DP), berapa yang **diterima** (pelunasan dalam periode), piutang invoice yang **beredar**, dan yang **lewat jatuh
 * tempo**. Null bila usaha belum punya invoice sama sekali, supaya Beranda toko kasir biasa tidak memuat kartu kosong.
 */
final class RingkasanInvoice
{
    public function __construct(private readonly PencatatPiutangPenjualan $piutang) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Dari: string, Sampai: string, Ditagih: string, Diterima: string, Beredar: array{Jumlah: int, Sisa: string}, LewatTempo: array{Jumlah: int, Sisa: string}, Draf: int}|null
     */
    public function Ambil(CarbonImmutable $hariIni, ?array $idOutletBoleh): ?array
    {
        $batas = fn ($q) => $q->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []));

        if (! $batas(Invoice::query())->exists()) {
            return null;
        }

        $dari = $hariIni->startOfMonth()->toDateString();
        $sampai = $hariIni->endOfMonth()->toDateString();
        $ditagih = Uang::Nol();

        foreach ($batas(Invoice::query())->where('Status', StatusInvoice::Terbit->value)->whereBetween('Tanggal', [$dari, $sampai])->pluck('Tagihan') as $tagihan) {
            $ditagih = $ditagih->Tambah(Uang::Dari((string) $tagihan));
        }

        $piutang = $this->piutang->RingkasanInvoice($idOutletBoleh, $dari, $sampai, $hariIni->toDateString());

        return [
            'Dari' => $dari,
            'Sampai' => $sampai,
            'Ditagih' => $ditagih->KeString(),
            'Diterima' => $piutang['Diterima'],
            'Beredar' => $piutang['Beredar'],
            'LewatTempo' => $piutang['LewatTempo'],
            'Draf' => $batas(Invoice::query())->where('Status', StatusInvoice::Draf->value)->count(),
        ];
    }
}
