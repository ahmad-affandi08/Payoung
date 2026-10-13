<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Kueri\PolaKasKasir;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pemenuhan\Kueri\WaktuSajiDapur;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Kueri\StokUntukLaporan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Tab operasional laporan penjualan (§10.10): **waktu saji KDS** per stasiun, **food cost teoretis vs aktual** per
 * bahan, dan **performa kasir**. Saring periode/outlet sama dengan tab lain di `/kelola/laporan/penjualan`; angka dari
 * kueri publik domain Pemenuhan, Persediaan, Kasir, dan Penjualan.
 */
final class LaporanOperasional
{
    public const TARGET_SAJI_BAWAAN = 15;

    public const TARGET_SAJI_OPSI = [5, 10, 15, 20, 30, 45, 60];

    public function __construct(
        private readonly WaktuSajiDapur $waktuSaji,
        private readonly DaftarStasiunDapur $stasiun,
        private readonly ZonaWaktuOutlet $zonaWaktu,
        private readonly StokUntukLaporan $stok,
        private readonly LaporanStok $laporanStok,
        private readonly InfoProdukStok $infoProduk,
        private readonly AgregatPenjualan $agregat,
        private readonly PolaKasKasir $polaKas,
        private readonly AnggotaOutlet $anggota,
    ) {}

    /**
     * Waktu saji per stasiun dapur (detik); urut jumlah tiket terbanyak.
     *
     * @return list<array<string, mixed>>
     */
    public function WaktuSaji(DataSaringLaporanPenjualan $saring, int $targetMenit = self::TARGET_SAJI_BAWAAN): array
    {
        $baris = $this->waktuSaji->PerStasiun($saring->dari->toDateString(), $saring->sampai->toDateString(), $saring->idOutlet, $this->zonaWaktu->AmbilSelisihDetik($saring->idOutlet), $targetMenit);
        $peta = $this->stasiun->AmbilPetaId();
        $hasil = array_map(fn (array $b): array => [
            'Kunci' => $peta[$b['IdStasiun']]['Uuid'] ?? (string) $b['IdStasiun'],
            'NamaStasiun' => $peta[$b['IdStasiun']]['Nama'] ?? 'Stasiun tidak dikenal',
            'JumlahTiket' => $b['JumlahTiket'],
            'TungguRataRata' => $b['TungguRataRata'],
            'MasakRataRata' => $b['MasakRataRata'],
            'TotalRataRata' => $b['TotalRataRata'],
            'TotalMedian' => $b['TotalMedian'],
            'TotalP90' => $b['TotalP90'],
            'TotalTerlama' => $b['TotalTerlama'],
            'SajikanRataRata' => $b['SajikanRataRata'],
            'LewatTarget' => $b['LewatTarget'],
            'PersenLewatTarget' => $b['JumlahTiket'] === 0 ? '0.00' : (string) BigDecimal::of($b['LewatTarget'] * 100)->dividedBy($b['JumlahTiket'], 2, RoundingMode::HalfUp),
        ], $baris);
        usort($hasil, fn (array $a, array $b): int => $b['JumlahTiket'] <=> $a['JumlahTiket'] ?: strcmp($a['NamaStasiun'], $b['NamaStasiun']));

        return $hasil;
    }

    /**
     * Food cost teoretis vs aktual. Teoretis = nilai bahan yang seharusnya terpakai menurut penjualan (resep × jumlah) dan
     * produksi, dikurangi retur penjualan. Aktual = teoretis + bahan terbuang (susut) + selisih stok (penyesuaian & opname,
     * bersih). Persentase terhadap penjualan bersih periode; baris per produk urut selisih terbesar. Konsinyasi tidak ikut.
     *
     * @return array{Ringkasan: array<string, string>, Baris: list<array<string, mixed>>}
     */
    public function FoodCost(DataSaringLaporanPenjualan $saring): array
    {
        $nol = ['Penjualan' => '0.00', 'Teoretis' => '0.00', 'Terbuang' => '0.00', 'Selisih' => '0.00', 'Aktual' => '0.00', 'TeoretisPersen' => '0.00', 'AktualPersen' => '0.00', 'SelisihPoin' => '0.00'];

        if ($saring->CekTanpaOutlet()) {
            return ['Ringkasan' => $nol, 'Baris' => []];
        }

        $gudang = $this->laporanStok->AmbilGudang($saring->idOutlet);
        $mutasi = $this->stok->AmbilPemakaianPerJenis(array_keys($gudang), $saring->dari, $saring->sampai);
        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($mutasi, 'IdProduk'))));
        $peran = [
            JenisMutasi::Penjualan->value => 'Teoretis', JenisMutasi::ReturPenjualan->value => 'Teoretis', JenisMutasi::ProduksiPakai->value => 'Teoretis',
            JenisMutasi::Susut->value => 'Terbuang',
            JenisMutasi::PenyesuaianMasuk->value => 'Selisih', JenisMutasi::PenyesuaianKeluar->value => 'Selisih', JenisMutasi::OpnameLebih->value => 'Selisih', JenisMutasi::OpnameKurang->value => 'Selisih',
        ];
        $perProduk = [];

        foreach ($mutasi as $m) {
            $p = $info[$m['IdProduk']] ?? null;

            if ($p === null || $p->jenis === JenisProduk::Konsinyasi) {
                continue;
            }

            $kolom = $peran[$m['JenisMutasi']] ?? null;

            if ($kolom === null) {
                continue;
            }

            // Mutasi keluar bertanda negatif; kerugian dilaporkan positif.
            $perProduk[$m['IdProduk']] ??= ['Info' => $p, 'Teoretis' => [BigDecimal::zero(), BigDecimal::zero()], 'Terbuang' => [BigDecimal::zero(), BigDecimal::zero()], 'Selisih' => [BigDecimal::zero(), BigDecimal::zero()]];
            $perProduk[$m['IdProduk']][$kolom][0] = $perProduk[$m['IdProduk']][$kolom][0]->minus($m['Jumlah']);
            $perProduk[$m['IdProduk']][$kolom][1] = $perProduk[$m['IdProduk']][$kolom][1]->minus($m['Nilai']);
        }

        $baris = [];
        $total = ['Teoretis' => BigDecimal::zero(), 'Terbuang' => BigDecimal::zero(), 'Selisih' => BigDecimal::zero()];

        foreach ($perProduk as $d) {
            $aktual = $d['Teoretis'][1]->plus($d['Terbuang'][1])->plus($d['Selisih'][1]);

            foreach (['Teoretis', 'Terbuang', 'Selisih'] as $k) {
                $total[$k] = $total[$k]->plus($d[$k][1]);
            }

            $baris[] = [
                'Kunci' => $d['Info']->uuid,
                'NamaProduk' => $d['Info']->nama,
                'SimbolSatuan' => $d['Info']->simbolSatuan,
                'QtyTeoretis' => self::Qty($d['Teoretis'][0]),
                'NilaiTeoretis' => self::Rp($d['Teoretis'][1]),
                'QtyTerbuang' => self::Qty($d['Terbuang'][0]),
                'NilaiTerbuang' => self::Rp($d['Terbuang'][1]),
                'QtySelisih' => self::Qty($d['Selisih'][0]),
                'NilaiSelisih' => self::Rp($d['Selisih'][1]),
                'NilaiAktual' => self::Rp($aktual),
                'VarianPersen' => $d['Teoretis'][1]->isZero() ? null : (string) $aktual->minus($d['Teoretis'][1])->multipliedBy(100)->dividedBy($d['Teoretis'][1], 2, RoundingMode::HalfUp),
            ];
        }

        usort($baris, fn (array $a, array $b): int => BigDecimal::of($b['NilaiTerbuang'])->plus($b['NilaiSelisih'])->compareTo(BigDecimal::of($a['NilaiTerbuang'])->plus($a['NilaiSelisih'])) ?: strcmp($a['NamaProduk'], $b['NamaProduk']));

        $penjualan = BigDecimal::of($this->agregat->Total($saring)->Bersih()->KeString());
        $aktualTotal = $total['Teoretis']->plus($total['Terbuang'])->plus($total['Selisih']);
        $persen = static fn (BigDecimal $nilai): string => $penjualan->isPositive() ? (string) $nilai->multipliedBy(100)->dividedBy($penjualan, 2, RoundingMode::HalfUp) : '0.00';

        return [
            'Ringkasan' => [
                'Penjualan' => self::Rp($penjualan),
                'Teoretis' => self::Rp($total['Teoretis']),
                'Terbuang' => self::Rp($total['Terbuang']),
                'Selisih' => self::Rp($total['Selisih']),
                'Aktual' => self::Rp($aktualTotal),
                'TeoretisPersen' => $persen($total['Teoretis']),
                'AktualPersen' => $persen($aktualTotal),
                'SelisihPoin' => (string) BigDecimal::of($persen($aktualTotal))->minus($persen($total['Teoretis']))->toScale(2),
            ],
            'Baris' => $baris,
        ];
    }

    /**
     * Performa kasir: transaksi, omzet, rata-rata keranjang, jam kerja (shift tutup, tanpa shift bersama), transaksi dan
     * omzet per jam kerja, void, dan selisih kas kurang. Urut omzet bersih.
     *
     * @return list<array<string, mixed>>
     */
    public function PerformaKasir(DataSaringLaporanPenjualan $saring): array
    {
        if ($saring->CekTanpaOutlet()) {
            return [];
        }

        $perKasir = [];

        foreach ($this->agregat->Agregasi($saring, ['Kasir']) as $b) {
            $perKasir[(int) $b['Kunci'][0]] = $b['Agregat'];
        }

        $pola = $this->agregat->PolaVoidReturPerKasir($saring);
        $kas = $this->polaKas->PerKasir($saring->dari, $saring->sampai, $saring->idOutlet, $saring->idKasir);
        $detik = $this->polaKas->DetikKerjaPerKasir($saring->dari, $saring->sampai, $saring->idOutlet, $saring->idKasir);
        $nama = $this->anggota->AmbilNama(array_keys($perKasir));
        $hasil = [];

        foreach ($perKasir as $id => $a) {
            $jam = BigDecimal::of($detik[$id] ?? 0)->dividedBy(3600, 4, RoundingMode::HalfUp);
            $transaksi = $a->jumlahTransaksi;
            $bersih = BigDecimal::of($a->Bersih()->KeString());
            $void = (int) ($pola[$id]['JumlahVoid'] ?? 0);
            $dasarVoid = (int) ($pola[$id]['JumlahTransaksi'] ?? $transaksi);
            $hasil[] = [
                'Kunci' => $nama[$id]['Uuid'] ?? (string) $id,
                'NamaKasir' => $nama[$id]['Nama'] ?? 'Pengguna tidak dikenal',
                'JumlahTransaksi' => $transaksi,
                'Bersih' => $a->Bersih()->KeString(),
                'RataRataKeranjang' => $a->RataRataKeranjang()->KeString(),
                'JamKerja' => (string) $jam->toScale(2, RoundingMode::HalfUp),
                'ShiftDitutup' => (int) ($kas[$id]['ShiftDitutup'] ?? 0),
                'TransaksiPerJam' => $jam->isZero() ? null : (string) BigDecimal::of($transaksi)->dividedBy($jam, 2, RoundingMode::HalfUp),
                'OmzetPerJam' => $jam->isZero() ? null : Uang::Dari((string) $bersih->dividedBy($jam, 2, RoundingMode::HalfUp))->KeString(),
                'JumlahVoid' => $void,
                'PersenVoid' => $dasarVoid === 0 ? '0.00' : (string) BigDecimal::of($void * 100)->dividedBy($dasarVoid, 2, RoundingMode::HalfUp),
                'SelisihKurang' => (string) ($kas[$id]['SelisihKurang'] ?? '0.00'),
            ];
        }

        usort($hasil, fn (array $a, array $b): int => BigDecimal::of($b['Bersih'])->compareTo($a['Bersih']) ?: strcmp($a['NamaKasir'], $b['NamaKasir']));

        return $hasil;
    }

    private static function Rp(BigDecimal $nilai): string
    {
        return (string) $nilai->toScale(2, RoundingMode::HalfUp);
    }

    private static function Qty(BigDecimal $nilai): string
    {
        return (string) $nilai->toScale(4, RoundingMode::HalfUp);
    }
}
