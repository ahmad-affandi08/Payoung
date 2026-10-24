<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Tugas;

use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Layanan\PengabarStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * OWN-03 / PLT-08, D-187: kabar permintaan stok antar-outlet.
 *
 * - `Diajukan`: manajer outlet **asal** (yang diminta mengirim) dikabari ada permintaan baru, kecuali pembuatnya.
 * - `Dibatalkan`: manajer outlet asal dikabari permintaannya dibatalkan peminta, supaya tidak menyiapkan barang.
 * - `Ditolak` / `Ditutup`: manajer outlet **tujuan** dan pembuat permintaan dikabari beserta alasannya, kecuali pelakunya.
 *
 * Dijalankan hanya bila status permintaan sesuai kejadian; permintaan dari/ke outlet kanvas tidak dikabari.
 */
final class BeritahuPermintaanStokTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPermintaan,
        public readonly StatusPermintaanStok $kejadian,
    ) {}

    public function handle(PengabarStok $pengabar, InfoGudang $infoGudang): void
    {
        $pengabar->DalamTenant($this->idTenant, function () use ($pengabar, $infoGudang): void {
            $p = PermintaanStok::query()->whereKey($this->idPermintaan)->first();

            if ($p === null || $p->Status !== $this->kejadian || $pengabar->CekMelibatkanKanvas($p->IdOutletAsal, $p->IdOutletTujuan)) {
                return;
            }

            $gudang = $infoGudang->AmbilBanyak([$p->IdGudangAsal, $p->IdGudangTujuan]);
            $asal = $gudang[$p->IdGudangAsal]->nama ?? 'gudang asal';
            $tujuan = $gudang[$p->IdGudangTujuan]->nama ?? 'gudang tujuan';
            $href = "/kelola/persediaan/permintaan/{$p->Uuid}";
            $kunci = "PermintaanStok:{$p->Uuid}:{$this->kejadian->value}";
            $alasan = $p->Alasan === null ? '' : " Alasan: {$p->Alasan}";

            match ($this->kejadian) {
                StatusPermintaanStok::Diajukan => $pengabar->Kabari(
                    $this->idTenant, $p->IdOutletAsal, [], $p->DibuatOleh, JenisNotifikasiPengguna::PermintaanStok, $kunci,
                    "Permintaan stok dari {$tujuan}",
                    "{$p->Nomor} meminta {$p->JumlahBaris} jenis barang dari {$asal}. Siapkan dan kirim lewat transfer stok.",
                    $href,
                ),
                StatusPermintaanStok::Dibatalkan => $pengabar->Kabari(
                    $this->idTenant, $p->IdOutletAsal, [], $p->DitutupOleh, JenisNotifikasiPengguna::PermintaanStok, $kunci,
                    "Permintaan stok dari {$tujuan} dibatalkan",
                    "{$p->Nomor} dibatalkan peminta, tidak perlu disiapkan.{$alasan}",
                    $href,
                ),
                StatusPermintaanStok::Ditolak => $pengabar->Kabari(
                    $this->idTenant, $p->IdOutletTujuan, [$p->DibuatOleh], $p->DitutupOleh, JenisNotifikasiPengguna::PermintaanStok, $kunci,
                    "Permintaan stok ke {$asal} ditolak",
                    "{$p->Nomor} ditolak pengirim.{$alasan}",
                    $href,
                ),
                StatusPermintaanStok::Ditutup => $pengabar->Kabari(
                    $this->idTenant, $p->IdOutletTujuan, [$p->DibuatOleh], $p->DitutupOleh, JenisNotifikasiPengguna::PermintaanStok, $kunci,
                    "Permintaan stok ke {$asal} ditutup",
                    "Sisa barang di {$p->Nomor} tidak akan dikirim.{$alasan}",
                    $href,
                ),
                default => null,
            };
        });
    }
}
