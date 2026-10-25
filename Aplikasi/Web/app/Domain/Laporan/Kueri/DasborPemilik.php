<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Kasir\Kueri\RingkasanShiftDasbor;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Data\DataAgregatPenjualan;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dasbor pemilik di beranda `/kelola` (F-14a, izin `laporan.penjualan.lihat`, dibatasi outlet akses). Hari berjalan
 * dihitung langsung dari dokumen; hari-hari sebelumnya dari `RingkasanPenjualanHarian` (ringan, PRD §17 "data dasbor").
 * Isi: omzet bersih, transaksi, rata-rata keranjang, dan laba kotor untuk periode terpilih (bawaan hari ini) dibanding
 * pembandingnya (satu hari: kemarin & hari yang sama minggu lalu; beberapa hari: periode sebelumnya yang sama panjang);
 * grafik (14 hari berakhir di tanggal akhir, atau seluruh rentang bila lebih dari satu hari); 5 produk terlaris
 * (7 hari berakhir di tanggal akhir, atau rentang bila lebih dari satu hari); penjualan per outlet pada periode;
 * widget Ringkasan cepat (`WidgetBeranda`, D-203: metode bayar, jenis pesanan, kategori, kasir, anti-fraud, komisi);
 * stok kritis (bila berizin `persediaan.lihat`); shift terbuka & selisih kas terbaru; jumlah penjualan `PerluTinjauan`
 * (keadaan saat ini, tidak mengikuti rentang).
 */
final class DasborPemilik
{
    private const HARI_GRAFIK = 14;

    /** Rentang terpanjang yang dilayani Beranda (selaras laporan penjualan). */
    public const MAKS_HARI = 92;

    public function __construct(
        private readonly AgregatPenjualan $agregat,
        private readonly PetaUuidOutlet $outlet,
        private readonly AnggotaOutlet $anggota,
        private readonly RingkasanShiftDasbor $shift,
        private readonly LaporanStok $laporanStok,
        private readonly WidgetBeranda $widget,
    ) {}

    /**
     * @param  CarbonImmutable  $hariIni  tanggal bisnis berjalan (batas atas rentang)
     * @param  CarbonImmutable|null  $dari  awal periode; null = hari ini
     * @param  CarbonImmutable|null  $sampai  akhir periode; null = hari ini
     * @param  list<int>|null  $idOutletBoleh
     * @param  bool  $bolehKomisi  widget komisi staf hanya untuk pemegang `karyawan.lihat`
     * @return array<string, mixed>
     */
    public function Ambil(CarbonImmutable $hariIni, ?array $idOutletBoleh, bool $bolehStok, ?CarbonImmutable $dari = null, ?CarbonImmutable $sampai = null, bool $bolehKomisi = false): array
    {
        // Semua tanggal disamakan ke tengah malam zona bawaan aplikasi: zona `$hariIni` (tanggal bisnis outlet) bisa berbeda
        // dari zona tanggal query, dan selisih hari antar-zona akan meleset sehari.
        $hariIni = $this->TanggalSaja($hariIni);
        [$awal, $akhir] = $this->NormalkanRentang($hariIni, $dari === null ? null : $this->TanggalSaja($dari), $sampai === null ? null : $this->TanggalSaja($sampai));
        $hari = (int) $awal->diffInDays($akhir) + 1;
        $satuHari = $hari === 1;
        $saringPeriode = new DataSaringLaporanPenjualan($awal, $akhir, $idOutletBoleh);

        // Grafik: satu hari = 14 hari berakhir di hari itu; beberapa hari = seluruh rentang.
        $awalGrafik = $satuHari ? $akhir->subDays(self::HARI_GRAFIK - 1) : $awal;
        $pembandingPertama = $satuHari ? $akhir->subDay() : $awal->subDays($hari);
        $akhirPembandingPertama = $satuHari ? $pembandingPertama : $awal->subDay();
        $mingguLalu = $akhir->subDays(7);
        $paling = [$awalGrafik, $awal, $pembandingPertama, $satuHari ? $mingguLalu : $awal];
        $terawal = array_reduce($paling, fn (CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable => $b->lessThan($a) ? $b : $a, $awal);
        $perTanggal = $this->AmbilPerTanggal($terawal, $akhir, $hariIni, $idOutletBoleh);

        $grafik = [];

        for ($t = $awalGrafik; $t->lessThanOrEqualTo($akhir); $t = $t->addDay()) {
            $a = $perTanggal[$t->toDateString()] ?? DataAgregatPenjualan::Nol();
            $grafik[] = ['Tanggal' => $t->toDateString(), 'Bersih' => $a->Bersih()->KeString(), 'LabaKotor' => $a->LabaKotor()->KeString(), 'JumlahTransaksi' => $a->jumlahTransaksi];
        }

        $pembanding = $satuHari
            ? [
                ['Nama' => 'kemarin', 'Angka' => $this->Jumlahkan($perTanggal, $pembandingPertama, $akhirPembandingPertama)->KeLarik()],
                ['Nama' => 'mingguLalu', 'Angka' => $this->Jumlahkan($perTanggal, $mingguLalu, $mingguLalu)->KeLarik()],
            ]
            : [['Nama' => 'periodeSebelumnya', 'Angka' => $this->Jumlahkan($perTanggal, $pembandingPertama, $akhirPembandingPertama)->KeLarik()]];

        $outlet = $this->outlet->AmbilRingkas($idOutletBoleh);
        $perOutlet = $this->agregat->Agregasi($saringPeriode, ['Outlet']);
        $shift = $this->shift->Ambil($idOutletBoleh);
        $namaOutlet = array_column($outlet, 'Nama', 'Id');
        $namaPengguna = $this->anggota->AmbilNama(array_values(array_filter([
            ...array_column($shift['Terbuka'], 'DibukaOleh'),
            ...array_column($shift['Tertutup'], 'DitutupOleh'),
        ])));
        $awalTerlaris = $satuHari ? $akhir->subDays(6) : $awal;

        return [
            'Tanggal' => $akhir->toDateString(),
            'Periode' => [
                'Dari' => $awal->toDateString(),
                'Sampai' => $akhir->toDateString(),
                'Hari' => $hari,
                'SatuHari' => $satuHari,
                'HariIni' => $hariIni->toDateString(),
                'JumlahHariTerlaris' => (int) $awalTerlaris->diffInDays($akhir) + 1,
            ],
            'Angka' => $this->Jumlahkan($perTanggal, $awal, $akhir)->KeLarik(),
            'Pembanding' => $pembanding,
            'Grafik' => $grafik,
            'ProdukTerlaris' => array_map(fn (array $p): array => [
                'Kunci' => (string) $p['IdProduk'],
                'NamaProduk' => $p['NamaProduk'],
                'Qty' => $p['Qty'],
                'Bersih' => $p['Bersih'],
            ], $this->agregat->PerProduk(new DataSaringLaporanPenjualan($awalTerlaris, $akhir, $idOutletBoleh), batas: 5)),
            'PerOutlet' => array_map(fn (array $o): array => [
                'Kunci' => $o['Uuid'],
                'NamaOutlet' => $o['Nama'],
                'Bersih' => ($perOutlet[(string) $o['Id']]['Agregat'] ?? DataAgregatPenjualan::Nol())->Bersih()->KeString(),
                'JumlahTransaksi' => ($perOutlet[(string) $o['Id']]['Agregat'] ?? DataAgregatPenjualan::Nol())->jumlahTransaksi,
            ], $outlet),
            'Widget' => $this->widget->Ambil($saringPeriode, $idOutletBoleh, $bolehKomisi),
            'StokKritis' => $bolehStok ? $this->laporanStok->StokKritis($idOutletBoleh, batas: 5) : null,
            'Shift' => [
                'JumlahTerbuka' => $shift['JumlahTerbuka'],
                'Terbuka' => array_map(fn (array $s): array => [
                    'Uuid' => $s['Uuid'],
                    'NamaOutlet' => $namaOutlet[$s['IdOutlet']] ?? '',
                    'NamaKasir' => $namaPengguna[$s['DibukaOleh']]['Nama'] ?? '',
                    'DibukaPada' => $s['DibukaPada'],
                ], $shift['Terbuka']),
                'Tertutup' => array_map(fn (array $s): array => [
                    'Uuid' => $s['Uuid'],
                    'NamaOutlet' => $namaOutlet[$s['IdOutlet']] ?? '',
                    'NamaKasir' => $s['DitutupOleh'] === null ? '' : ($namaPengguna[$s['DitutupOleh']]['Nama'] ?? ''),
                    'DitutupPada' => $s['DitutupPada'],
                    'Selisih' => $s['Selisih'],
                ], $shift['Tertutup']),
            ],
            'JumlahPerluTinjauan' => $this->agregat->HitungPerluTinjauan($idOutletBoleh),
        ];
    }

    private function TanggalSaja(CarbonImmutable $tanggal): CarbonImmutable
    {
        return CarbonImmutable::parse($tanggal->toDateString())->startOfDay();
    }

    /**
     * Rentang yang sah: bawaan hari ini; akhir tidak melewati hari ini; awal tidak setelah akhir; paling lama
     * `MAKS_HARI` hari (awal dimajukan, bukan ditolak, supaya tautan lama tetap terbuka).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function NormalkanRentang(CarbonImmutable $hariIni, ?CarbonImmutable $dari, ?CarbonImmutable $sampai): array
    {
        $akhir = ($sampai ?? $dari ?? $hariIni)->startOfDay();
        $akhir = $akhir->greaterThan($hariIni) ? $hariIni : $akhir;
        $awal = ($dari ?? $akhir)->startOfDay();
        $awal = $awal->greaterThan($akhir) ? $akhir : $awal;
        $terjauh = $akhir->subDays(self::MAKS_HARI - 1);

        return [$awal->lessThan($terjauh) ? $terjauh : $awal, $akhir];
    }

    /**
     * Agregat per tanggal: hari berjalan dari dokumen, hari sebelumnya dari tabel ringkasan.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, DataAgregatPenjualan>
     */
    private function AmbilPerTanggal(CarbonImmutable $dari, CarbonImmutable $sampai, CarbonImmutable $hariIni, ?array $idOutletBoleh): array
    {
        $perTanggal = $this->AmbilRingkasan($dari, $sampai->lessThan($hariIni) ? $sampai : $hariIni->subDay(), $idOutletBoleh);

        if ($dari->lessThanOrEqualTo($hariIni) && $sampai->greaterThanOrEqualTo($hariIni)) {
            $perTanggal[$hariIni->toDateString()] = $this->agregat->Total(new DataSaringLaporanPenjualan($hariIni, $hariIni, $idOutletBoleh));
        }

        return $perTanggal;
    }

    /**
     * @param  array<string, DataAgregatPenjualan>  $perTanggal
     */
    private function Jumlahkan(array $perTanggal, CarbonImmutable $dari, CarbonImmutable $sampai): DataAgregatPenjualan
    {
        $total = DataAgregatPenjualan::Nol();

        for ($t = $dari; $t->lessThanOrEqualTo($sampai); $t = $t->addDay()) {
            $total = $total->Tambah($perTanggal[$t->toDateString()] ?? DataAgregatPenjualan::Nol());
        }

        return $total;
    }

    /**
     * Agregat per tanggal dari tabel ringkasan (semua outlet yang boleh digabung).
     *
     * @param  list<int>|null  $idOutlet
     * @return array<string, DataAgregatPenjualan>
     */
    private function AmbilRingkasan(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutlet): array
    {
        $hasil = [];

        if ($idOutlet === [] || $dari->greaterThan($sampai)) {
            return $hasil;
        }

        $baris = RingkasanPenjualanHarian::query()
            ->whereBetween('TanggalBisnis', [$dari->toDateString(), $sampai->toDateString()])
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->get();

        foreach ($baris as $b) {
            $tanggal = $b->TanggalBisnis->toDateString();
            $agregat = LaporanPenjualan::DariRingkasan($b);
            $hasil[$tanggal] = isset($hasil[$tanggal]) ? $hasil[$tanggal]->Tambah($agregat) : $agregat;
        }

        return $hasil;
    }
}
