<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Kueri\ShiftPerTanggal;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use Carbon\CarbonImmutable;

/**
 * Laporan ringkas Aplikasi Owner (OWN-05): penjualan per produk/kategori/kasir/jam/kanal dan laporan shift & selisih kas.
 * Angka diambil dari laporan penjualan back-office F-14a (`LaporanPenjualan`, `AgregatPenjualan`), sehingga definisinya
 * sama: `Omzet` = penjualan bersih. `Jumlah` = kuantitas terjual (Produk, Kategori) atau jumlah transaksi (Kasir, Jam,
 * Kanal), sebagai string desimal. Urut omzet menurun, kecuali Jam (urut jam lokal outlet).
 */
final class LaporanRingkasPemilik
{
    public const KELOMPOK = ['Produk', 'Kategori', 'Kasir', 'Jam', 'Kanal'];

    public function __construct(
        private readonly AgregatPenjualan $agregat,
        private readonly LaporanPenjualan $laporan,
        private readonly ShiftPerTanggal $shift,
        private readonly PetaUuidOutlet $outlet,
        private readonly AnggotaOutlet $anggota,
    ) {}

    /**
     * OWN-09: skor risiko kasir (void tunai cepat, void/diskon/retur terhadap rata-rata, buka laci, kas kurang) yang
     * sama persis dengan tab Anti-fraud laporan penjualan; urut skor menurun, tiap skor membawa alasan berteks.
     *
     * @return list<array<string, mixed>>
     */
    public function AntiFraud(DataSaringLaporanPenjualan $saring): array
    {
        return $this->laporan->AntiFraud($saring);
    }

    /**
     * @return array{Kelompok: string, Baris: list<array{Nama: string, Jumlah: string, Omzet: string}>, Total: array{Jumlah: string, Omzet: string}}
     */
    public function Penjualan(string $kelompok, DataSaringLaporanPenjualan $saring): array
    {
        $baris = match ($kelompok) {
            'Produk' => array_map(fn (array $p): array => ['Nama' => $p['NamaProduk'], 'Jumlah' => $p['Qty'], 'Omzet' => $p['Bersih']], $this->agregat->PerProduk($saring)),
            'Kategori' => array_map(fn (array $k): array => ['Nama' => self::Teks($k['NamaKategori']), 'Jumlah' => self::Teks($k['Qty']), 'Omzet' => self::Teks($k['Bersih'])], $this->laporan->PerKategori($saring)),
            'Kasir' => array_map(fn (array $k): array => ['Nama' => self::Teks($k['NamaKasir']), 'Jumlah' => self::Teks($k['JumlahTransaksi']), 'Omzet' => self::Teks($k['Bersih'])], $this->laporan->PerKasir($saring)),
            'Kanal' => array_map(fn (array $k): array => ['Nama' => self::Teks($k['LabelKanal']), 'Jumlah' => self::Teks($k['JumlahTransaksi']), 'Omzet' => self::Teks($k['Bersih'])], $this->laporan->PerKanal($saring)),
            default => array_map(fn (array $j): array => ['Nama' => (string) $j['Jam'], 'Jumlah' => (string) $j['JumlahTransaksi'], 'Omzet' => $j['Bersih']], $this->laporan->PerJam($saring)['PerJam']),
        };

        if ($kelompok !== 'Jam') {
            usort($baris, fn (array $a, array $b): int => Uang::Dari($b['Omzet'])->Bandingkan(Uang::Dari($a['Omzet'])) ?: strcmp($a['Nama'], $b['Nama']));
        }

        $jumlah = Kuantitas::Nol();
        $omzet = Uang::Nol();

        foreach ($baris as $b) {
            $jumlah = $jumlah->Tambah(Kuantitas::Dari($b['Jumlah']));
            $omzet = $omzet->Tambah(Uang::Dari($b['Omzet']));
        }

        $jumlahTeks = in_array($kelompok, ['Produk', 'Kategori'], true) ? $jumlah->KeString() : (string) array_sum(array_map(fn (array $b): int => (int) $b['Jumlah'], $baris));

        return ['Kelompok' => $kelompok, 'Baris' => $baris, 'Total' => ['Jumlah' => $jumlahTeks, 'Omzet' => $omzet->KeString()]];
    }

    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet
     * @return list<array{Uuid: string, Outlet: string, Kasir: string, DibukaPada: string, DitutupPada: string|null, Status: string, Selisih: string|null}>
     */
    public function Shift(CarbonImmutable $tanggal, ?array $idOutlet): array
    {
        $shift = $this->shift->Ambil($tanggal, $idOutlet);
        $namaOutlet = array_column($this->outlet->AmbilRingkas(array_values(array_unique(array_column($shift, 'IdOutlet')))), 'Nama', 'Id');
        $namaKasir = $this->anggota->AmbilNama(array_column($shift, 'DibukaOleh'));

        return array_map(fn (array $s): array => [
            'Uuid' => $s['Uuid'],
            'Outlet' => $namaOutlet[$s['IdOutlet']] ?? '',
            'Kasir' => $namaKasir[$s['DibukaOleh']]['Nama'] ?? '',
            'DibukaPada' => $s['DibukaPada'],
            'DitutupPada' => $s['DitutupPada'],
            'Status' => $s['Status'],
            'Selisih' => $s['Selisih'],
        ], $shift);
    }

    private static function Teks(mixed $nilai): string
    {
        return is_string($nilai) || is_int($nilai) ? (string) $nilai : '';
    }
}
