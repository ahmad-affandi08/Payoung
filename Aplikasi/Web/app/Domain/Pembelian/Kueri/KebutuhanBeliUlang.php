<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\ProdukUntukLaporan;
use App\Domain\Katalog\Kueri\SatuanProdukPembelian;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use App\Domain\Persediaan\Kueri\StokUntukLaporan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * D-23 D: barang yang perlu dibeli ulang per (lokasi stok, pemasok). Kritis = saldo ≤ stok minimum. Jumlah dipesan
 * sampai stok maksimum (bila kosong: 2 × minimum), dikurangi saldo dan sisa PO terbuka (Draf s.d. Diterima sebagian)
 * sehingga menjalankan ulang tidak menggandakan pesanan. Pemasok, satuan, dan harga dari pembelian terakhir produk itu
 * (penerimaan barang yang diposting, lalu PO yang tidak dibatalkan). Produk tanpa riwayat pembelian atau pemasoknya
 * nonaktif dilaporkan terpisah (tidak bisa dibuatkan draf).
 */
final class KebutuhanBeliUlang
{
    /** F24: panjang periode dasar laju pemakaian untuk draf PO berdasarkan perkiraan. */
    public const HARI_DASAR_PERKIRAAN = 28;

    /** Status PO yang dianggap masih akan mendatangkan barang. */
    private const STATUS_TERBUKA = [
        StatusPesananPembelian::Draf,
        StatusPesananPembelian::MenungguPersetujuan,
        StatusPesananPembelian::Disetujui,
        StatusPesananPembelian::DiterimaSebagian,
    ];

    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly ProdukUntukLaporan $produk,
        private readonly StokUntukLaporan $stok,
        private readonly InfoProdukStok $infoProduk,
        private readonly SatuanProdukPembelian $satuan,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua lokasi stok
     * @param  string  $sumber  `Minimum` (bawaan, di bawah stok minimum) atau `Perkiraan` (laju pemakaian 28 hari untuk `$hariCakupan` hari, F24)
     * @return array{Kelompok: list<array{IdGudang: int, UuidPemasok: string, Baris: list<array{UuidProduk: string, UuidProdukSatuan: string|null, Jumlah: string, Harga: string}>}>, TanpaPemasok: list<string>}
     */
    public function Ambil(?array $idOutletBoleh = null, string $sumber = 'Minimum', int $hariCakupan = 14, ?CarbonImmutable $hariIni = null): array
    {
        $gudang = [];

        foreach ($this->infoGudang->AmbilBoleh($idOutletBoleh) as $g) {
            $gudang[$g->id] = $g;
        }

        $kebutuhan = $sumber === 'Perkiraan'
            ? $this->KebutuhanPerkiraan(array_keys($gudang), $hariCakupan, $hariIni ?? CarbonImmutable::now())
            : $this->KebutuhanMinimum(array_keys($gudang));

        if ($kebutuhan === []) {
            return ['Kelompok' => [], 'TanpaPemasok' => []];
        }

        $idKebutuhan = array_values(array_unique(array_column($kebutuhan, 'IdProduk')));
        $terakhir = $this->AmbilPembelianTerakhir($idKebutuhan);
        $info = $this->infoProduk->AmbilBanyak($idKebutuhan);
        $satuan = $this->satuan->AmbilUntukProduk($idKebutuhan);
        $pemasok = Pemasok::query()->whereIn('Id', array_values(array_unique(array_column($terakhir, 'IdPemasok'))))->where('Aktif', true)->pluck('Uuid', 'Id');
        $kelompok = [];
        $tanpa = [];

        foreach ($kebutuhan as $k) {
            $produk = $info[$k['IdProduk']] ?? null;
            $beli = $terakhir[$k['IdProduk']] ?? null;
            $uuidPemasok = $beli === null ? null : $pemasok->get($beli['IdPemasok']);

            if ($produk === null) {
                continue;
            }

            if ($beli === null || ! is_string($uuidPemasok)) {
                $tanpa[] = $produk->nama;

                continue;
            }

            $baris = $this->SusunBaris($produk->uuid, $produk->bolehDesimal, $k['Kurang'], $beli, $satuan[$k['IdProduk']] ?? []);
            $kunci = "{$k['IdGudang']}|{$uuidPemasok}";
            $kelompok[$kunci] ??= ['IdGudang' => $k['IdGudang'], 'UuidPemasok' => $uuidPemasok, 'Baris' => []];
            $kelompok[$kunci]['Baris'][] = $baris;
        }

        return ['Kelompok' => array_values($kelompok), 'TanpaPemasok' => array_values(array_unique($tanpa))];
    }

    /**
     * Barang di bawah stok minimum: sampai stok maksimum (bila kosong: 2 × minimum) dikurangi saldo dan sisa PO terbuka.
     *
     * @param  list<int>  $idGudang
     * @return list<array{IdProduk: int, IdGudang: int, Kurang: Kuantitas}>
     */
    private function KebutuhanMinimum(array $idGudang): array
    {
        $batas = $this->produk->AmbilBatasStok($idGudang);
        $idProduk = array_values(array_unique(array_column($batas, 'IdProduk')));
        $saldo = $this->stok->AmbilSaldo($idGudang, $idProduk);
        $dipesan = $this->AmbilSisaPoTerbuka($idGudang, $idProduk);
        $kebutuhan = [];

        foreach ($batas as $b) {
            $kunci = "{$b['IdProduk']}|{$b['IdGudang']}";
            $jumlah = Kuantitas::Dari($saldo[$kunci]['Jumlah'] ?? '0');
            $minimum = Kuantitas::Dari($b['StokMinimum']);

            if ($jumlah->Bandingkan($minimum) > 0) {
                continue;
            }

            $target = $b['StokMaksimum'] !== null && Kuantitas::Dari($b['StokMaksimum'])->Bandingkan($minimum) > 0
                ? Kuantitas::Dari($b['StokMaksimum'])
                : $minimum->Kali(2);
            $kurang = $target->Kurangi($jumlah)->Kurangi($dipesan[$kunci] ?? Kuantitas::Nol());

            if ($kurang->Bandingkan(Kuantitas::Nol()) > 0) {
                $kebutuhan[] = ['IdProduk' => $b['IdProduk'], 'IdGudang' => $b['IdGudang'], 'Kurang' => $kurang];
            }
        }

        return $kebutuhan;
    }

    /**
     * F24 (INV-13): kebutuhan dari laju pemakaian `HARI_DASAR_PERKIRAAN` hari terakhir (sampai kemarin; penjualan, bahan
     * resep, produksi, susut, dikurangi retur) × `$hariCakupan` hari, dikurangi saldo dan sisa PO terbuka. Tanpa faktor
     * musim (saran restock di laporan stok memakainya; draf tetap diperiksa orang sebelum diajukan). Konsinyasi tidak ikut.
     *
     * @param  list<int>  $idGudang
     * @return list<array{IdProduk: int, IdGudang: int, Kurang: Kuantitas}>
     */
    private function KebutuhanPerkiraan(array $idGudang, int $hariCakupan, CarbonImmutable $hariIni): array
    {
        $sampai = $hariIni->startOfDay()->subDay();
        $pakai = $this->stok->AmbilPemakaian($idGudang, $sampai->subDays(self::HARI_DASAR_PERKIRAAN - 1), $sampai);

        if ($pakai === []) {
            return [];
        }

        $idProduk = array_values(array_unique(array_column($pakai, 'IdProduk')));
        $saldo = $this->stok->AmbilSaldo($idGudang, $idProduk);
        $dipesan = $this->AmbilSisaPoTerbuka($idGudang, $idProduk);
        $info = $this->infoProduk->AmbilBanyak($idProduk);
        $kebutuhan = [];

        foreach ($pakai as $b) {
            $produk = $info[$b['IdProduk']] ?? null;

            if ($produk === null || $produk->jenis === JenisProduk::Konsinyasi) {
                continue;
            }

            $kunci = "{$b['IdProduk']}|{$b['IdGudang']}";
            $rata = BigDecimal::of($b['Pakai'])->dividedBy(self::HARI_DASAR_PERKIRAAN, 6, RoundingMode::HalfUp);
            $kurang = $rata->multipliedBy($hariCakupan)
                ->minus($saldo[$kunci]['Jumlah'] ?? '0')
                ->minus(($dipesan[$kunci] ?? Kuantitas::Nol())->KeString());

            if ($kurang->isPositive()) {
                $kebutuhan[] = ['IdProduk' => $b['IdProduk'], 'IdGudang' => $b['IdGudang'], 'Kurang' => Kuantitas::Dari($kurang->toScale(Kuantitas::SKALA, RoundingMode::Up))];
            }
        }

        return $kebutuhan;
    }

    /**
     * Jumlah dalam satuan pembelian terakhir: dibulatkan ke atas untuk satuan isi > 1 atau produk tanpa pecahan.
     *
     * @param  array{IdPemasok: int, IdProdukSatuan: int|null, Konversi: string, Harga: string}  $beli
     * @param  list<array{Id: int, Uuid: string, Konversi: string}>  $satuanProduk
     * @return array{UuidProduk: string, UuidProdukSatuan: string|null, Jumlah: string, Harga: string}
     */
    private function SusunBaris(string $uuidProduk, bool $bolehDesimal, Kuantitas $kurang, array $beli, array $satuanProduk): array
    {
        $satuanBeli = null;

        foreach ($satuanProduk as $s) {
            if ($s['Id'] === $beli['IdProdukSatuan']) {
                $satuanBeli = $s;
            }
        }

        $konversi = BigDecimal::of($satuanBeli['Konversi'] ?? '1');

        if ($satuanBeli === null || $konversi->isLessThanOrEqualTo(0)) {
            $konversi = BigDecimal::one();
        }

        $jumlah = $kurang->KeDesimal()->dividedBy($konversi, Kuantitas::SKALA, RoundingMode::Up);

        if (! $bolehDesimal || $konversi->isGreaterThan(1)) {
            $jumlah = $jumlah->toScale(0, RoundingMode::Up);
        }

        return [
            'UuidProduk' => $uuidProduk,
            'UuidProdukSatuan' => $satuanBeli['Uuid'] ?? null,
            'Jumlah' => Kuantitas::Dari($jumlah)->KeString(),
            'Harga' => $satuanBeli === null
                ? (string) BigDecimal::of($beli['Harga'])->dividedBy(BigDecimal::of($beli['Konversi'] === '' ? '1' : $beli['Konversi']), 2, RoundingMode::HalfUp)
                : $beli['Harga'],
        ];
    }

    /**
     * Sisa jumlah (satuan dasar) PO terbuka per "IdProduk|IdGudang".
     *
     * @param  list<int>  $idGudang
     * @param  list<int>  $idProduk
     * @return array<string, Kuantitas>
     */
    private function AmbilSisaPoTerbuka(array $idGudang, array $idProduk): array
    {
        if ($idGudang === [] || $idProduk === []) {
            return [];
        }

        $hasil = [];
        $baris = PesananPembelianDetail::query()
            ->join('PesananPembelian', 'PesananPembelian.Id', '=', 'PesananPembelianDetail.IdPesananPembelian')
            ->whereIn('PesananPembelian.IdGudang', $idGudang)
            ->whereIn('PesananPembelian.Status', array_map(fn (StatusPesananPembelian $s): string => $s->value, self::STATUS_TERBUKA))
            ->whereIn('PesananPembelianDetail.IdProduk', $idProduk)
            ->get(['PesananPembelianDetail.IdProduk', 'PesananPembelian.IdGudang', 'PesananPembelianDetail.Jumlah', 'PesananPembelianDetail.JumlahDiterima', 'PesananPembelianDetail.Konversi']);

        foreach ($baris as $b) {
            $sisa = BigDecimal::of((string) $b->Jumlah)->minus((string) $b->JumlahDiterima);

            if ($sisa->isLessThanOrEqualTo(0)) {
                continue;
            }

            $kunci = "{$b->IdProduk}|{$b->getAttribute('IdGudang')}";
            $dasar = Kuantitas::Dari($sisa->multipliedBy((string) $b->Konversi)->toScale(Kuantitas::SKALA, RoundingMode::Up));
            $hasil[$kunci] = ($hasil[$kunci] ?? Kuantitas::Nol())->Tambah($dasar);
        }

        return $hasil;
    }

    /**
     * Pembelian terakhir per produk: penerimaan barang diposting (berpemasok), lalu PO yang tidak dibatalkan.
     *
     * @param  list<int>  $idProduk
     * @return array<int, array{IdPemasok: int, IdProdukSatuan: int|null, Konversi: string, Harga: string}>
     */
    private function AmbilPembelianTerakhir(array $idProduk): array
    {
        $hasil = [];
        $terima = PenerimaanBarangDetail::query()
            ->join('PenerimaanBarang', 'PenerimaanBarang.Id', '=', 'PenerimaanBarangDetail.IdPenerimaanBarang')
            ->where('PenerimaanBarang.Status', StatusDokumenTerposting::Diposting->value)
            ->whereNotNull('PenerimaanBarang.IdPemasok')
            ->whereIn('PenerimaanBarangDetail.IdProduk', $idProduk)
            ->orderByDesc('PenerimaanBarang.Tanggal')
            ->orderByDesc('PenerimaanBarangDetail.Id')
            ->get(['PenerimaanBarangDetail.IdProduk', 'PenerimaanBarang.IdPemasok', 'PenerimaanBarangDetail.IdProdukSatuan', 'PenerimaanBarangDetail.Konversi', 'PenerimaanBarangDetail.Harga']);

        foreach ($terima as $t) {
            $hasil[$t->IdProduk] ??= [
                'IdPemasok' => (int) $t->getAttribute('IdPemasok'),
                'IdProdukSatuan' => $t->IdProdukSatuan,
                'Konversi' => (string) $t->Konversi,
                'Harga' => (string) $t->Harga,
            ];
        }

        $sisa = array_values(array_diff($idProduk, array_keys($hasil)));

        if ($sisa !== []) {
            $po = PesananPembelianDetail::query()
                ->join('PesananPembelian', 'PesananPembelian.Id', '=', 'PesananPembelianDetail.IdPesananPembelian')
                ->where('PesananPembelian.Status', '!=', StatusPesananPembelian::Dibatalkan->value)
                ->whereIn('PesananPembelianDetail.IdProduk', $sisa)
                ->orderByDesc('PesananPembelian.Tanggal')
                ->orderByDesc('PesananPembelianDetail.Id')
                ->get(['PesananPembelianDetail.IdProduk', 'PesananPembelian.IdPemasok', 'PesananPembelianDetail.IdProdukSatuan', 'PesananPembelianDetail.Konversi', 'PesananPembelianDetail.Harga']);

            foreach ($po as $p) {
                $hasil[$p->IdProduk] ??= [
                    'IdPemasok' => (int) $p->getAttribute('IdPemasok'),
                    'IdProdukSatuan' => $p->IdProdukSatuan,
                    'Konversi' => (string) $p->Konversi,
                    'Harga' => (string) $p->Harga,
                ];
            }
        }

        return $hasil;
    }
}
