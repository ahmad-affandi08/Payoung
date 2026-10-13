<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Kasir\Kueri\PolaKasKasir;
use App\Domain\Katalog\Kueri\ProdukUntukLaporan;
use App\Domain\Laporan\Data\DataPeriodeLaporan;
use App\Domain\Laporan\Layanan\PencariPasanganProduk;
use App\Domain\Laporan\Layanan\PenilaiInsightProduk;
use App\Domain\Laporan\Layanan\PenilaiRisikoKasir;
use App\Domain\Laporan\Layanan\PeramalPenjualan;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Data\DataAgregatPenjualan;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan penjualan back-office F-14a (`/kelola/laporan/penjualan`, izin `laporan.penjualan.lihat`, dibatasi outlet
 * akses). Saring halaman: `tab`, `dari`/`sampai` (maks. 92 hari), `outlet` (Uuid), `kasir` (Uuid), `kanal`. Tab:
 * ringkasan harian (dari `RingkasanPenjualanHarian`; bila menyaring kasir/kanal dihitung langsung dari dokumen),
 * per produk (`TabelData` mode server), per kategori, per jam (heatmap hari × jam lokal outlet), per kasir, per kanal,
 * per metode bayar, diskon per kasir, dan anti-fraud per kasir (F-14/OWN-09: void, void tunai cepat, retur, diskon, buka
 * laci manual, selisih kas, skor risiko `PenilaiRisikoKasir`), serta insight X6 (v3.43): analisis ABC (Pareto omzet
 * 80/95%) dan menu engineering (Star/Plowhorse/Puzzle/Dog, `PenilaiInsightProduk`). Angka dari kueri publik domain Penjualan
 * (`AgregatPenjualan`) dan Kasir (`PolaKasKasir`).
 */
final class LaporanPenjualan
{
    /** Tampilan tabel lokal ≤ 200 baris (D-16); ekspor memakai batas lebih besar. */
    public const BATAS_VOID_ITEM = 200;

    public const BATAS_EKSPOR_VOID_ITEM = 5000;

    public const BATAS_PELANGGAN = 200;

    public const BATAS_EKSPOR_PELANGGAN = 5000;

    public const BATAS_BUNDLING = 50;

    public const TAB = ['harian', 'detail', 'produk', 'kategori', 'jam', 'kasir', 'kanal', 'metode', 'diskon', 'void-item', 'anti-fraud', 'abc', 'menu', 'pelanggan', 'outlet', 'waktu-saji', 'food-cost', 'performa-kasir', 'prediksi', 'bundling'];

    public function __construct(
        private readonly AgregatPenjualan $agregat,
        private readonly PetaUuidOutlet $outlet,
        private readonly AnggotaOutlet $anggota,
        private readonly ProdukUntukLaporan $produk,
        private readonly ZonaWaktuOutlet $zonaWaktu,
        private readonly PolaKasKasir $polaKas,
        private readonly PengaturanKasirTenant $pengaturanKasir,
        private readonly PenilaiRisikoKasir $penilai,
        private readonly PenilaiInsightProduk $insight,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly LaporanOperasional $operasional,
        private readonly PeramalPenjualan $peramal,
        private readonly PencariPasanganProduk $pasangan,
    ) {}

    /**
     * Membaca saring halaman. Outlet di luar akses atau Uuid tidak dikenal = tanpa outlet (laporan kosong).
     *
     * @param  array<mixed>  $query
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, UuidKasir: string, Kanal: string, Saring: DataSaringLaporanPenjualan}
     */
    public function BacaSaring(array $query, ?array $idOutletBoleh, CarbonImmutable $hariIni): array
    {
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], self::TAB, true) ? $query['tab'] : 'harian';
        $periode = DataPeriodeLaporan::Baca($query['dari'] ?? null, $query['sampai'] ?? null, $hariIni);
        $uuidOutlet = is_string($query['outlet'] ?? null) ? $query['outlet'] : '';
        $idOutlet = $idOutletBoleh;

        if ($uuidOutlet !== '') {
            $id = $this->outlet->AmbilIdDariUuid([$uuidOutlet])[$uuidOutlet] ?? null;
            $idOutlet = $id !== null && ($idOutletBoleh === null || in_array($id, $idOutletBoleh, true)) ? [$id] : [];
        }

        $uuidKasir = is_string($query['kasir'] ?? null) ? $query['kasir'] : '';
        $idKasir = null;

        if ($uuidKasir !== '') {
            $idKasir = 0;

            foreach ($this->AmbilOpsiKasir($idOutletBoleh) as $opsi) {
                if ($opsi['Nilai'] === $uuidKasir) {
                    $idKasir = $opsi['Id'];
                }
            }
        }

        $kanal = KanalPenjualan::tryFrom(is_string($query['kanal'] ?? null) ? $query['kanal'] : '');
        $target = (int) (is_string($query['target'] ?? null) ? $query['target'] : 0);
        $target = in_array($target, LaporanOperasional::TARGET_SAJI_OPSI, true) ? $target : LaporanOperasional::TARGET_SAJI_BAWAAN;

        return [
            'Tab' => $tab,
            'Periode' => $periode,
            'UuidOutlet' => $uuidOutlet,
            'UuidKasir' => $uuidKasir,
            'Kanal' => $kanal->value ?? '',
            'Saring' => new DataSaringLaporanPenjualan($periode->dari, $periode->sampai, $idOutlet, $idKasir, $kanal, $target, $hariIni->startOfDay()),
        ];
    }

    /**
     * Props halaman Inertia `Kelola/Laporan/Penjualan`.
     *
     * @param  array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, UuidKasir: string, Kanal: string, Saring: DataSaringLaporanPenjualan}  $saring
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>
     */
    public function AmbilHalaman(array $saring, ?array $idOutletBoleh, DataPermintaanTabel $tabel): array
    {
        $s = $saring['Saring'];

        return [
            'Saring' => [
                'Tab' => $saring['Tab'],
                'Dari' => $saring['Periode']->dari->toDateString(),
                'Sampai' => $saring['Periode']->sampai->toDateString(),
                'Outlet' => $saring['UuidOutlet'],
                'Kasir' => $saring['UuidKasir'],
                'Kanal' => $saring['Kanal'],
                'Target' => $s->targetSajiMenit,
            ],
            'OpsiTarget' => LaporanOperasional::TARGET_SAJI_OPSI,
            'Peringatan' => $saring['Periode']->peringatan,
            'MaksHari' => DataPeriodeLaporan::MAKS_HARI,
            'OpsiOutlet' => array_map(fn (array $o): array => ['Nilai' => $o['Uuid'], 'Label' => $o['Nama']], $this->outlet->AmbilRingkas($idOutletBoleh)),
            'OpsiKasir' => array_map(fn (array $o): array => ['Nilai' => $o['Nilai'], 'Label' => $o['Label']], $this->AmbilOpsiKasir($idOutletBoleh)),
            'OpsiKanal' => array_map(fn (KanalPenjualan $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()], KanalPenjualan::cases()),
            'Total' => $this->agregat->Total($s)->KeLarik(),
            'Isi' => $this->AmbilIsiTab($saring['Tab'], $s, $tabel),
        ];
    }

    /**
     * Data satu tab. `produk` = `{Data, Meta}` (TabelData mode server); tab lain = daftar baris (mode lokal).
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function AmbilIsiTab(string $tab, DataSaringLaporanPenjualan $saring, DataPermintaanTabel $tabel): array
    {
        return match ($tab) {
            'produk' => $this->agregat->PerProdukTabel($saring, $tabel),
            // D-43: detail penjualan per item (mirip "Detail Penjualan" Majoo), TabelData mode server.
            'detail' => $this->agregat->DetailPerItemTabel($saring, $tabel),
            'kategori' => $this->PerKategori($saring),
            'jam' => $this->PerJam($saring),
            'kasir' => $this->PerKasir($saring),
            'kanal' => $this->PerKanal($saring),
            'metode' => $this->PerMetode($saring),
            'diskon' => $this->Diskon($saring),
            'void-item' => $this->VoidItem($saring),
            'anti-fraud' => $this->AntiFraud($saring),
            // X6 (v3.43): analisis ABC & menu engineering dari agregat per produk periode yang sama.
            'abc' => $this->insight->Abc($this->agregat->PerProduk($saring)),
            'menu' => $this->insight->Menu($this->agregat->PerProduk($saring)),
            'pelanggan' => $this->PerPelanggan($saring),
            'outlet' => $this->PerOutlet($saring),
            'waktu-saji' => $this->operasional->WaktuSaji($saring, $saring->targetSajiMenit),
            'food-cost' => $this->operasional->FoodCost($saring),
            'performa-kasir' => $this->operasional->PerformaKasir($saring),
            'prediksi' => $this->Prediksi($saring),
            'bundling' => $this->Bundling($saring),
            default => $this->Harian($saring),
        };
    }

    /**
     * Ringkasan harian: dari tabel ringkasan (cepat) bila tanpa saring kasir/kanal, selain itu dari dokumen.
     *
     * @return list<array<string, mixed>>
     */
    public function Harian(DataSaringLaporanPenjualan $saring): array
    {
        if ($saring->CekTanpaOutlet()) {
            return [];
        }

        $perTanggal = [];

        if ($saring->idKasir === null && $saring->kanal === null) {
            $baris = RingkasanPenjualanHarian::query()
                ->whereBetween('TanggalBisnis', [$saring->dari->toDateString(), $saring->sampai->toDateString()])
                ->when($saring->idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $saring->idOutlet ?? []))
                ->orderBy('TanggalBisnis')
                ->get();

            foreach ($baris as $b) {
                $tanggal = $b->TanggalBisnis->toDateString();
                $agregat = self::DariRingkasan($b);
                $perTanggal[$tanggal] = isset($perTanggal[$tanggal]) ? $perTanggal[$tanggal]->Tambah($agregat) : $agregat;
            }
        } else {
            foreach ($this->agregat->Agregasi($saring, ['Tanggal']) as $b) {
                $perTanggal[$b['Kunci'][0]] = $b['Agregat'];
            }
        }

        ksort($perTanggal);

        return array_values(array_map(fn (string $tanggal, DataAgregatPenjualan $a): array => ['Tanggal' => $tanggal, ...$a->KeLarik()], array_keys($perTanggal), $perTanggal));
    }

    /** Agregat satu baris ringkasan harian. */
    public static function DariRingkasan(RingkasanPenjualanHarian $b): DataAgregatPenjualan
    {
        return new DataAgregatPenjualan(
            Uang::Dari($b->Kotor),
            Uang::Dari($b->Diskon),
            Uang::Dari($b->Retur),
            Uang::Dari($b->Pajak),
            Uang::Dari($b->BiayaLayanan),
            Uang::Dari($b->Hpp),
            $b->JumlahTransaksi,
            $b->JumlahRetur,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function PerKategori(DataSaringLaporanPenjualan $saring): array
    {
        $produk = $this->agregat->PerProduk($saring);
        $kategori = $this->produk->AmbilKategori(array_column($produk, 'IdProduk'));
        $grup = [];

        foreach ($produk as $p) {
            $k = $kategori[$p['IdProduk']] ?? ['IdKategori' => null, 'NamaKategori' => ''];
            $kunci = (string) ($k['IdKategori'] ?? 0);
            $ada = $grup[$kunci] ?? [
                'Kunci' => $kunci,
                'NamaKategori' => $k['IdKategori'] === null ? 'Tanpa kategori' : $k['NamaKategori'],
                'JumlahProduk' => 0,
                'Qty' => Kuantitas::Nol(),
                'Kotor' => Uang::Nol(),
                'Diskon' => Uang::Nol(),
                'Retur' => Uang::Nol(),
                'Bersih' => Uang::Nol(),
                'Pajak' => Uang::Nol(),
                'Hpp' => Uang::Nol(),
                'LabaKotor' => Uang::Nol(),
            ];
            $ada['JumlahProduk']++;
            $ada['Qty'] = $ada['Qty']->Tambah(Kuantitas::Dari($p['Qty']));

            foreach (['Kotor', 'Diskon', 'Retur', 'Bersih', 'Pajak', 'Hpp', 'LabaKotor'] as $kolom) {
                $ada[$kolom] = $ada[$kolom]->Tambah(Uang::Dari($p[$kolom]));
            }

            $grup[$kunci] = $ada;
        }

        $hasil = array_values(array_map(fn (array $g): array => [
            'Kunci' => $g['Kunci'],
            'NamaKategori' => $g['NamaKategori'],
            'JumlahProduk' => $g['JumlahProduk'],
            'Qty' => $g['Qty']->KeString(),
            'Kotor' => $g['Kotor']->KeString(),
            'Diskon' => $g['Diskon']->KeString(),
            'Retur' => $g['Retur']->KeString(),
            'Bersih' => $g['Bersih']->KeString(),
            'Pajak' => $g['Pajak']->KeString(),
            'Hpp' => $g['Hpp']->KeString(),
            'LabaKotor' => $g['LabaKotor']->KeString(),
        ], $grup));
        usort($hasil, fn (array $a, array $b): int => Uang::Dari($b['Bersih'])->Bandingkan(Uang::Dari($a['Bersih'])) ?: strcmp($a['NamaKategori'], $b['NamaKategori']));

        return $hasil;
    }

    /**
     * Heatmap hari × jam lokal outlet (`Sel`) dan total per jam (`PerJam`, 24 baris untuk tabel yang bisa dibaca
     * pembaca layar).
     *
     * @return array{Sel: list<array{Hari: int, Jam: int, Bersih: string, JumlahTransaksi: int}>, PerJam: list<array{Jam: int, Bersih: string, JumlahTransaksi: int}>}
     */
    public function PerJam(DataSaringLaporanPenjualan $saring): array
    {
        $sel = $this->agregat->PerJam($saring, $this->zonaWaktu->AmbilSelisihDetik($saring->idOutlet));
        $perJam = [];

        foreach ($sel as $s) {
            $ada = $perJam[$s['Jam']] ?? ['Jam' => $s['Jam'], 'Bersih' => Uang::Nol(), 'JumlahTransaksi' => 0];
            $ada['Bersih'] = $ada['Bersih']->Tambah(Uang::Dari($s['Bersih']));
            $ada['JumlahTransaksi'] += $s['JumlahTransaksi'];
            $perJam[$s['Jam']] = $ada;
        }

        ksort($perJam);

        return [
            'Sel' => $sel,
            'PerJam' => array_values(array_map(fn (array $j): array => [...$j, 'Bersih' => $j['Bersih']->KeString()], $perJam)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function PerKasir(DataSaringLaporanPenjualan $saring): array
    {
        $baris = $this->agregat->Agregasi($saring, ['Kasir']);
        $nama = $this->anggota->AmbilNama(array_map(fn (array $b): int => (int) $b['Kunci'][0], array_values($baris)));
        $hasil = [];

        foreach ($baris as $b) {
            $id = (int) $b['Kunci'][0];
            $hasil[] = ['Kunci' => $nama[$id]['Uuid'] ?? (string) $id, 'NamaKasir' => $nama[$id]['Nama'] ?? 'Pengguna tidak dikenal', ...$b['Agregat']->KeLarik()];
        }

        return self::UrutBersih($hasil);
    }

    /**
     * Penjualan bersih per pelanggan (§10.10 "per pelanggan"): urut omzet bersih, `Porsi` = bagian dari seluruh omzet
     * bersih saring (termasuk transaksi tanpa pelanggan, baris "Tanpa pelanggan"). Dibatasi `$batas` baris teratas.
     *
     * @return list<array<string, mixed>>
     */
    public function PerPelanggan(DataSaringLaporanPenjualan $saring, int $batas = self::BATAS_PELANGGAN): array
    {
        $baris = $this->agregat->Agregasi($saring, ['Pelanggan']);
        $nama = $this->pelanggan->AmbilNamaBanyak(array_map(fn (array $b): int => (int) $b['Kunci'][0], array_values($baris)));
        $hasil = [];

        foreach ($baris as $b) {
            $id = (int) $b['Kunci'][0];
            $tanpa = $id === 0;
            $hasil[] = [
                'Kunci' => $tanpa ? '' : ($nama[$id]['Uuid'] ?? (string) $id),
                'NamaPelanggan' => $tanpa ? 'Tanpa pelanggan' : ($nama[$id]['Nama'] ?? 'Pelanggan tidak dikenal'),
                ...$b['Agregat']->KeLarik(),
            ];
        }

        return array_slice(self::TambahPorsi(self::UrutBersih($hasil)), 0, $batas);
    }

    /**
     * Perbandingan outlet (§10.10 "perbandingan outlet"): angka yang sama dengan tab lain per outlet, ditambah margin
     * laba kotor dan bagian dari omzet bersih. Merek tidak ditampilkan (D-76).
     *
     * @return list<array<string, mixed>>
     */
    public function PerOutlet(DataSaringLaporanPenjualan $saring): array
    {
        $baris = $this->agregat->Agregasi($saring, ['Outlet']);
        $nama = [];

        foreach ($this->outlet->AmbilRingkas($saring->idOutlet) as $o) {
            $nama[$o['Id']] = $o;
        }

        $hasil = [];

        foreach ($baris as $b) {
            $id = (int) $b['Kunci'][0];
            $hasil[] = ['Kunci' => $nama[$id]['Uuid'] ?? (string) $id, 'NamaOutlet' => $nama[$id]['Nama'] ?? 'Outlet tidak dikenal', ...$b['Agregat']->KeLarik()];
        }

        return self::TambahPorsi(self::UrutBersih($hasil));
    }

    /**
     * Menambah `Porsi` (% omzet bersih terhadap jumlah semua baris) dan `Margin` (% laba kotor terhadap omzet bersih),
     * 2 desimal, dari string desimal (tanpa float).
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array<string, mixed>>
     */
    private static function TambahPorsi(array $baris): array
    {
        $total = BigDecimal::zero();

        foreach ($baris as $b) {
            $total = $total->plus(self::Teks($b['Bersih']));
        }

        $persen = static fn (BigDecimal $bagian, BigDecimal $dasar): string => $dasar->isZero()
            ? '0.00'
            : (string) $bagian->multipliedBy(100)->dividedBy($dasar, 2, RoundingMode::HalfUp);

        return array_values(array_map(fn (array $b): array => [
            ...$b,
            'Porsi' => $persen(BigDecimal::of(self::Teks($b['Bersih'])), $total),
            'Margin' => $persen(BigDecimal::of(self::Teks($b['LabaKotor'])), BigDecimal::of(self::Teks($b['Bersih']))),
        ], $baris));
    }

    /**
     * Prediksi penjualan 14 hari ke depan (`PeramalPenjualan`) dari penjualan bersih harian 8 minggu terakhir sampai
     * kemarin pada outlet/kasir/kanal yang sama dengan saring (periode laporan tidak dipakai).
     *
     * @return array<string, mixed>
     */
    public function Prediksi(DataSaringLaporanPenjualan $saring, int $horizon = 14): array
    {
        $hariIni = ($saring->hariIni ?? CarbonImmutable::now())->startOfDay();
        $jendela = new DataSaringLaporanPenjualan($hariIni->subDays(PeramalPenjualan::MINGGU_DASAR * 7), $hariIni->subDay(), $saring->idOutlet, $saring->idKasir, $saring->kanal);
        $seri = [];

        foreach ($this->Harian($jendela) as $b) {
            $seri[(string) $b['Tanggal']] = ['Bersih' => self::Teks($b['Bersih']), 'JumlahTransaksi' => (int) $b['JumlahTransaksi']];
        }

        return $this->peramal->Ramal($seri, $hariIni, $horizon);
    }

    /**
     * Rekomendasi bundling (`PencariPasanganProduk`) dari keranjang penjualan pada periode saring: pasangan teratas dengan
     * harga normal (jumlah harga rata-rata satu unit masing-masing) dan harga paket saran (95%, dibulatkan ke bawah ke
     * kelipatan Rp 500).
     *
     * @return array{JumlahTransaksi: int, Terpotong: bool, Baris: list<array<string, mixed>>}
     */
    public function Bundling(DataSaringLaporanPenjualan $saring): array
    {
        $keranjang = $this->agregat->KeranjangProduk($saring);
        $hasil = $this->pasangan->Cari($keranjang['Keranjang'], self::BATAS_BUNDLING);
        $harga = [];

        foreach ($this->agregat->PerProduk($saring) as $p) {
            if (BigDecimal::of(self::Teks($p['Qty']))->isPositive()) {
                $harga[(int) $p['IdProduk']] = BigDecimal::of(self::Teks($p['Bersih']))->dividedBy(self::Teks($p['Qty']), 2, RoundingMode::HalfUp);
            }
        }

        $baris = [];

        foreach ($hasil['Baris'] as $b) {
            $normal = isset($harga[$b['IdProdukA']], $harga[$b['IdProdukB']]) ? $harga[$b['IdProdukA']]->plus($harga[$b['IdProdukB']]) : null;
            $baris[] = [
                'Kunci' => $b['IdProdukA'].'-'.$b['IdProdukB'],
                'NamaA' => $keranjang['Nama'][$b['IdProdukA']] ?? 'Produk',
                'NamaB' => $keranjang['Nama'][$b['IdProdukB']] ?? 'Produk',
                'Support' => $b['Support'],
                'PorsiTransaksi' => $b['PorsiTransaksi'],
                'KeyakinanAB' => $b['KeyakinanAB'],
                'KeyakinanBA' => $b['KeyakinanBA'],
                'Lift' => $b['Lift'],
                'HargaNormal' => $normal === null ? null : (string) $normal->toScale(2),
                'HargaPaket' => $normal === null ? null : (string) $normal->multipliedBy('0.95')->dividedBy(500, 0, RoundingMode::Down)->multipliedBy(500)->toScale(2),
            ];
        }

        return ['JumlahTransaksi' => $hasil['JumlahTransaksi'], 'Terpotong' => $keranjang['Terpotong'], 'Baris' => $baris];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function PerKanal(DataSaringLaporanPenjualan $saring): array
    {
        $hasil = [];

        foreach ($this->agregat->Agregasi($saring, ['Kanal']) as $b) {
            $kanal = KanalPenjualan::tryFrom($b['Kunci'][0]);
            $hasil[] = ['Kunci' => $b['Kunci'][0], 'LabelKanal' => $kanal?->AmbilLabel() ?? $b['Kunci'][0], ...$b['Agregat']->KeLarik()];
        }

        return self::UrutBersih($hasil);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function PerMetode(DataSaringLaporanPenjualan $saring): array
    {
        return array_values(array_map(fn (array $m): array => [
            'Kunci' => (string) $m['IdMetodePembayaran'],
            'NamaMetode' => $m['Nama'],
            'LabelJenis' => JenisMetodePembayaran::tryFrom($m['Jenis'])?->AmbilLabel() ?? $m['Jenis'],
            'Diterima' => $m['Diterima'],
            'Refund' => $m['Refund'],
            'Bersih' => $m['Bersih'],
            'JumlahTransaksi' => $m['JumlahTransaksi'],
        ], $this->agregat->PerMetode($saring)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function Diskon(DataSaringLaporanPenjualan $saring): array
    {
        $baris = $this->agregat->DiskonPerKasir($saring);
        $nama = $this->anggota->AmbilNama(array_column($baris, 'IdKasir'));

        return array_values(array_map(fn (array $b): array => [
            'Kunci' => $nama[$b['IdKasir']]['Uuid'] ?? (string) $b['IdKasir'],
            'NamaKasir' => $nama[$b['IdKasir']]['Nama'] ?? 'Pengguna tidak dikenal',
            'JumlahTransaksi' => $b['JumlahTransaksi'],
            'JumlahBerdiskon' => $b['JumlahBerdiskon'],
            'JumlahDisetujui' => $b['JumlahDisetujui'],
            'DiskonBaris' => $b['DiskonBaris'],
            'DiskonPesanan' => $b['DiskonPesanan'],
            'TotalDiskon' => $b['TotalDiskon'],
            'Kotor' => $b['Kotor'],
        ], $baris));
    }

    /**
     * BR-07.5: void item pesanan meja (item sudah dikirim ke dapur lalu dibatalkan) dengan alasan, pembatal, penyetuju, dan
     * apakah bahan dicatat terbuang. Terbaru dulu; paling banyak `BATAS_VOID_ITEM` baris untuk tampilan.
     *
     * @return list<array<string, mixed>>
     */
    public function VoidItem(DataSaringLaporanPenjualan $saring, int $batas = self::BATAS_VOID_ITEM): array
    {
        $baris = $this->agregat->VoidItem($saring, $this->zonaWaktu->AmbilSelisihDetik($saring->idOutlet), $batas);
        $nama = $this->anggota->AmbilNama(array_values(array_filter(array_merge(array_column($baris, 'IdPembatal'), array_column($baris, 'IdPenyetuju')), fn (mixed $id): bool => is_int($id))));

        return array_values(array_map(fn (array $b): array => [
            'Kunci' => $b['Uuid'],
            'Waktu' => $b['Waktu'],
            'NamaOutlet' => $b['NamaOutlet'],
            'Nomor' => $b['Nomor'],
            'NamaProduk' => $b['NamaProduk'],
            'Jumlah' => $b['Jumlah'],
            'Nilai' => $b['Nilai'],
            'Alasan' => $b['Alasan'] ?? '',
            'NamaPembatal' => $b['IdPembatal'] === null ? '' : ($nama[$b['IdPembatal']]['Nama'] ?? 'Pengguna tidak dikenal'),
            'NamaPenyetuju' => $b['IdPenyetuju'] === null ? '' : ($nama[$b['IdPenyetuju']]['Nama'] ?? 'Pengguna tidak dikenal'),
            'Terbuang' => $b['Terbuang'],
        ], $baris));
    }

    /**
     * F-14 anti-fraud per kasir (OWN-09, BR-09.3): pola void (termasuk void tunai ≤ 10 menit setelah bayar), retur,
     * diskon, buka laci tanpa transaksi, dan selisih kas tutup shift, beserta skor risiko & alasannya. Urut skor menurun.
     *
     * @return list<array<string, mixed>>
     */
    public function AntiFraud(DataSaringLaporanPenjualan $saring): array
    {
        if ($saring->CekTanpaOutlet()) {
            return [];
        }

        $pola = $this->agregat->PolaVoidReturPerKasir($saring, PenilaiRisikoKasir::MENIT_VOID_CEPAT);

        foreach ($this->agregat->DiskonPerKasir($saring) as $d) {
            $pola[$d['IdKasir']] = [...($pola[$d['IdKasir']] ?? []), 'JumlahBerdiskon' => $d['JumlahBerdiskon'], 'JumlahDisetujui' => $d['JumlahDisetujui'], 'TotalDiskon' => $d['TotalDiskon']];
        }

        foreach ($this->polaKas->PerKasir($saring->dari, $saring->sampai, $saring->idOutlet, $saring->idKasir) as $idKasir => $k) {
            $pola[$idKasir] = [...($pola[$idKasir] ?? []), ...$k];
        }

        $skor = $this->penilai->Nilai($pola, $this->pengaturanKasir->Ambil()->toleransiSelisihKas);
        $nama = $this->anggota->AmbilNama(array_keys($pola));
        $baris = [];

        foreach ($pola as $idKasir => $p) {
            $baris[] = [
                'Kunci' => $nama[$idKasir]['Uuid'] ?? (string) $idKasir,
                'NamaKasir' => $nama[$idKasir]['Nama'] ?? 'Pengguna tidak dikenal',
                'JumlahTransaksi' => (int) ($p['JumlahTransaksi'] ?? 0),
                'JumlahVoid' => (int) ($p['JumlahVoid'] ?? 0),
                'NilaiVoid' => (string) ($p['NilaiVoid'] ?? '0.00'),
                'VoidCepatTunai' => (int) ($p['VoidCepatTunai'] ?? 0),
                'JumlahRetur' => (int) ($p['JumlahRetur'] ?? 0),
                'NilaiRetur' => (string) ($p['NilaiRetur'] ?? '0.00'),
                'JumlahBerdiskon' => (int) ($p['JumlahBerdiskon'] ?? 0),
                'TotalDiskon' => (string) ($p['TotalDiskon'] ?? '0.00'),
                'BukaLaciManual' => (int) ($p['BukaLaciManual'] ?? 0),
                'ShiftSelisihKurang' => (int) ($p['ShiftSelisihKurang'] ?? 0),
                'SelisihKurang' => (string) ($p['SelisihKurang'] ?? '0.00'),
                'Skor' => $skor[$idKasir]['Skor'],
                'Tingkat' => $skor[$idKasir]['Tingkat'],
                'Alasan' => $skor[$idKasir]['Alasan'],
            ];
        }

        usort($baris, fn (array $a, array $b): int => [$b['Skor'], $a['NamaKasir']] <=> [$a['Skor'], $b['NamaKasir']]);

        return $baris;
    }

    /**
     * Nama outlet/kasir/kanal yang dipilih untuk blok saringan kop laporan (D-43). Kosong = "Semua ...".
     *
     * @param  array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, UuidKasir: string, Kanal: string, Saring: DataSaringLaporanPenjualan}  $saring
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Outlet: string, Kasir: string, Kanal: string}
     */
    public function AmbilLabelSaringan(array $saring, ?array $idOutletBoleh): array
    {
        $namaOutlet = '';

        foreach ($this->outlet->AmbilRingkas($idOutletBoleh) as $o) {
            if ($o['Uuid'] === $saring['UuidOutlet']) {
                $namaOutlet = $o['Nama'];
            }
        }

        $namaKasir = 'Semua Kasir';

        foreach ($this->AmbilOpsiKasir($idOutletBoleh) as $opsi) {
            if ($saring['UuidKasir'] !== '' && $opsi['Nilai'] === $saring['UuidKasir']) {
                $namaKasir = $opsi['Label'];
            }
        }

        return [
            'Outlet' => $namaOutlet,
            'Kasir' => $namaKasir,
            'Kanal' => KanalPenjualan::tryFrom($saring['Kanal'])?->AmbilLabel() ?? 'Semua Kanal',
        ];
    }

    /**
     * Blok ringkasan kop laporan penjualan: angka periode dan saring yang sama dengan isi tabel (D-43).
     *
     * @return list<ItemRingkasan>
     */
    public function RingkasanPeriode(DataSaringLaporanPenjualan $saring): array
    {
        $total = $this->agregat->Total($saring);

        return [
            new ItemRingkasan('Penjualan Kotor', $total->kotor->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Diskon', $total->diskon->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Retur', $total->retur->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Penjualan Bersih', $total->Bersih()->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Laba Kotor', $total->LabaKotor()->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Total Transaksi', $total->jumlahTransaksi, JenisKolom::Bilangan),
        ];
    }

    public function WaktuTerakhirDiterima(DataSaringLaporanPenjualan $saring): ?DateTimeImmutable
    {
        return $this->agregat->WaktuTerakhirDiterima($saring);
    }

    /**
     * Kolom dan baris ekspor satu tab sesuai saring (D-43): tiap kolom diberi jenis (uang, bilangan, kuantitas, persen,
     * tanggal) supaya Excel menyimpannya sebagai nilai asli, dan `jumlahkan` menandai kolom yang dijumlah di kaki tabel.
     * Uang dan kuantitas tetap string desimal dari kueri (tanpa float).
     *
     * @return array{0: list<KolomLaporan>, 1: iterable<list<string|int|DateTimeInterface|null>>}
     */
    public function AmbilEkspor(string $tab, DataSaringLaporanPenjualan $saring, string $cari = ''): array
    {
        $t = static fn (string $judul, int $lebar = 0): KolomLaporan => new KolomLaporan($judul, JenisKolom::Teks, $lebar > 0 ? $lebar : null);
        $u = static fn (string $judul, bool $jumlah = true): KolomLaporan => new KolomLaporan($judul, JenisKolom::Uang, jumlahkan: $jumlah);
        $n = static fn (string $judul, bool $jumlah = true): KolomLaporan => new KolomLaporan($judul, JenisKolom::Bilangan, jumlahkan: $jumlah);
        $q = static fn (string $judul, bool $jumlah = false): KolomLaporan => new KolomLaporan($judul, JenisKolom::Kuantitas, jumlahkan: $jumlah);
        $pr = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Persen);
        $angka = ['Kotor', 'Diskon', 'Retur', 'Bersih', 'Pajak', 'BiayaLayanan', 'Hpp', 'LabaKotor', 'JumlahTransaksi', 'JumlahRetur'];
        $kolomAngka = [$u('Kotor'), $u('Diskon'), $u('Retur'), $u('Bersih'), $u('Pajak'), $u('Biaya layanan'), $u('HPP'), $u('Laba kotor'), $n('Jumlah transaksi'), $n('Jumlah retur')];
        $ambil = fn (array $baris, array $kolom): array => array_values(array_map(fn (array $b): array => array_values(array_map(fn (string $k): string|int|null => self::Sel($b[$k] ?? null), $kolom)), $baris));

        return match ($tab) {
            'detail' => [
                [$t('No Transaksi', 22), new KolomLaporan('Waktu Transaksi', JenisKolom::TanggalWaktu), $t('Outlet', 24), $t('Jenis Order', 16), $t('Nama Produk', 32), $q('Quantity', true), $u('Harga Satuan', false), $u('Kotor'), $u('Diskon'), $u('Pajak'), $u('Total'), $t('Metode Pembayaran', 24), $t('Kasir', 22), $t('Catatan', 28)],
                (function () use ($saring, $cari): \Generator {
                    foreach ($this->agregat->DetailPerItem($saring, $cari) as $b) {
                        yield [$b['Nomor'], new DateTimeImmutable($b['Waktu']), $b['NamaOutlet'], $b['Kanal'], $b['NamaProduk'], $b['Qty'], $b['HargaSatuan'], $b['Kotor'], $b['Diskon'], $b['Pajak'], $b['Total'], $b['Metode'], $b['NamaKasir'], $b['Catatan']];
                    }
                })(),
            ],
            'produk' => [[$t('Produk', 32), $q('Qty (satuan dasar)'), ...$kolomAngka], $ambil($this->agregat->PerProduk($saring, $cari), ['NamaProduk', 'Qty', ...$angka])],
            'kategori' => [[$t('Kategori', 28), $n('Jumlah produk', false), $q('Qty (satuan dasar)'), $u('Kotor'), $u('Diskon'), $u('Retur'), $u('Bersih'), $u('Pajak'), $u('HPP'), $u('Laba kotor')], $ambil($this->PerKategori($saring), ['NamaKategori', 'JumlahProduk', 'Qty', 'Kotor', 'Diskon', 'Retur', 'Bersih', 'Pajak', 'Hpp', 'LabaKotor'])],
            'jam' => [[$t('Hari', 14), $t('Jam', 10), $u('Bersih'), $n('Jumlah transaksi')], $ambil(array_map(fn (array $s): array => [...$s, 'Hari' => self::NamaHari($s['Hari']), 'Jam' => sprintf('%02d:00', $s['Jam'])], $this->PerJam($saring)['Sel']), ['Hari', 'Jam', 'Bersih', 'JumlahTransaksi'])],
            'kasir' => [[$t('Kasir', 26), ...$kolomAngka, $u('Rata-rata keranjang', false)], $ambil($this->PerKasir($saring), ['NamaKasir', ...$angka, 'RataRataKeranjang'])],
            'kanal' => [[$t('Kanal', 20), ...$kolomAngka, $u('Rata-rata keranjang', false)], $ambil($this->PerKanal($saring), ['LabelKanal', ...$angka, 'RataRataKeranjang'])],
            'metode' => [[$t('Metode bayar', 26), $t('Jenis', 16), $u('Diterima'), $u('Refund'), $u('Bersih'), $n('Jumlah transaksi')], $ambil($this->PerMetode($saring), ['NamaMetode', 'LabelJenis', 'Diterima', 'Refund', 'Bersih', 'JumlahTransaksi'])],
            'diskon' => [[$t('Kasir', 26), $n('Jumlah transaksi'), $n('Transaksi berdiskon'), $n('Diskon disetujui'), $u('Diskon baris'), $u('Diskon pesanan'), $u('Total diskon'), $u('Kotor')], $ambil($this->Diskon($saring), ['NamaKasir', 'JumlahTransaksi', 'JumlahBerdiskon', 'JumlahDisetujui', 'DiskonBaris', 'DiskonPesanan', 'TotalDiskon', 'Kotor'])],
            'void-item' => [
                [new KolomLaporan('Waktu', JenisKolom::TanggalWaktu), $t('Outlet', 24), $t('No Pesanan', 22), $t('Item', 32), $q('Jumlah', true), $u('Nilai'), $t('Alasan', 36), $t('Dibatalkan oleh', 22), $t('Disetujui oleh', 22), $t('Bahan terbuang', 16)],
                (function () use ($saring): \Generator {
                    foreach ($this->VoidItem($saring, self::BATAS_EKSPOR_VOID_ITEM) as $b) {
                        yield [new DateTimeImmutable($b['Waktu']), $b['NamaOutlet'], $b['Nomor'], $b['NamaProduk'], $b['Jumlah'], $b['Nilai'], $b['Alasan'], $b['NamaPembatal'], $b['NamaPenyetuju'], $b['Terbuang'] ? 'Ya' : 'Tidak'];
                    }
                })(),
            ],
            'anti-fraud' => [
                [$t('Kasir', 26), $n('Skor risiko', false), $t('Tingkat', 12), $n('Transaksi'), $n('Void'), $u('Nilai void'), $n('Void tunai cepat'), $n('Retur'), $u('Nilai retur'), $n('Transaksi berdiskon'), $u('Total diskon'), $n('Buka laci manual'), $n('Shift kas kurang'), $u('Total kas kurang'), $t('Alasan', 60)],
                $ambil(array_map(fn (array $b): array => [...$b, 'Alasan' => implode('; ', $b['Alasan'])], $this->AntiFraud($saring)), ['NamaKasir', 'Skor', 'Tingkat', 'JumlahTransaksi', 'JumlahVoid', 'NilaiVoid', 'VoidCepatTunai', 'JumlahRetur', 'NilaiRetur', 'JumlahBerdiskon', 'TotalDiskon', 'BukaLaciManual', 'ShiftSelisihKurang', 'SelisihKurang', 'Alasan']),
            ],
            'waktu-saji' => [
                [$t('Stasiun', 24), $n('Jumlah tiket'), $n('Rata-rata tunggu (detik)', false), $n('Rata-rata masak (detik)', false), $n('Rata-rata total (detik)', false), $n('Median total (detik)', false), $n('Persentil 90 (detik)', false), $n('Terlama (detik)', false), $n('Rata-rata sajikan (detik)', false), $n("Lewat target {$saring->targetSajiMenit} menit"), $pr('Persen lewat target')],
                $ambil($this->operasional->WaktuSaji($saring, $saring->targetSajiMenit), ['NamaStasiun', 'JumlahTiket', 'TungguRataRata', 'MasakRataRata', 'TotalRataRata', 'TotalMedian', 'TotalP90', 'TotalTerlama', 'SajikanRataRata', 'LewatTarget', 'PersenLewatTarget']),
            ],
            'food-cost' => [
                [$t('Bahan/produk', 32), $q('Terpakai teoretis'), $u('Nilai teoretis'), $q('Terbuang'), $u('Nilai terbuang'), $q('Selisih stok'), $u('Nilai selisih stok'), $u('Nilai aktual'), $pr('Varian vs teoretis %')],
                $ambil($this->operasional->FoodCost($saring)['Baris'], ['NamaProduk', 'QtyTeoretis', 'NilaiTeoretis', 'QtyTerbuang', 'NilaiTerbuang', 'QtySelisih', 'NilaiSelisih', 'NilaiAktual', 'VarianPersen']),
            ],
            'performa-kasir' => [
                [$t('Kasir', 26), $n('Transaksi'), $u('Penjualan bersih'), $u('Rata-rata keranjang', false), new KolomLaporan('Jam kerja', JenisKolom::Kuantitas), $n('Shift ditutup'), new KolomLaporan('Transaksi per jam', JenisKolom::Kuantitas), $u('Omzet per jam', false), $n('Void'), $pr('Void %'), $u('Selisih kas kurang')],
                $ambil($this->operasional->PerformaKasir($saring), ['NamaKasir', 'JumlahTransaksi', 'Bersih', 'RataRataKeranjang', 'JamKerja', 'ShiftDitutup', 'TransaksiPerJam', 'OmzetPerJam', 'JumlahVoid', 'PersenVoid', 'SelisihKurang']),
            ],
            'prediksi' => [
                [new KolomLaporan('Tanggal', JenisKolom::Tanggal), $t('Hari', 10), $u('Perkiraan penjualan bersih'), $n('Perkiraan transaksi', false), $u('Terendah (riwayat)', false), $u('Tertinggi (riwayat)', false), $n('Jumlah sampel', false)],
                array_values(array_map(fn (array $b): array => [new DateTimeImmutable((string) $b['Tanggal']), self::NamaHari((int) $b['Hari']), $b['Perkiraan'], $b['PerkiraanTransaksi'], $b['Terendah'], $b['Tertinggi'], $b['Sampel']], $this->Prediksi($saring)['Baris'])),
            ],
            'bundling' => [
                [$t('Produk A', 30), $t('Produk B', 30), $n('Transaksi bersama'), $pr('Porsi transaksi %'), $pr('Keyakinan A→B %'), $pr('Keyakinan B→A %'), new KolomLaporan('Lift', JenisKolom::Kuantitas), $u('Harga normal paket', false), $u('Harga paket saran', false)],
                $ambil($this->Bundling($saring)['Baris'], ['NamaA', 'NamaB', 'Support', 'PorsiTransaksi', 'KeyakinanAB', 'KeyakinanBA', 'Lift', 'HargaNormal', 'HargaPaket']),
            ],
            'pelanggan' => [[$t('Pelanggan', 30), ...$kolomAngka, $u('Rata-rata keranjang', false), $pr('Porsi %')], $ambil($this->PerPelanggan($saring, self::BATAS_EKSPOR_PELANGGAN), ['NamaPelanggan', ...$angka, 'RataRataKeranjang', 'Porsi'])],
            'outlet' => [[$t('Outlet', 28), ...$kolomAngka, $u('Rata-rata keranjang', false), $pr('Margin %'), $pr('Porsi %')], $ambil($this->PerOutlet($saring), ['NamaOutlet', ...$angka, 'RataRataKeranjang', 'Margin', 'Porsi'])],
            'abc' => [[$t('Produk', 32), $q('Qty (satuan dasar)'), $u('Bersih'), $pr('Porsi %'), $pr('Kumulatif %'), $t('Kelas', 10)], $ambil($this->insight->Abc($this->agregat->PerProduk($saring))['Baris'], ['NamaProduk', 'Qty', 'Bersih', 'Porsi', 'PorsiKumulatif', 'Kelas'])],
            'menu' => [[$t('Produk', 32), $q('Qty (satuan dasar)'), $u('Bersih'), $u('HPP'), $u('Margin per unit', false), $pr('Porsi qty %'), $t('Kelas', 14), $u('Harga rata-rata', false), $u('Saran harga', false)], $ambil($this->insight->Menu($this->agregat->PerProduk($saring))['Baris'], ['NamaProduk', 'Qty', 'Bersih', 'Hpp', 'MarginPerUnit', 'PorsiQty', 'Kelas', 'HargaRata', 'SaranHarga'])],
            default => [[new KolomLaporan('Tanggal', JenisKolom::Tanggal), ...$kolomAngka, $u('Rata-rata keranjang', false)], $ambil($this->Harian($saring), ['Tanggal', ...$angka, 'RataRataKeranjang'])],
        };
    }

    /**
     * Kasir yang pernah bertransaksi di outlet yang boleh diakses, urut nama.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Id: int, Nilai: string, Label: string}>
     */
    private function AmbilOpsiKasir(?array $idOutletBoleh): array
    {
        $opsi = array_map(fn (int $id, array $p): array => ['Id' => $id, 'Nilai' => $p['Uuid'], 'Label' => $p['Nama']], array_keys($nama = $this->anggota->AmbilNama($this->agregat->AmbilIdKasir($idOutletBoleh))), $nama);
        usort($opsi, fn (array $a, array $b): int => strcmp($a['Label'], $b['Label']));

        return $opsi;
    }

    public static function NamaHari(int $hari): string
    {
        return ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][$hari - 1] ?? (string) $hari;
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     * @return list<array<string, mixed>>
     */
    private static function UrutBersih(array $baris): array
    {
        usort($baris, fn (array $a, array $b): int => Uang::Dari(self::Teks($b['Bersih']))->Bandingkan(Uang::Dari(self::Teks($a['Bersih']))));

        return $baris;
    }

    private static function Sel(mixed $nilai): string|int|null
    {
        return is_string($nilai) || is_int($nilai) || $nilai === null ? $nilai : null;
    }

    private static function Teks(mixed $nilai): string
    {
        return is_string($nilai) ? $nilai : '0';
    }
}
