<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\TransferStok;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * OWN-03 / PLT-08: kabar "kiriman stok masuk" ke pengguna ber-izin `persediaan.kelola` di outlet tujuan transfer, begitu
 * transfer dikirim, supaya barang yang tiba bisa langsung diperiksa dan diterima. Pengirimnya sendiri tidak diberi kabar.
 * Satu kabar per transfer dan penerima (kunci unik), jadi tugas yang diulang tidak menggandakannya. Penerima tanpa outlet
 * akses ke tujuan, atau yang membisukan outlet itu, tidak menerimanya (`PenerimaNotifikasi`).
 */
final class BeritahuTransferStokMasukTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $idTenant, public readonly int $idTransfer) {}

    public function handle(KonteksTenant $konteks, PenerimaNotifikasi $penerima, BuatNotifikasiPengguna $buat, InfoGudang $infoGudang): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $transfer = TransferStok::query()->whereKey($this->idTransfer)->first();

            // Hanya transfer yang benar-benar masih dalam perjalanan; yang sudah dibatalkan/diterima tidak perlu dikabarkan.
            if ($transfer === null || $transfer->Status !== StatusTransferStok::Dikirim) {
                return;
            }

            $gudang = $infoGudang->AmbilBanyak([$transfer->IdGudangAsal, $transfer->IdGudangTujuan]);
            $asal = $gudang[$transfer->IdGudangAsal]->nama ?? 'gudang asal';
            $tujuan = $gudang[$transfer->IdGudangTujuan]->nama ?? 'gudang tujuan';

            foreach ($penerima->AmbilIdPengguna($this->idTenant, IzinTenant::PersediaanKelola, $transfer->IdOutletTujuan) as $idPengguna) {
                if ($idPengguna === $transfer->DikirimOleh) {
                    continue;
                }

                $buat->Jalankan(
                    $idPengguna,
                    JenisNotifikasiPengguna::TransferStokMasuk,
                    'TransferStokMasuk:'.$transfer->Uuid,
                    "Kiriman stok menuju {$tujuan}",
                    "{$transfer->Nomor} dari {$asal}, {$transfer->JumlahBaris} jenis barang. Periksa dan terima setelah barang tiba.",
                    ['Tautan' => 'notifikasi', 'Href' => "/kelola/persediaan/transfer/{$transfer->Uuid}"],
                );
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
