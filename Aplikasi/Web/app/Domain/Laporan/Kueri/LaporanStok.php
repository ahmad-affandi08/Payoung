<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\ProdukUntukLaporan;
use App\Domain\Laporan\Layanan\PenilaiMusimRestock;
use App\Domain\Laporan\Layanan\PenilaiRencanaProduksi;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Penjualan\Kueri\PermintaanPreOrder;
use App\Domain\Persediaan\Kueri\StokUntukLaporan;
use App\Domain\Referensi\Kueri\HariLiburTerbit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Laporan stok F-14a (`/kelola/laporan/stok`, izin `persediaan.lihat`, lokasi stok di outlet yang boleh diakses):
 * - nilai persediaan per lokasi stok dan per kategori pada akhir tanggal bisnis tertentu (dari `MutasiStok`; tanpa
 *   barang konsinyasi, F-05i);
 * - stok kritis: saldo terkini ≤ batas minimum per lokasi stok (`ProdukGudang.StokMinimum`), urut kekurangan terbesar.
 * Posisi & kartu stok per produk sudah ada di F-05a.
 */
final class LaporanStok
{
    public const HARI_MUKA_KEDALUWARSA = 30;

    public const HARI_SEGERA_KEDALUWARSA = 7;

    /** X6: panjang periode dasar rata-rata pemakaian untuk saran restock. */
    public const HARI_DASAR_RESTOCK = 28;

    /** Rentang pemakaian laporan slow/fast moving (hari) dan urutan kelasnya. */
    public const RENTANG_BAWAAN_PERPUTARAN = 30;

    public const RENTANG_PERPUTARAN = [30, 60, 90];

    public const KELAS_PERPUTARAN = ['Cepat', 'Normal', 'Lambat', 'Mati'];

    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly StokUntukLaporan $stok,
        private readonly ProdukUntukLaporan $produk,
        private readonly InfoProdukStok $infoProduk,
        private readonly HariLiburTerbit $hariLibur,
        private readonly PermintaanPreOrder $preOrder,
        private readonly PenilaiRencanaProduksi $penilaiProduksi,
    ) {}

    /**
     * Lokasi stok yang boleh diakses (termasuk diarsipkan), disaring satu Uuid bila diisi.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array<int, DataInfoGudang>
     */
    public function AmbilGudang(?array $idOutletBoleh, string $uuidGudang = ''): array
    {
        $hasil = [];

        foreach ($this->infoGudang->AmbilBoleh($idOutletBoleh, false) as $g) {
            if ($uuidGudang === '' || $g->uuid === $uuidGudang) {
                $hasil[$g->id] = $g;
            }
        }

        return $hasil;
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Total: array{Nilai: string, JumlahProduk: int}, PerGudang: list<array{Kunci: string, NamaGudang: string, NamaOutlet: string, JumlahProduk: int, Nilai: string}>, PerKategori: list<array{Kunci: string, NamaKategori: string, JumlahProduk: int, Nilai: string}>}
     */
    public function NilaiPersediaan(CarbonImmutable $tanggal, ?array $idOutletBoleh, string $uuidGudang = ''): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $baris = $this->stok->AmbilNilaiPadaTanggal($tanggal, array_keys($gudang));
        // F-05i: barang titipan bukan aset toko (nilainya hutang ke penitip), jadi tidak ikut nilai persediaan.
        $jenis = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($baris, 'IdProduk'))), denganTerhapus: true);
        $baris = array_values(array_filter($baris, fn (array $b): bool => ($jenis[$b['IdProduk']] ?? null)?->jenis !== JenisProduk::Konsinyasi));
        $kategori = $this->produk->AmbilKategori(array_values(array_unique(array_column($baris, 'IdProduk'))));
        $perGudang = [];
        $perKategori = [];
        $total = Uang::Nol();
        $produkTotal = [];

        foreach ($baris as $b) {
            $nilai = Uang::Dari($b['Nilai']);
            $total = $total->Tambah($nilai);
            $produkTotal[$b['IdProduk']] = true;
            $g = $gudang[$b['IdGudang']] ?? null;
            $kunciGudang = $g->uuid ?? (string) $b['IdGudang'];
            $perGudang[$kunciGudang] ??= ['Kunci' => $kunciGudang, 'NamaGudang' => $g->nama ?? '', 'NamaOutlet' => $g->namaOutlet ?? '', 'Produk' => [], 'Nilai' => Uang::Nol()];
            $perGudang[$kunciGudang]['Produk'][$b['IdProduk']] = true;
            $perGudang[$kunciGudang]['Nilai'] = $perGudang[$kunciGudang]['Nilai']->Tambah($nilai);

            $k = $kategori[$b['IdProduk']] ?? ['IdKategori' => null, 'NamaKategori' => ''];
            $kunciKategori = (string) ($k['IdKategori'] ?? 0);
            $perKategori[$kunciKategori] ??= ['Kunci' => $kunciKategori, 'NamaKategori' => $k['IdKategori'] === null ? 'Tanpa kategori' : $k['NamaKategori'], 'Produk' => [], 'Nilai' => Uang::Nol()];
            $perKategori[$kunciKategori]['Produk'][$b['IdProduk']] = true;
            $perKategori[$kunciKategori]['Nilai'] = $perKategori[$kunciKategori]['Nilai']->Tambah($nilai);
        }

        $urut = fn (array $a, array $b): int => Uang::Dari($b['Nilai'])->Bandingkan(Uang::Dari($a['Nilai']));
        $hasilGudang = array_values(array_map(fn (array $g): array => [
            'Kunci' => $g['Kunci'],
            'NamaGudang' => $g['NamaGudang'],
            'NamaOutlet' => $g['NamaOutlet'],
            'JumlahProduk' => count($g['Produk']),
            'Nilai' => $g['Nilai']->KeString(),
        ], $perGudang));
        $hasilKategori = array_values(array_map(fn (array $g): array => [
            'Kunci' => $g['Kunci'],
            'NamaKategori' => $g['NamaKategori'],
            'JumlahProduk' => count($g['Produk']),
            'Nilai' => $g['Nilai']->KeString(),
        ], $perKategori));
        usort($hasilGudang, $urut);
        usort($hasilKategori, $urut);

        return [
            'Total' => ['Nilai' => $total->KeString(), 'JumlahProduk' => count($produkTotal)],
            'PerGudang' => $hasilGudang,
            'PerKategori' => $hasilKategori,
        ];
    }

    /**
     * Stok kritis (saldo ≤ batas minimum). `batas` null = semua.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Jumlah: int, Baris: list<array{Kunci: string, UuidProduk: string, NamaProduk: string, Sku: string|null, SimbolSatuan: string, UuidGudang: string, NamaGudang: string, NamaOutlet: string, Saldo: string, StokMinimum: string, Kekurangan: string}>}
     */
    public function StokKritis(?array $idOutletBoleh, string $uuidGudang = '', ?int $batas = null): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $minimum = $this->produk->AmbilBatasMinimum(array_keys($gudang));
        $saldo = $this->stok->AmbilSaldo(array_keys($gudang), array_values(array_unique(array_column($minimum, 'IdProduk'))));
        $kritis = [];

        foreach ($minimum as $m) {
            $jumlah = Kuantitas::Dari($saldo["{$m['IdProduk']}|{$m['IdGudang']}"]['Jumlah'] ?? '0');
            $min = Kuantitas::Dari($m['StokMinimum']);

            if ($jumlah->Bandingkan($min) <= 0) {
                $kritis[] = [$m, $jumlah, $min];
            }
        }

        usort($kritis, fn (array $a, array $b): int => $b[2]->Kurangi($b[1])->Bandingkan($a[2]->Kurangi($a[1])) ?: $a[0]['IdProduk'] <=> $b[0]['IdProduk']);
        $jumlahKritis = count($kritis);

        if ($batas !== null) {
            $kritis = array_slice($kritis, 0, $batas);
        }

        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (array $k): int => $k[0]['IdProduk'], $kritis))));
        $baris = [];

        foreach ($kritis as [$m, $jumlah, $min]) {
            $p = $info[$m['IdProduk']] ?? null;
            $g = $gudang[$m['IdGudang']] ?? null;
            $baris[] = [
                'Kunci' => ($p->uuid ?? (string) $m['IdProduk']).'-'.($g->uuid ?? (string) $m['IdGudang']),
                'UuidProduk' => $p->uuid ?? '',
                'NamaProduk' => $p->nama ?? 'Produk tidak dikenal',
                'Sku' => $p?->sku,
                'SimbolSatuan' => $p->simbolSatuan ?? '',
                'UuidGudang' => $g->uuid ?? '',
                'NamaGudang' => $g->nama ?? '',
                'NamaOutlet' => $g->namaOutlet ?? '',
                'Saldo' => $jumlah->KeString(),
                'StokMinimum' => $min->KeString(),
                'Kekurangan' => $min->Kurangi($jumlah)->KeString(),
            ];
        }

        return ['Jumlah' => $jumlahKritis, 'Baris' => $baris];
    }

    /**
     * X6 (v3.43) saran restock: rata-rata pemakaian harian `HARI_DASAR_RESTOCK` hari terakhir (sampai kemarin) per produk
     * × lokasi stok (`StokUntukLaporan::AmbilPemakaian`), saldo terkini, perkiraan hari stok habis, dan saran beli =
     * rata-rata × `$hariCakupan` − saldo (dibulatkan ke atas ke satuan bulat bila satuannya tidak desimal; minimal 0).
     * Urut dari yang paling cepat habis. Hanya produk yang dipakai pada periode dasar.
     * X6 musiman (v3.78): laju dikali faktor musim per produk × lokasi dari pemakaian periode yang sama tahun lalu
     * (`PenilaiMusimRestock`; selaras Lebaran bila periode cakupan jatuh di sekitar Ramadan/Lebaran).
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{HariDasar: int, HariCakupan: int, Musim: array{Jenis: string, SelisihHari: int, Lebaran: string|null}, Baris: list<array<string, mixed>>}
     */
    public function SaranRestock(?array $idOutletBoleh, CarbonImmutable $hariIni, string $uuidGudang = '', int $hariCakupan = 14): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $sampai = $hariIni->subDay();
        $pakai = $this->stok->AmbilPemakaian(array_keys($gudang), $sampai->subDays(self::HARI_DASAR_RESTOCK - 1), $sampai);
        $saldo = $this->stok->AmbilSaldo(array_keys($gudang), array_values(array_unique(array_column($pakai, 'IdProduk'))));
        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($pakai, 'IdProduk'))));
        $musim = PenilaiMusimRestock::TentukanPergeseran($hariIni, $hariCakupan, $this->AmbilLebaran($hariIni));
        $geser = $musim['SelisihHari'];
        $laluCakupan = $this->PetakanPakai($this->stok->AmbilPemakaian(array_keys($gudang), $hariIni->subDays($geser), $hariIni->addDays($hariCakupan - 1)->subDays($geser)));
        $laluDasar = $this->PetakanPakai($this->stok->AmbilPemakaian(array_keys($gudang), $sampai->subDays(self::HARI_DASAR_RESTOCK - 1 + $geser), $sampai->subDays($geser)));
        $baris = [];

        foreach ($pakai as $b) {
            $p = $info[$b['IdProduk']] ?? null;
            $g = $gudang[$b['IdGudang']] ?? null;

            if ($p === null || $p->jenis === JenisProduk::Konsinyasi) {
                continue;
            }

            $stok = BigDecimal::of($saldo["{$b['IdProduk']}|{$b['IdGudang']}"]['Jumlah'] ?? '0');
            $rata = BigDecimal::of($b['Pakai'])->dividedBy(self::HARI_DASAR_RESTOCK, 4, RoundingMode::HalfUp);
            $kunci = "{$b['IdProduk']}|{$b['IdGudang']}";
            $faktor = PenilaiMusimRestock::HitungFaktor($laluCakupan[$kunci] ?? '0', $hariCakupan, $laluDasar[$kunci] ?? '0', self::HARI_DASAR_RESTOCK);
            $perkiraan = $rata->multipliedBy($faktor)->toScale(4, RoundingMode::HalfUp);
            $butuh = $perkiraan->multipliedBy($hariCakupan)->minus($stok);
            $saran = $butuh->isPositive() ? $butuh->toScale($p->bolehDesimal ? 4 : 0, RoundingMode::Up) : BigDecimal::zero()->toScale(4);
            $hariHabis = $perkiraan->isZero() ? null : ($stok->isPositive() ? $stok->dividedBy($perkiraan, 0, RoundingMode::Down)->toInt() : 0);
            $baris[] = [
                'Kunci' => $p->uuid.'-'.($g->uuid ?? (string) $b['IdGudang']),
                'UuidProduk' => $p->uuid,
                'NamaProduk' => $p->nama,
                'Sku' => $p->sku,
                'SimbolSatuan' => $p->simbolSatuan,
                'UuidGudang' => $g->uuid ?? '',
                'NamaGudang' => $g->nama ?? '',
                'NamaOutlet' => $g->namaOutlet ?? '',
                'Pakai' => $b['Pakai'],
                'RataPerHari' => (string) $rata,
                'FaktorMusim' => (string) $faktor,
                'RataPerkiraan' => (string) $perkiraan,
                'Saldo' => (string) $stok->toScale(4),
                'HariHabis' => $hariHabis,
                'SaranBeli' => (string) $saran->toScale(4),
            ];
        }

        usort($baris, fn (array $a, array $b): int => ($a['HariHabis'] ?? PHP_INT_MAX) <=> ($b['HariHabis'] ?? PHP_INT_MAX) ?: strcmp((string) $a['NamaProduk'], (string) $b['NamaProduk']));

        return ['HariDasar' => self::HARI_DASAR_RESTOCK, 'HariCakupan' => $hariCakupan, 'Musim' => $musim, 'Baris' => $baris];
    }

    /**
     * Rencana produksi harian (§9.11 Bakery): untuk tiap produk jenis Produksi × lokasi stok, permintaan `$target` dari
     * rata-rata hari-dalam-minggu yang sama (`PenilaiRencanaProduksi`) dan pre-order yang diambil pada tanggal itu,
     * dibandingkan dengan saldo sekarang. Pre-order milik outlet dicatat pada lokasi stok outlet itu yang paling sering
     * memakai produknya (atau lokasi pertama bila belum ada riwayat). Pasangan tanpa riwayat, saldo, dan pre-order tidak
     * ikut. Urut rekomendasi terbesar.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Target: string, Minggu: int, Baris: list<array<string, mixed>>}
     */
    public function RencanaProduksi(?array $idOutletBoleh, CarbonImmutable $hariIni, CarbonImmutable $target, string $uuidGudang = ''): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $produksi = [];

        foreach ($this->infoProduk->AmbilSemuaBerstok() as $p) {
            if ($p->jenis === JenisProduk::Produksi) {
                $produksi[$p->id] = $p;
            }
        }

        $idProduk = array_keys($produksi);
        $dari = $target->subWeeks(PenilaiRencanaProduksi::MINGGU_DASAR);
        $sampai = $hariIni->subDay();
        $harian = $dari->greaterThan($sampai) ? [] : $this->stok->AmbilPemakaianHarian(array_keys($gudang), $idProduk, $dari, $sampai);
        $saldo = $this->stok->AmbilSaldo(array_keys($gudang), $idProduk);
        $pesanan = $this->preOrder->AmbilPerProduk($target, $idOutletBoleh);
        $pasangan = [];

        foreach (array_keys($harian) as $kunci) {
            $pasangan[$kunci] = true;
        }

        foreach ($saldo as $kunci => $s) {
            if (BigDecimal::of($s['Jumlah'])->isPositive()) {
                $pasangan[$kunci] = true;
            }
        }

        // Pre-order milik outlet → lokasi stok outlet itu yang paling banyak memakai produk tersebut.
        $preOrderPerPasangan = [];

        foreach ($pesanan as $idP => $jumlahPesanan) {
            if (! isset($produksi[$idP])) {
                continue;
            }

            $terbaik = null;
            $terbaikPakai = null;
            foreach ($gudang as $g) {
                $kunci = "{$idP}|{$g->id}";
                $pakai = array_reduce(array_values($harian[$kunci] ?? []), fn (BigDecimal $total, string $v): BigDecimal => $total->plus($v), BigDecimal::zero());

                if ($terbaikPakai === null || $pakai->isGreaterThan($terbaikPakai)) {
                    $terbaik = $kunci;
                    $terbaikPakai = $pakai;
                }
            }

            if ($terbaik !== null) {
                $pasangan[$terbaik] = true;
                $preOrderPerPasangan[$terbaik] = $jumlahPesanan;
            }
        }

        $baris = [];

        foreach (array_keys($pasangan) as $kunci) {
            [$idP, $idG] = array_map('intval', explode('|', $kunci));
            $p = $produksi[$idP] ?? null;
            $g = $gudang[$idG] ?? null;

            if ($p === null || $g === null) {
                continue;
            }

            $nilai = $this->penilaiProduksi->Hitung($harian[$kunci] ?? [], $hariIni, $target, $preOrderPerPasangan[$kunci] ?? '0', $saldo[$kunci]['Jumlah'] ?? '0', $p->bolehDesimal);
            $baris[] = [
                'Kunci' => $p->uuid.'-'.$g->uuid,
                'UuidProduk' => $p->uuid,
                'NamaProduk' => $p->nama,
                'Sku' => $p->sku,
                'SimbolSatuan' => $p->simbolSatuan,
                'UuidGudang' => $g->uuid,
                'NamaGudang' => $g->nama,
                'NamaOutlet' => $g->namaOutlet ?? '',
                ...$nilai,
            ];
        }

        usort($baris, fn (array $a, array $b): int => BigDecimal::of($b['Rekomendasi'])->compareTo(BigDecimal::of($a['Rekomendasi'])) ?: strcmp((string) $a['NamaProduk'], (string) $b['NamaProduk']));

        return ['Target' => $target->toDateString(), 'Minggu' => PenilaiRencanaProduksi::MINGGU_DASAR, 'Baris' => $baris];
    }

    /**
     * Laporan slow/fast moving (§10.10): pemakaian `$rentang` hari terakhir (sampai kemarin, sama dengan saran restock)
     * dibandingkan dengan saldo sekarang per produk × lokasi stok. `Perputaran` = pemakaian ÷ saldo. Kelas: `Cepat`
     * (≥ 1, stok habis dalam rentang itu atau saldo sudah nol/minus), `Normal` (0,25 s.d. < 1), `Lambat` (> 0 s.d. < 0,25),
     * `Mati` (ada saldo tetapi tidak ada pemakaian sama sekali). Konsinyasi tidak ikut. `NilaiTertahan` = nilai persediaan
     * kelas Lambat + Mati. Urut pemakaian terbesar; kelas Mati di akhir menurut nilai.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Rentang: int, Ringkasan: array<string, array{Jumlah: int, Nilai: string}>, NilaiTertahan: string, Baris: list<array<string, mixed>>}
     */
    public function Perputaran(?array $idOutletBoleh, CarbonImmutable $hariIni, string $uuidGudang = '', int $rentang = self::RENTANG_BAWAAN_PERPUTARAN): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $sampai = $hariIni->subDay();
        $pakai = [];

        foreach ($this->stok->AmbilPemakaian(array_keys($gudang), $sampai->subDays($rentang - 1), $sampai) as $b) {
            $pakai["{$b['IdProduk']}|{$b['IdGudang']}"] = $b['Pakai'];
        }

        $saldo = [];

        foreach ($this->stok->AmbilNilaiPadaTanggal($hariIni, array_keys($gudang)) as $b) {
            $saldo["{$b['IdProduk']}|{$b['IdGudang']}"] = $b;
        }

        $kunciSemua = array_values(array_unique([...array_keys($pakai), ...array_keys(array_filter($saldo, fn (array $b): bool => BigDecimal::of($b['Jumlah'])->isPositive()))]));
        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (string $k): int => (int) explode('|', $k)[0], $kunciSemua))));
        $ringkasan = array_fill_keys(self::KELAS_PERPUTARAN, ['Jumlah' => 0, 'Nilai' => BigDecimal::zero()]);
        $baris = [];

        foreach ($kunciSemua as $kunci) {
            [$idProduk, $idGudang] = array_map('intval', explode('|', $kunci));
            $p = $info[$idProduk] ?? null;
            $g = $gudang[$idGudang] ?? null;

            if ($p === null || $g === null || $p->jenis === JenisProduk::Konsinyasi) {
                continue;
            }

            $dipakai = BigDecimal::of($pakai[$kunci] ?? '0');
            $sisa = BigDecimal::of($saldo[$kunci]['Jumlah'] ?? '0');
            $nilai = BigDecimal::of($saldo[$kunci]['Nilai'] ?? '0');
            $rasio = $sisa->isPositive() ? $dipakai->dividedBy($sisa, 4, RoundingMode::HalfUp) : null;
            $rata = $dipakai->dividedBy($rentang, 4, RoundingMode::HalfUp);
            $kelas = match (true) {
                $dipakai->isZero() => 'Mati',
                $rasio === null || $rasio->isGreaterThanOrEqualTo(1) => 'Cepat',
                $rasio->isGreaterThanOrEqualTo('0.25') => 'Normal',
                default => 'Lambat',
            };
            $ringkasan[$kelas]['Jumlah']++;
            $ringkasan[$kelas]['Nilai'] = $ringkasan[$kelas]['Nilai']->plus($nilai);
            $baris[] = [
                'Kunci' => $p->uuid.'-'.$g->uuid,
                'UuidProduk' => $p->uuid,
                'NamaProduk' => $p->nama,
                'Sku' => $p->sku,
                'SimbolSatuan' => $p->simbolSatuan,
                'UuidGudang' => $g->uuid,
                'NamaGudang' => $g->nama,
                'NamaOutlet' => $g->namaOutlet ?? '',
                'Pakai' => (string) $dipakai->toScale(4),
                'RataPerHari' => (string) $rata,
                'Saldo' => (string) $sisa->toScale(4),
                'Nilai' => (string) $nilai->toScale(2, RoundingMode::HalfUp),
                'Perputaran' => $rasio === null ? null : (string) $rasio,
                'HariCover' => $rata->isZero() || ! $sisa->isPositive() ? null : $sisa->dividedBy($rata, 0, RoundingMode::Down)->toInt(),
                'Kelas' => $kelas,
            ];
        }

        $urutKelas = array_flip(self::KELAS_PERPUTARAN);
        usort($baris, fn (array $a, array $b): int => $urutKelas[$a['Kelas']] <=> $urutKelas[$b['Kelas']]
            ?: ($a['Kelas'] === 'Mati'
                ? BigDecimal::of($b['Nilai'])->compareTo($a['Nilai'])
                : BigDecimal::of($b['Pakai'])->compareTo($a['Pakai']))
            ?: strcmp((string) $a['NamaProduk'], (string) $b['NamaProduk']));

        return [
            'Rentang' => $rentang,
            'Ringkasan' => array_map(fn (array $r): array => ['Jumlah' => $r['Jumlah'], 'Nilai' => (string) $r['Nilai']->toScale(2, RoundingMode::HalfUp)], $ringkasan),
            'NilaiTertahan' => (string) $ringkasan['Lambat']['Nilai']->plus($ringkasan['Mati']['Nilai'])->toScale(2, RoundingMode::HalfUp),
            'Baris' => $baris,
        ];
    }

    /**
     * Hari pertama Idul Fitri per tahun (tahun lalu s.d. tahun depan) dari master hari libur nasional terbit (cuti
     * bersama sebelum Lebaran tidak dihitung).
     *
     * @return array<int, CarbonImmutable>
     */
    private function AmbilLebaran(CarbonImmutable $hariIni): array
    {
        $hasil = [];

        foreach (range($hariIni->year - 1, $hariIni->year + 1) as $tahun) {
            foreach ($this->hariLibur->AmbilTahun($tahun) as $libur) {
                if ($libur->Jenis->value === 'Nasional' && preg_match('/idul\s*fitri|idulfitri|lebaran/i', $libur->Nama) === 1) {
                    $hasil[$tahun] = CarbonImmutable::parse($libur->Tanggal->toDateString(), $hariIni->getTimezone());
                    break;
                }
            }
        }

        return $hasil;
    }

    /**
     * @param  list<array{IdProduk: int, IdGudang: int, Pakai: string}>  $pakai
     * @return array<string, string>
     */
    private function PetakanPakai(array $pakai): array
    {
        $hasil = [];

        foreach ($pakai as $b) {
            $hasil["{$b['IdProduk']}|{$b['IdGudang']}"] = $b['Pakai'];
        }

        return $hasil;
    }

    /**
     * F-05g: batch yang sudah lewat atau akan kedaluwarsa dalam `$hariMuka` hari (bawaan 30), per lokasi stok.
     * `SisaHari` negatif = sudah lewat. `Status`: `Lewat`, `Segera` (≤ 7 hari), `Mendekati`.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Jumlah: int, JumlahLewat: int, Baris: list<array{Kunci: string, UuidProduk: string, NamaProduk: string, Sku: string|null, SimbolSatuan: string, UuidGudang: string, NamaGudang: string, NamaOutlet: string, NomorBatch: string, TanggalKedaluwarsa: string, SisaHari: int, Status: string, Sisa: string}>}
     */
    public function BatchKedaluwarsa(?array $idOutletBoleh, CarbonImmutable $hariIni, string $uuidGudang = '', int $hariMuka = self::HARI_MUKA_KEDALUWARSA, ?int $batas = null): array
    {
        $gudang = $this->AmbilGudang($idOutletBoleh, $uuidGudang);
        $hasil = $this->stok->AmbilBatchMendekatiKedaluwarsa(array_keys($gudang), $hariIni->addDays($hariMuka), $batas);
        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($hasil['Baris'], 'IdProduk'))));
        $lewat = $this->stok->AmbilBatchMendekatiKedaluwarsa(array_keys($gudang), $hariIni->subDay(), 0)['Jumlah'];
        $baris = [];

        foreach ($hasil['Baris'] as $b) {
            $p = $info[$b['IdProduk']] ?? null;
            $g = $gudang[$b['IdGudang']] ?? null;
            $sisaHari = (int) $hariIni->startOfDay()->diffInDays(CarbonImmutable::parse($b['TanggalKedaluwarsa'], $hariIni->getTimezone())->startOfDay(), false);
            $baris[] = [
                'Kunci' => ($p->uuid ?? (string) $b['IdProduk']).'-'.($g->uuid ?? (string) $b['IdGudang']).'-'.$b['NomorBatch'],
                'UuidProduk' => $p->uuid ?? '',
                'NamaProduk' => $p->nama ?? 'Produk tidak dikenal',
                'Sku' => $p?->sku,
                'SimbolSatuan' => $p->simbolSatuan ?? '',
                'UuidGudang' => $g->uuid ?? '',
                'NamaGudang' => $g->nama ?? '',
                'NamaOutlet' => $g->namaOutlet ?? '',
                'NomorBatch' => $b['NomorBatch'],
                'TanggalKedaluwarsa' => $b['TanggalKedaluwarsa'],
                'SisaHari' => $sisaHari,
                'Status' => $sisaHari < 0 ? 'Lewat' : ($sisaHari <= self::HARI_SEGERA_KEDALUWARSA ? 'Segera' : 'Mendekati'),
                'Sisa' => $b['JumlahSisa'],
            ];
        }

        return ['Jumlah' => $hasil['Jumlah'], 'JumlahLewat' => $lewat, 'Baris' => $baris];
    }
}
