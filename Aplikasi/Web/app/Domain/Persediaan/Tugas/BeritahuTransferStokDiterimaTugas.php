<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Tugas;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\PengabarStok;
use App\Domain\Persediaan\Model\TransferStok;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * OWN-03 / PLT-08, D-187: kabar "kiriman stok sudah diterima" ke pengirim dan manajer outlet **asal** setiap kali
 * penerimaan dicatat (satu kabar per transfer dan nomor penerimaan). Isinya menyebut bila ada barang rusak (susut) atau
 * ditolak dan dikembalikan ke asal, serta apakah masih ada sisa yang belum diterima. Penerima yang sendiri mencatat
 * penerimaannya tidak dikabari; transfer dari/ke outlet kanvas tidak dikabari.
 */
final class BeritahuTransferStokDiterimaTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idTransfer,
        public readonly int $penerimaanKe,
        public readonly ?int $idPenerima,
    ) {}

    public function handle(PengabarStok $pengabar, InfoGudang $infoGudang): void
    {
        $pengabar->DalamTenant($this->idTenant, function () use ($pengabar, $infoGudang): void {
            $t = TransferStok::query()->whereKey($this->idTransfer)->first();

            if ($t === null || ! in_array($t->Status, [StatusTransferStok::DiterimaSebagian, StatusTransferStok::Diterima], true)
                || $pengabar->CekMelibatkanKanvas($t->IdOutletAsal, $t->IdOutletTujuan)) {
                return;
            }

            $gudang = $infoGudang->AmbilBanyak([$t->IdGudangTujuan]);
            $tujuan = $gudang[$t->IdGudangTujuan]->nama ?? 'gudang tujuan';
            $rusak = Kuantitas::Nol();
            $ditolak = Kuantitas::Nol();

            foreach ($t->Detail()->get() as $d) {
                $rusak = $rusak->Tambah(Kuantitas::Dari($d->JumlahSusut));
                $ditolak = $ditolak->Tambah(Kuantitas::Dari($d->JumlahDitolak));
            }

            $catatan = [];

            if (! $rusak->KeDesimal()->isZero()) {
                $catatan[] = 'ada barang rusak yang dicatat sebagai susut';
            }

            if (! $ditolak->KeDesimal()->isZero()) {
                $catatan[] = 'ada barang ditolak dan dikembalikan ke asal';
            }

            $selesai = $t->Status === StatusTransferStok::Diterima;
            $isi = "{$t->Nomor} ".($selesai ? 'sudah diterima seluruhnya' : 'diterima sebagian, sisanya masih dalam perjalanan')." di {$tujuan}"
                .($catatan === [] ? '.' : '; '.implode(' dan ', $catatan).'.');

            $pengabar->Kabari(
                $this->idTenant,
                $t->IdOutletAsal,
                [$t->DikirimOleh],
                $this->idPenerima,
                JenisNotifikasiPengguna::TransferStokDiterima,
                "TransferStokDiterima:{$t->Uuid}:{$this->penerimaanKe}",
                $selesai ? "Kiriman stok diterima di {$tujuan}" : "Kiriman stok diterima sebagian di {$tujuan}",
                $isi,
                "/kelola/persediaan/transfer/{$t->Uuid}",
            );
        });
    }
}
