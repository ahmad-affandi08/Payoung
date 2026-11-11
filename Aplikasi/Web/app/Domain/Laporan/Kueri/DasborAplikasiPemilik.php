<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Kueri\ShiftPerTanggal;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\PerangkatBermasalah;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Data\DataAgregatPenjualan;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use Carbon\CarbonImmutable;

/**
 * Dasbor Aplikasi Owner (OWN-02, `GET /api/pemilik/v1/dasbor`). Angka memakai definisi yang sama dengan dasbor
 * back-office F-14a (`AgregatPenjualan`): Omzet = penjualan bersih (tanpa void, dikurangi diskon & retur), laba kotor =
 * bersih − HPP, rata-rata = bersih / transaksi. Satu tanggal bisnis, dibandingkan dengan kemarin dan hari yang sama minggu
 * lalu. Per jam memakai jam lokal outlet (`LaporanPenjualan::PerJam`). `PerluTindakan` berisi selisih kas shift yang
 * ditutup pada tanggal itu, penjualan `PerluTinjauan` pada tanggal itu, serta (khusus hari berjalan) perangkat POS
 * aktif yang tidak terhubung > 30 menit dan jumlah stok kritis (bila berizin `persediaan.lihat`).
 */
final class DasborAplikasiPemilik
{
    public const MENIT_PERANGKAT_TIDAK_AKTIF = PerangkatBermasalah::MENIT_TIDAK_TERHUBUNG;

    public function __construct(
        private readonly AgregatPenjualan $agregat,
        private readonly LaporanPenjualan $laporan,
        private readonly PetaUuidOutlet $outlet,
        private readonly AnggotaOutlet $anggota,
        private readonly ShiftPerTanggal $shift,
        private readonly PerangkatBermasalah $perangkat,
        private readonly LaporanStok $laporanStok,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  outlet yang boleh dilihat pengguna (null = semua)
     * @param  list<int>|null  $idOutlet  outlet yang dihitung (outlet terpilih, atau sama dengan `$idOutletBoleh`)
     * @return array<string, mixed>
     */
    public function Ambil(
        CarbonImmutable $tanggal,
        ?array $idOutletBoleh,
        ?array $idOutlet,
        bool $bolehKeuangan,
        bool $bolehStok,
        bool $hariBerjalan,
    ): array {
        $tanggal = $tanggal->startOfDay();
        $saring = new DataSaringLaporanPenjualan($tanggal, $tanggal, $idOutlet);
        $hariIni = $this->agregat->Total($saring);
        $kemarin = $this->agregat->Total(new DataSaringLaporanPenjualan($tanggal->subDay(), $tanggal->subDay(), $idOutlet));
        $mingguLalu = $this->agregat->Total(new DataSaringLaporanPenjualan($tanggal->subDays(7), $tanggal->subDays(7), $idOutlet));
        $outletBoleh = $this->outlet->AmbilRingkas($idOutletBoleh);
        $outletDihitung = $idOutlet === null ? $outletBoleh : array_values(array_filter($outletBoleh, fn (array $o): bool => in_array($o['Id'], $idOutlet, true)));
        $perOutlet = $this->agregat->Agregasi($saring, ['Outlet']);

        return [
            'Tanggal' => $tanggal->toDateString(),
            'Outlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outletBoleh),
            'Ringkasan' => [
                'Omzet' => $hariIni->Bersih()->KeString(),
                'LabaKotor' => $bolehKeuangan ? $hariIni->LabaKotor()->KeString() : null,
                'Transaksi' => $hariIni->jumlahTransaksi,
                'RataRata' => $hariIni->RataRataKeranjang()->KeString(),
                'OmzetKemarin' => $kemarin->Bersih()->KeString(),
                'OmzetMingguLalu' => $mingguLalu->Bersih()->KeString(),
            ],
            'PerOutlet' => array_map(function (array $o) use ($perOutlet): array {
                $a = $perOutlet[(string) $o['Id']]['Agregat'] ?? DataAgregatPenjualan::Nol();

                return ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama'], 'Omzet' => $a->Bersih()->KeString(), 'Transaksi' => $a->jumlahTransaksi];
            }, $outletDihitung),
            'PerJam' => array_map(fn (array $j): array => ['Jam' => $j['Jam'], 'Omzet' => $j['Bersih']], $this->laporan->PerJam($saring)['PerJam']),
            'ProdukTeratas' => array_map(fn (array $p): array => [
                'Nama' => $p['NamaProduk'],
                'Jumlah' => $p['Qty'],
                'Omzet' => $p['Bersih'],
            ], $this->agregat->PerProduk($saring, batas: 5)),
            'PerluTindakan' => $this->AmbilPerluTindakan($tanggal, $idOutlet, $outletBoleh, $bolehStok, $hariBerjalan),
        ];
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @param  list<array{Id: int, Uuid: string, Nama: string}>  $outletBoleh
     * @return list<array{Jenis: string, Judul: string, Keterangan: string}>
     */
    private function AmbilPerluTindakan(CarbonImmutable $tanggal, ?array $idOutlet, array $outletBoleh, bool $bolehStok, bool $hariBerjalan): array
    {
        $namaOutlet = array_column($outletBoleh, 'Nama', 'Id');
        $hasil = [];
        $shift = array_values(array_filter(
            $this->shift->Ambil($tanggal, $idOutlet),
            fn (array $s): bool => $s['Selisih'] !== null && ! Uang::Dari($s['Selisih'])->BernilaiNol(),
        ));
        $namaKasir = $this->anggota->AmbilNama(array_column($shift, 'DibukaOleh'));

        foreach ($shift as $s) {
            $selisih = Uang::Dari((string) $s['Selisih']);
            $nominal = $selisih->BernilaiNegatif() ? Uang::Nol()->Kurangi($selisih) : $selisih;
            $hasil[] = [
                'Jenis' => 'SelisihKas',
                'Judul' => 'Selisih kas shift '.($namaKasir[$s['DibukaOleh']]['Nama'] ?? 'kasir'),
                'Keterangan' => ($selisih->BernilaiNegatif() ? 'Kurang ' : 'Lebih ').$nominal->FormatRupiah().' di '.($namaOutlet[$s['IdOutlet']] ?? 'outlet'),
            ];
        }

        $perluTinjauan = array_sum($this->agregat->HitungPerluTinjauanPerHari($idOutlet, $tanggal, $tanggal));

        if ($perluTinjauan > 0) {
            $hasil[] = [
                'Jenis' => 'PenjualanPerluTinjauan',
                'Judul' => "{$perluTinjauan} penjualan perlu ditinjau",
                'Keterangan' => 'Penjualan dari kasir yang ditandai untuk diperiksa. Tinjau di back-office.',
            ];
        }

        if (! $hariBerjalan) {
            return $hasil;
        }

        $sekarang = CarbonImmutable::now();

        foreach ($this->perangkat->AmbilTidakTerhubung($idOutlet, $sekarang) as $p) {
            $hasil[] = [
                'Jenis' => 'PerangkatTidakAktif',
                'Judul' => "Perangkat {$p['Nama']} tidak aktif",
                'Keterangan' => PerangkatBermasalah::TulisTerakhirTerhubung($p['TerakhirAktifPada'], $sekarang).' di '.($namaOutlet[$p['IdOutlet']] ?? 'outlet'),
            ];
        }

        if ($bolehStok) {
            $jumlah = $this->laporanStok->StokKritis($idOutlet, batas: 0)['Jumlah'];

            if ($jumlah > 0) {
                $hasil[] = [
                    'Jenis' => 'StokMenipis',
                    'Judul' => "{$jumlah} stok menipis",
                    'Keterangan' => 'Stok produk sudah mencapai batas minimum. Segera pesan ulang ke pemasok.',
                ];
            }
        }

        return $hasil;
    }
}
