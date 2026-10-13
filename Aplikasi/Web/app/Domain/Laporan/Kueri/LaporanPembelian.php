<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Laporan\Data\DataPeriodeLaporan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pembelian\Kueri\AgregatPembelian;
use App\Domain\Pembelian\Kueri\PetaNamaPembelian;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Laporan pembelian back-office (§10.10 "Pembelian": per pemasok, per produk, PO outstanding;
 * `/kelola/laporan/pembelian`, izin `pembelian.kelola`, dibatasi outlet akses). Saring: `tab`, `dari`/`sampai`
 * (bawaan 30 hari, maks. 92 hari), `outlet` (Uuid). Tab PO outstanding menampilkan keadaan saat ini (bukan periode).
 * Hutang & umur hutang sudah ada di `Pembelian › Hutang pemasok`. Angka dari `AgregatPembelian`.
 */
final class LaporanPembelian
{
    public const TAB = ['pemasok', 'produk', 'po'];

    public const BATAS_PRODUK = 200;

    public const BATAS_EKSPOR_PRODUK = 5000;

    public function __construct(
        private readonly AgregatPembelian $agregat,
        private readonly PetaNamaPembelian $nama,
        private readonly PetaUuidOutlet $outlet,
    ) {}

    /**
     * @param  array<mixed>  $query
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, IdOutlet: list<int>|null}
     */
    public function BacaSaring(array $query, ?array $idOutletBoleh, CarbonImmutable $hariIni): array
    {
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], self::TAB, true) ? $query['tab'] : 'pemasok';
        $periode = DataPeriodeLaporan::Baca($query['dari'] ?? null, $query['sampai'] ?? null, $hariIni, 30);
        $uuidOutlet = is_string($query['outlet'] ?? null) ? $query['outlet'] : '';
        $idOutlet = $idOutletBoleh;

        if ($uuidOutlet !== '') {
            $id = $this->outlet->AmbilIdDariUuid([$uuidOutlet])[$uuidOutlet] ?? null;
            $idOutlet = $id !== null && ($idOutletBoleh === null || in_array($id, $idOutletBoleh, true)) ? [$id] : [];
        }

        return ['Tab' => $tab, 'Periode' => $periode, 'UuidOutlet' => $uuidOutlet, 'IdOutlet' => $idOutlet];
    }

    /**
     * Props halaman Inertia `Kelola/Laporan/Pembelian`.
     *
     * @param  array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, IdOutlet: list<int>|null}  $saring
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>
     */
    public function AmbilHalaman(array $saring, ?array $idOutletBoleh, CarbonImmutable $hariIni): array
    {
        return [
            'Saring' => [
                'Tab' => $saring['Tab'],
                'Dari' => $saring['Periode']->dari->toDateString(),
                'Sampai' => $saring['Periode']->sampai->toDateString(),
                'Outlet' => $saring['UuidOutlet'],
            ],
            'Peringatan' => $saring['Periode']->peringatan,
            'MaksHari' => DataPeriodeLaporan::MAKS_HARI,
            'OpsiOutlet' => array_map(fn (array $o): array => ['Nilai' => $o['Uuid'], 'Label' => $o['Nama']], $this->outlet->AmbilRingkas($idOutletBoleh)),
            'Isi' => $this->AmbilIsiTab($saring, $hariIni),
        ];
    }

    /**
     * @param  array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, IdOutlet: list<int>|null}  $saring
     * @return list<array<string, mixed>>
     */
    public function AmbilIsiTab(array $saring, CarbonImmutable $hariIni, int $batasProduk = self::BATAS_PRODUK): array
    {
        if ($saring['IdOutlet'] === []) {
            return [];
        }

        return match ($saring['Tab']) {
            'produk' => $this->PerProduk($saring, $batasProduk),
            'po' => $this->PesananOutstanding($saring, $hariIni),
            default => $this->PerPemasok($saring),
        };
    }

    /**
     * @param  array{Periode: DataPeriodeLaporan, IdOutlet: list<int>|null}  $saring
     * @return list<array<string, mixed>>
     */
    public function PerPemasok(array $saring): array
    {
        $baris = $this->agregat->PerPemasok($saring['Periode']->dari, $saring['Periode']->sampai, $saring['IdOutlet']);
        $pemasok = $this->nama->Pemasok(array_column($baris, 'IdPemasok'));
        $hasil = [];

        foreach ($baris as $b) {
            $p = $b['IdPemasok'] === null ? null : ($pemasok[$b['IdPemasok']] ?? null);
            $hasil[] = [
                'Kunci' => $p['Uuid'] ?? '',
                'KodePemasok' => $p['Kode'] ?? '',
                'NamaPemasok' => $b['IdPemasok'] === null ? 'Tanpa pemasok' : ($p['Nama'] ?? 'Pemasok tidak dikenal'),
                'JumlahFaktur' => $b['JumlahFaktur'],
                'Subtotal' => $b['Subtotal'],
                'Ongkir' => $b['Ongkir'],
                'Pajak' => $b['Pajak'],
                'Total' => $b['Total'],
                'Retur' => $b['Retur'],
                'Bersih' => $b['Bersih'],
                'Dibayar' => $b['Dibayar'],
                'SisaHutang' => $b['SisaHutang'],
            ];
        }

        usort($hasil, fn (array $a, array $b): int => BigDecimal::of($b['Bersih'])->compareTo($a['Bersih']) ?: strcmp($a['NamaPemasok'], $b['NamaPemasok']));

        return $hasil;
    }

    /**
     * @param  array{Periode: DataPeriodeLaporan, IdOutlet: list<int>|null}  $saring
     * @return list<array<string, mixed>>
     */
    public function PerProduk(array $saring, int $batas = self::BATAS_PRODUK): array
    {
        return array_map(fn (array $b): array => [
            'Kunci' => (string) $b['IdProduk'],
            ...array_diff_key($b, ['IdProduk' => 0]),
        ], $this->agregat->PerProduk($saring['Periode']->dari, $saring['Periode']->sampai, $saring['IdOutlet'], $batas));
    }

    /**
     * @param  array{IdOutlet: list<int>|null}  $saring
     * @return list<array<string, mixed>>
     */
    public function PesananOutstanding(array $saring, CarbonImmutable $hariIni): array
    {
        $baris = $this->agregat->PesananOutstanding($hariIni, $saring['IdOutlet']);
        $pemasok = $this->nama->Pemasok(array_column($baris, 'IdPemasok'));

        return array_map(fn (array $b): array => [
            'Kunci' => $b['Uuid'],
            'NamaPemasok' => $pemasok[$b['IdPemasok']]['Nama'] ?? 'Pemasok tidak dikenal',
            ...array_diff_key($b, ['IdPemasok' => 0]),
        ], $baris);
    }

    /**
     * Kolom dan baris ekspor satu tab (D-43).
     *
     * @param  array{Tab: string, Periode: DataPeriodeLaporan, UuidOutlet: string, IdOutlet: list<int>|null}  $saring
     * @return array{0: list<KolomLaporan>, 1: list<list<string|int|null>>}
     */
    public function AmbilEkspor(array $saring, CarbonImmutable $hariIni): array
    {
        $t = static fn (string $judul, int $lebar = 0): KolomLaporan => new KolomLaporan($judul, JenisKolom::Teks, $lebar > 0 ? $lebar : null);
        $u = static fn (string $judul, bool $jumlah = true): KolomLaporan => new KolomLaporan($judul, JenisKolom::Uang, jumlahkan: $jumlah);
        $n = static fn (string $judul, bool $jumlah = true): KolomLaporan => new KolomLaporan($judul, JenisKolom::Bilangan, jumlahkan: $jumlah);
        $q = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Kuantitas);
        $ambil = fn (array $baris, array $kolom): array => array_values(array_map(fn (array $b): array => array_values(array_map(fn (string $k): string|int|null => is_string($b[$k] ?? null) || is_int($b[$k] ?? null) ? $b[$k] : null, $kolom)), $baris));

        return match ($saring['Tab']) {
            'produk' => [
                [$t('Produk', 32), $q('Qty (satuan dasar)'), $u('Nilai beli'), $u('Retur'), $u('Bersih'), $u('Harga rata-rata per satuan dasar', false), $u('Harga terendah', false), $u('Harga tertinggi', false), $n('Jumlah faktur'), $n('Jumlah pemasok', false)],
                $ambil($this->PerProduk($saring, self::BATAS_EKSPOR_PRODUK), ['NamaProduk', 'Qty', 'Nilai', 'NilaiRetur', 'Bersih', 'HargaRata', 'HargaMin', 'HargaMax', 'JumlahFaktur', 'JumlahPemasok']),
            ],
            'po' => [
                [$t('No PO', 22), $t('Pemasok', 28), $t('Status', 18), new KolomLaporan('Tanggal PO', JenisKolom::Tanggal), new KolomLaporan('Perkiraan tiba', JenisKolom::Tanggal), $n('Umur (hari)', false), $n('Terlambat (hari)', false), $n('Baris belum lengkap', false), $u('Total PO'), $u('Nilai sisa')],
                array_values(array_map(fn (array $b): array => [$b['Nomor'], $b['NamaPemasok'], $b['LabelStatus'], new \DateTimeImmutable($b['Tanggal']), $b['PerkiraanTiba'] === null ? null : new \DateTimeImmutable($b['PerkiraanTiba']), $b['UmurHari'], $b['HariTerlambat'], $b['JumlahBarisSisa'], $b['Total'], $b['NilaiSisa']], $this->PesananOutstanding($saring, $hariIni))),
            ],
            default => [
                [$t('Pemasok', 30), $t('Kode', 14), $n('Jumlah faktur'), $u('DPP'), $u('Ongkir'), $u('Pajak'), $u('Total faktur'), $u('Retur'), $u('Pembelian bersih'), $u('Dibayar'), $u('Sisa hutang')],
                $ambil($this->PerPemasok($saring), ['NamaPemasok', 'KodePemasok', 'JumlahFaktur', 'Subtotal', 'Ongkir', 'Pajak', 'Total', 'Retur', 'Bersih', 'Dibayar', 'SisaHutang']),
            ],
        };
    }
}
