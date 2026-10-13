<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Penjualan\Data\DataHargaManual;
use App\Domain\Penjualan\Data\DataHasilPemeriksaanDiskon;

/**
 * Harga manual kasir (price engine lapis 1, PRD §F-03): harga satu baris yang diubah kasir dari harga price engine.
 *
 * Aturannya cermin diskon manual (`PemeriksaDiskonPenjualan`) tetapi tanpa batas persen — yang dijaga adalah **siapa**
 * yang boleh menentukan harga: Pemilik, kasir ber-izin `pos.harga.timpa`, atau penyetuju (PIN) ber-izin itu.
 *
 * Penjualan offline sudah terjadi dan uangnya sudah diterima, jadi pelanggaran **tidak menolak**: menjadi alasan
 * tinjauan `HargaManualTanpaIzin` (tanpa izin/persetujuan) atau `HargaManualTanpaAlasan`. Penyetuju yang tidak lagi punya
 * akses ke outlet = `HargaManualIzinBerubah`. Yang tetap ditolak (`PenyetujuTidakBerwenang`) hanya data yang tidak
 * mungkin sah: `UuidPenyetujuHarga` bukan anggota tenant atau tanpa izin `pos.harga.timpa` sama sekali.
 * Baris yang `HargaAsli`-nya sama dengan harga yang dipakai tidak dianggap perubahan.
 */
final class PemeriksaHargaManual
{
    public function __construct(private readonly AnggotaOutlet $anggota) {}

    /**
     * @param  list<array{Nama: string, Harga: Uang, Manual: DataHargaManual}>  $baris  hanya baris yang membawa `HargaManual`
     */
    public function Periksa(array $baris, DataAnggotaOutlet $kasir, ?string $uuidPenyetuju, int $idTenant, int $idOutlet): DataHasilPemeriksaanDiskon
    {
        $tinjauan = [];
        $penyetuju = null;

        if ($uuidPenyetuju !== null) {
            $hasil = $this->anggota->CariDiTenant($idTenant, $uuidPenyetuju, $idOutlet);

            if ($hasil === null || ! $hasil[0]->CekIzin(IzinTenant::PosHargaTimpa->value)) {
                throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Penyetuju harga bukan anggota usaha ini atau tidak punya izin mengubah harga di kasir.', 'UuidPenyetujuHarga', 403);
            }

            [$penyetuju, $aksesOutlet] = $hasil;

            if (! $aksesOutlet) {
                $tinjauan['HargaManualIzinBerubah'] = "HargaManualIzinBerubah: penyetuju harga {$penyetuju->nama} tidak lagi terdaftar di outlet ini";
            }
        }

        $berubah = array_values(array_filter($baris, fn (array $b): bool => ! $b['Manual']->hargaAsli->SamaDengan($b['Harga'])));

        if ($berubah === []) {
            return new DataHasilPemeriksaanDiskon($penyetuju, $tinjauan);
        }

        $alasanKosong = array_values(array_filter($berubah, fn (array $b): bool => trim($b['Manual']->alasan) === ''));

        if ($alasanKosong !== []) {
            $tinjauan['HargaManualTanpaAlasan'] = 'HargaManualTanpaAlasan: harga '.implode(', ', array_map(fn (array $b): string => $b['Nama'], $alasanKosong)).' diubah tanpa alasan';
        }

        $diizinkan = $kasir->pemilik || $kasir->CekIzin(IzinTenant::PosHargaTimpa->value) || $penyetuju !== null;

        if (! $diizinkan) {
            $tinjauan['HargaManualTanpaIzin'] = "HargaManualTanpaIzin: {$kasir->nama} mengubah harga ".implode(', ', array_map(
                fn (array $b): string => "{$b['Nama']} dari {$b['Manual']->hargaAsli->FormatRupiah()} ke {$b['Harga']->FormatRupiah()}",
                $berubah,
            )).' tanpa izin dan tanpa persetujuan supervisor';
        }

        return new DataHasilPemeriksaanDiskon($penyetuju, $tinjauan);
    }
}
