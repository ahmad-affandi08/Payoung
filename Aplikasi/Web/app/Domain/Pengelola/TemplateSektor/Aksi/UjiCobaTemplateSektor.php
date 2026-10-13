<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TemplateSektor\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\KesiapanPeranAkun;
use App\Domain\Akuntansi\Kueri\LabaRugi;
use App\Domain\Akuntansi\Kueri\NeracaSaldo;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\SiapkanOrganisasiAwal;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Aksi\TerapkanTemplateSektor;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Penjualan\Aksi\SiapkanMetodePembayaranBawaan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * P-03 langkah 4 (pratinjau sandbox): menerapkan satu versi template (boleh draf) ke **tenant uji sementara**, lalu
 * memeriksa bahwa hasilnya benar-benar bisa dipakai: semua peran akun terpetakan, transaksi contoh (penjualan tunai
 * dengan PPN, HPP, pembelian kredit) terposting seimbang lewat pemetaan template, neraca saldo seimbang, dan laba rugi
 * menghitung angka yang diharapkan.
 *
 * Semuanya berjalan di satu transaksi database yang **selalu dibatalkan** (tidak ada tenant, akun, jurnal, atau audit
 * tenant yang tertinggal) dan tidak menyentuh tenant sungguhan; hanya audit konsol (`template-sektor.uji-coba`) yang
 * dicatat sesudahnya. Efek samping di luar database (antrean, email) tidak terjadi karena semua penangannya berjalan
 * sesudah commit.
 */
final class UjiCobaTemplateSektor
{
    private const PENJUALAN = '100000';

    private const PPN = '11000';

    private const HPP = '40000';

    private const PEMBELIAN = '60000';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly SiapkanOrganisasiAwal $siapkanOrganisasi,
        private readonly SiapkanMetodePembayaranBawaan $siapkanMetode,
        private readonly TerapkanTemplateSektor $terapkan,
        private readonly PembacaIsiTemplate $pembaca,
        private readonly KesiapanPeranAkun $kesiapan,
        private readonly PostingJurnal $posting,
        private readonly NeracaSaldo $neracaSaldo,
        private readonly LabaRugi $labaRugi,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return array{Lolos: bool, Periksa: list<array{Kunci: string, Label: string, Lolos: bool, Keterangan: string}>}
     */
    public function Jalankan(TemplateSektorVersi $versi): array
    {
        $versi->loadMissing('TemplateSektor');
        $sebelumnya = $this->konteks->Ambil();
        $periksa = [];

        DB::beginTransaction();

        try {
            $periksa = $this->Uji($versi);
        } catch (Throwable $galat) {
            $periksa[] = self::Hasil('Penerapan', 'Penerapan ke tenant baru', false, $galat->getMessage());
        } finally {
            DB::rollBack();
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }

        $lolos = array_reduce($periksa, fn (bool $lalu, array $p): bool => $lalu && $p['Lolos'], true);
        $this->audit->Catat('template-sektor.uji-coba', $versi, nilaiBaru: [
            'Template' => $versi->TemplateSektor->Kode,
            'Versi' => $versi->Versi,
            'Lolos' => $lolos,
            'Gagal' => array_values(array_map(fn (array $p): string => $p['Kunci'], array_filter($periksa, fn (array $p): bool => ! $p['Lolos']))),
        ]);

        return ['Lolos' => $lolos, 'Periksa' => $periksa];
    }

    /**
     * @return list<array{Kunci: string, Label: string, Lolos: bool, Keterangan: string}>
     */
    private function Uji(TemplateSektorVersi $versi): array
    {
        $kode = $versi->TemplateSektor->Kode;
        $tenant = Tenant::query()->create(['Nama' => 'Uji coba '.$kode, 'Slug' => 'uji-coba-'.Str::lower(Str::random(10))]);
        $outlet = $this->siapkanOrganisasi->Jalankan($tenant->Id, $tenant->Nama, $tenant->ZonaWaktu);
        $this->konteks->Atur($tenant->Id);
        $this->siapkanMetode->Jalankan($tenant->Id);
        $hasil = $this->terapkan->Jalankan($outlet, $kode, [], $versi);
        $isi = $this->pembaca->Baca($versi->Isi);
        $periksa = [];

        $periksa[] = self::Hasil('Penerapan', 'Penerapan ke tenant baru', true, sprintf(
            '%d akun, %d pemetaan, %d kategori, %d satuan, %d kelompok pajak, %d fitur, %d stasiun dapur ditambahkan.',
            $hasil->jumlahAkun, $hasil->jumlahPemetaan, $hasil->jumlahKategori, $hasil->jumlahSatuan, $hasil->jumlahKelompokPajak, $hasil->jumlahFitur, $hasil->jumlahStasiunDapur,
        ));

        $kesiapan = $this->kesiapan->Periksa(PeranAkun::cases(), $outlet->Id);
        $periksa[] = self::Hasil('PeranAkun', 'Semua peran akun terpetakan', $kesiapan['Siap'], $kesiapan['Siap']
            ? count(PeranAkun::cases()).' peran akun punya akun dengan tipe yang sesuai.'
            : 'Belum terpetakan: '.implode(', ', array_column($kesiapan['PeranBelumDipetakan'], 'Label')).'.');

        if (! $kesiapan['Siap']) {
            return $periksa;
        }

        $periksa[] = $this->PeriksaJurnal($outlet);
        $periksa[] = $this->PeriksaLaporan($outlet);

        $mentah = is_array($versi->Isi['ProdukContoh'] ?? null) ? count($versi->Isi['ProdukContoh']) : 0;
        $terbaca = count($isi->produkContoh);
        $periksa[] = self::Hasil('ProdukContoh', 'Produk contoh terbaca', $terbaca === $mentah, $terbaca === $mentah
            ? "{$terbaca} produk contoh sah."
            : ($mentah - $terbaca).' dari '.$mentah.' produk contoh dilewati karena harga, satuan, atau jenisnya tidak sah atau namanya ganda.');

        return $periksa;
    }

    /**
     * @return array{Kunci: string, Label: string, Lolos: bool, Keterangan: string}
     */
    private function PeriksaJurnal(Outlet $outlet): array
    {
        $tanggal = CarbonImmutable::now('Asia/Jakarta');
        $jumlah = fn (string $nilai): Uang => Uang::Dari($nilai);
        $contoh = [
            [JenisSumberJurnal::Penjualan, 'Penjualan tunai dengan PPN', [
                DataBarisJurnal::Debit(PeranAkun::KasOutlet, $jumlah(self::PENJUALAN)->Tambah($jumlah(self::PPN)), $outlet->Id),
                DataBarisJurnal::Kredit(PeranAkun::Penjualan, $jumlah(self::PENJUALAN), $outlet->Id),
                DataBarisJurnal::Kredit(PeranAkun::PpnKeluaran, $jumlah(self::PPN), $outlet->Id),
            ]],
            [JenisSumberJurnal::Penjualan, 'HPP penjualan', [
                DataBarisJurnal::Debit(PeranAkun::Hpp, $jumlah(self::HPP), $outlet->Id),
                DataBarisJurnal::Kredit(PeranAkun::PersediaanBarangDagang, $jumlah(self::HPP), $outlet->Id),
            ]],
            [JenisSumberJurnal::PenerimaanBarang, 'Pembelian kredit', [
                DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, $jumlah(self::PEMBELIAN), $outlet->Id),
                DataBarisJurnal::Kredit(PeranAkun::HutangUsaha, $jumlah(self::PEMBELIAN), $outlet->Id),
            ]],
        ];

        foreach ($contoh as $urutan => [$jenis, $keterangan, $baris]) {
            $this->posting->Jalankan(new DataJurnal($jenis, 9000 + $urutan, null, null, $tanggal, "Uji coba: {$keterangan}", $baris, null, 'UjiCoba'));
        }

        return self::Hasil('Jurnal', 'Transaksi contoh terposting seimbang', true, 'Penjualan tunai dengan PPN, HPP, dan pembelian kredit terposting lewat pemetaan template.');
    }

    /**
     * @return array{Kunci: string, Label: string, Lolos: bool, Keterangan: string}
     */
    private function PeriksaLaporan(Outlet $outlet): array
    {
        $hari = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $saring = new SaringLaporanKeuangan($hari, $hari, null, null);
        $neraca = $this->neracaSaldo->Ambil($saring);
        $laba = $this->labaRugi->Ambil($saring)['Ringkasan'];
        $pendapatan = Uang::Dari($laba['Pendapatan']['Nilai'] ?? '0');
        $hpp = Uang::Dari($laba['Hpp']['Nilai'] ?? '0');
        $kotor = Uang::Dari($laba['LabaKotor']['Nilai'] ?? '0');
        $sesuai = $pendapatan->SamaDengan(Uang::Dari(self::PENJUALAN)) && $hpp->SamaDengan(Uang::Dari(self::HPP)) && $kotor->SamaDengan(Uang::Dari(self::PENJUALAN)->Kurangi(Uang::Dari(self::HPP)));
        $lolos = $neraca['Seimbang'] && $sesuai;

        return self::Hasil('Laporan', 'Neraca saldo dan laba rugi menghitung benar', $lolos, $lolos
            ? "Neraca saldo seimbang; pendapatan {$pendapatan->FormatRupiah()}, HPP {$hpp->FormatRupiah()}, laba kotor {$kotor->FormatRupiah()}."
            : ($neraca['Seimbang'] ? "Laba rugi tidak sesuai harapan (pendapatan {$pendapatan->FormatRupiah()}, HPP {$hpp->FormatRupiah()})." : 'Neraca saldo tidak seimbang.'));
    }

    /**
     * @return array{Kunci: string, Label: string, Lolos: bool, Keterangan: string}
     */
    private static function Hasil(string $kunci, string $label, bool $lolos, string $keterangan): array
    {
        return ['Kunci' => $kunci, 'Label' => $label, 'Lolos' => $lolos, 'Keterangan' => $keterangan];
    }
}
