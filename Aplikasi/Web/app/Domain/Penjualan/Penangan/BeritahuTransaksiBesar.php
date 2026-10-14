<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Penangan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Peristiwa\PenjualanDiterima;
use App\Domain\Penjualan\Peristiwa\PenjualanDivoid;
use App\Domain\Penjualan\Peristiwa\PeristiwaDokumenPenjualan;
use App\Domain\Penjualan\Peristiwa\ReturPenjualanDiterima;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * OWN-03: kabar ke pengguna berizin `laporan.penjualan.lihat` (dan outlet kejadian dalam cakupannya) saat ada **void**,
 * **retur**, atau **penjualan berdiskon** bernilai sama atau di atas `BatasNotifikasiTransaksi` pengaturan kasir
 * (bawaan Rp 500.000; 0 = setiap kejadian). Penangan non-kritis: antrean, setelah commit (aturan #10). Idempoten per
 * (jenis kejadian, dokumen) lewat kunci notifikasi, sehingga penangan yang diulang tidak menggandakan kabar.
 */
final class BeritahuTransaksiBesar implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly PenerimaNotifikasi $penerima,
        private readonly BuatNotifikasiPengguna $buat,
    ) {}

    public function handle(PeristiwaDokumenPenjualan $peristiwa): void
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($peristiwa->AmbilIdTenant());

        try {
            $batas = $this->pengaturan->Ambil()->batasNotifikasiTransaksi;
            $kabar = match (true) {
                $peristiwa instanceof PenjualanDivoid => $this->KabarVoid($peristiwa, $batas),
                $peristiwa instanceof ReturPenjualanDiterima => $this->KabarRetur($peristiwa, $batas),
                $peristiwa instanceof PenjualanDiterima => $this->KabarDiskon($peristiwa, $batas),
                default => null,
            };

            if ($kabar === null) {
                return;
            }

            foreach ($this->penerima->AmbilIdPengguna($peristiwa->AmbilIdTenant(), IzinTenant::LaporanPenjualanLihat, $peristiwa->AmbilIdOutlet()) as $idPengguna) {
                $this->buat->Jalankan(
                    $idPengguna,
                    JenisNotifikasiPengguna::TransaksiBesar,
                    $kabar['Kunci'],
                    $kabar['Judul'],
                    $kabar['Isi'],
                    ['Tautan' => 'notifikasi', 'Href' => $kabar['Href']],
                );
            }
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }

    /** @return array{Kunci: string, Judul: string, Isi: string, Href: string}|null */
    private function KabarVoid(PenjualanDivoid $peristiwa, Uang $batas): ?array
    {
        $penjualan = Penjualan::query()->whereKey($peristiwa->idDokumen)->first();

        if ($penjualan === null || Uang::Dari($penjualan->TotalAkhir)->Bandingkan($batas) < 0) {
            return null;
        }

        return [
            'Kunci' => 'TransaksiBesar:Void:'.$penjualan->Uuid,
            'Judul' => 'Penjualan di-void '.Uang::Dari($penjualan->TotalAkhir)->FormatRupiah(),
            'Isi' => "{$penjualan->Nomor} di-void. Periksa alasan dan penyetujunya.",
            'Href' => "/kelola/penjualan/{$penjualan->Uuid}",
        ];
    }

    /** @return array{Kunci: string, Judul: string, Isi: string, Href: string}|null */
    private function KabarRetur(ReturPenjualanDiterima $peristiwa, Uang $batas): ?array
    {
        $retur = ReturPenjualan::query()->whereKey($peristiwa->idDokumen)->first();

        if ($retur === null) {
            return null;
        }

        $nilai = Uang::Dari($retur->TotalNilai)->Tambah(Uang::Dari($retur->TotalPajak));

        if ($nilai->Bandingkan($batas) < 0) {
            return null;
        }

        return [
            'Kunci' => 'TransaksiBesar:Retur:'.$retur->Uuid,
            'Judul' => 'Retur penjualan '.$nilai->FormatRupiah(),
            'Isi' => "{$retur->Nomor}".($retur->TanpaStruk ? ' (tanpa struk)' : '').". Alasan: {$retur->Alasan}",
            'Href' => '/kelola/laporan/penjualan?tab=anti-fraud',
        ];
    }

    /** @return array{Kunci: string, Judul: string, Isi: string, Href: string}|null */
    private function KabarDiskon(PenjualanDiterima $peristiwa, Uang $batas): ?array
    {
        $penjualan = Penjualan::query()->whereKey($peristiwa->idDokumen)->first();

        if ($penjualan === null) {
            return null;
        }

        $diskon = Uang::Dari($penjualan->TotalDiskon);

        if ($diskon->Bandingkan(Uang::Nol()) <= 0 || $diskon->Bandingkan($batas) < 0) {
            return null;
        }

        return [
            'Kunci' => 'TransaksiBesar:Diskon:'.$penjualan->Uuid,
            'Judul' => 'Diskon besar '.$diskon->FormatRupiah(),
            'Isi' => "{$penjualan->Nomor} berdiskon {$diskon->FormatRupiah()} dari total {$penjualan->TotalAkhir}.",
            'Href' => "/kelola/penjualan/{$penjualan->Uuid}",
        ];
    }
}
