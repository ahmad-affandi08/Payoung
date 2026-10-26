<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Persediaan\Layanan\PembangunUlangSaldoStok;
use App\Domain\Persediaan\Layanan\PemeriksaKonsistensiStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Support\Facades\DB;

/**
 * Alat bantu (ubah): bangun ulang `SaldoStok` dan `BatchStok.JumlahSisa` dari `MutasiStok` (PGL-16 bagian 2, BR-P09.3,
 * aturan emas #9). Ledger tidak disentuh; hanya cache yang menyimpang yang diperbaiki, lewat `PembangunUlangSaldoStok` yang
 * sama dengan `persediaan:bangun-ulang-saldo`. Hasil: jumlah perbedaan dari `PemeriksaKonsistensiStok` sebelum dan sesudah
 * (lapisan FIFO dan status nomor seri hanya dilaporkan, tidak diperbaiki alat ini). Tenant dengan lebih dari
 * `BATAS_PASANGAN` pasangan produk-lokasi ditolak supaya tidak menahan permintaan web; tim teknis memakai perintah konsol.
 */
final class BangunUlangSaldoStokDukungan
{
    public const BATAS_PASANGAN = 5000;

    public const BATAS_CONTOH = 10;

    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly PemeriksaKonsistensiStok $pemeriksa,
        private readonly PembangunUlangSaldoStok $pembangun,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::BangunUlangSaldoStok, [], function (): HasilKerjaAlat {
            $kueri = SaldoStok::query()->select(['IdProduk', 'IdGudang'])->toBase()
                ->union(MutasiStok::query()->select(['IdProduk', 'IdGudang'])->distinct()->toBase());
            $pasangan = DB::query()->fromSub($kueri, 'Pasangan')->count();

            if ($pasangan > self::BATAS_PASANGAN) {
                throw new PelanggaranAturanBisnis('TenantTerlaluBesar', 'Data stok tenant ini terlalu besar untuk dibangun ulang dari konsol. Minta tim teknis menjalankan perintah persediaan:bangun-ulang-saldo.');
            }

            $sebelum = $this->pemeriksa->Periksa();
            $diperbaiki = $this->pembangun->Jalankan();
            $sesudah = $this->pemeriksa->Periksa();

            $keadaanSebelum = ['JumlahPerbedaan' => count($sebelum), 'Contoh' => array_slice($sebelum, 0, self::BATAS_CONTOH)];
            $keadaanSesudah = ['JumlahPerbedaan' => count($sesudah), 'JumlahPasanganDiperbaiki' => $diperbaiki, 'Contoh' => array_slice($sesudah, 0, self::BATAS_CONTOH)];

            if ($diperbaiki === 0) {
                return new HasilKerjaAlat(StatusHasilAlatDukungan::TanpaPerubahan, 'Saldo stok sudah sama dengan buku stok. Tidak ada yang diperbaiki.', $keadaanSebelum, $keadaanSesudah);
            }

            $sisa = count($sesudah) === 0 ? 'Tidak ada perbedaan tersisa.' : count($sesudah).' perbedaan tersisa (lapisan FIFO atau nomor seri, perlu ditinjau tim teknis).';

            return new HasilKerjaAlat(StatusHasilAlatDukungan::Berhasil, "{$diperbaiki} pasangan produk-lokasi diperbaiki. {$sisa}", $keadaanSebelum, $keadaanSesudah);
        });
    }
}
