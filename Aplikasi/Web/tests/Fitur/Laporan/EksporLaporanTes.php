<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Laporan\BantuanLaporan;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

/** @return array<string, string> isi berkas .xlsx hasil unduhan, per nama bagian */
function BacaUnduhanXlsx(string $isi): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-uji-');
    file_put_contents((string) $path, $isi);
    $zip = new ZipArchive;
    expect($zip->open((string) $path))->toBeTrue();
    $bagian = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $bagian[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink((string) $path);

    return $bagian;
}

describe('Kop laporan seragam (D-43): laporan penjualan', function (): void {
    it('Excel tab detail: kop usaha, filter, ringkasan, header, dan baris per item dengan nilai asli', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $respons = $this->get('/kelola/laporan/penjualan/ekspor?tab=detail&dari=2026-10-01&sampai=2026-10-07&format=xlsx')->assertOk();
        expect($respons->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
            ->and($respons->headers->get('Content-Disposition'))->toContain('laporan-penjualan-detail-2026-10-01-2026-10-07.xlsx');

        $bagian = BacaUnduhanXlsx($respons->streamedContent());
        $sheet = $bagian['xl/worksheets/sheet1.xml'];

        expect($sheet)->toContain('Detail Penjualan')
            ->and($sheet)->toContain('Semua Outlet')
            ->and($sheet)->toContain('01 Oktober 2026 - 07 Oktober 2026')
            ->and($sheet)->toContain('Asia/Jakarta (GMT +7)')
            ->and($sheet)->toContain('Penjualan Bersih')
            ->and($sheet)->toContain('<v>83150.00</v>')
            ->and($sheet)->toContain('No Transaksi')
            ->and($sheet)->toContain('Metode Pembayaran')
            ->and($sheet)->toContain(htmlspecialchars($d['Minyak']->Nama, ENT_XML1))
            ->and($sheet)->toContain('Dibuat dengan Payoung');
        expect($bagian['xl/workbook.xml'])->toContain('name="Detail Penjualan"');
    });

    it('CSV (bawaan tanpa parameter) tab detail: header dan baris per item. Sama dengan format=csv', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $bawaan = $this->get('/kelola/laporan/penjualan/ekspor?tab=detail&dari=2026-10-01&sampai=2026-10-07')->assertOk()->streamedContent();
        $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=detail&dari=2026-10-01&sampai=2026-10-07&format=csv')->assertOk()->streamedContent();

        expect($bawaan)->toBe($csv)
            ->and($csv)->toStartWith("\xEF\xBB\xBF\"No Transaksi\",\"Waktu Transaksi\",Outlet,\"Jenis Order\",\"Nama Produk\",Quantity,\"Harga Satuan\",Kotor,Diskon,Pajak,Total,\"Metode Pembayaran\",Kasir,Catatan")
            ->and($csv)->toContain($d['Minyak']->Nama);
    });

    it('format cetak merender halaman Cetak dengan kop, ringkasan terformat Indonesia, dan baris', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get('/kelola/laporan/penjualan/ekspor?tab=harian&dari=2026-10-01&sampai=2026-10-07&format=cetak')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Laporan/Cetak')
                ->where('Judul', 'Laporan Penjualan | Ringkasan Harian')
                ->where('Cakupan', 'Semua Outlet')
                ->where('Terpotong', false)
                ->where('Ringkasan.3.Label', 'Penjualan Bersih')
                ->where('Ringkasan.3.Nilai', 'Rp 83.150')
                ->where('Kolom.0.Judul', 'Tanggal')
                ->has('Baris')
                ->where('Jumlah.0', 'Jumlah'));
    });

    it('tab detail di halaman: TabelData mode server dengan cari, urut, dan halaman. Tenant lain tidak terlihat', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get('/kelola/laporan/penjualan?tab=detail&dari=2026-10-01&sampai=2026-10-07')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Laporan/Penjualan')
                ->where('Saring.Tab', 'detail')
                ->where('Isi.Meta.Halaman', 1)
                ->has('Isi.Data.0', fn (AssertableInertia $b) => $b
                    ->hasAll(['Id', 'Nomor', 'Waktu', 'NamaOutlet', 'Kanal', 'NamaProduk', 'Qty', 'HargaSatuan', 'Kotor', 'Diskon', 'Pajak', 'Total', 'Metode', 'NamaKasir', 'Catatan'])
                    ->etc()));

        $cari = $this->getJson('/kelola/laporan/penjualan?tab=detail&dari=2026-10-01&sampai=2026-10-07&cari='.rawurlencode($d['Minyak']->Nama))->assertOk();
        expect($cari->json('Meta.Total'))->toBeGreaterThan(0);
        foreach ($cari->json('Data') as $baris) {
            expect($baris['NamaProduk'])->toBe($d['Minyak']->Nama);
        }

        $kosong = $this->getJson('/kelola/laporan/penjualan?tab=detail&dari=2026-10-01&sampai=2026-10-07&cari=tidak-ada-produk-ini')->assertOk();
        expect($kosong->json('Meta.Total'))->toBe(0)->and($kosong->json('Data'))->toBe([]);

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $lain = $this->getJson('/kelola/laporan/penjualan?tab=detail&dari=2026-10-01&sampai=2026-10-07')->assertOk();
        expect($lain->json('Meta.Total'))->toBe(0);
    });

    it('ekspor Excel tetap hanya untuk yang berhak: Kasir 403 di semua format', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);

        foreach (['xlsx', 'csv', 'cetak'] as $format) {
            $this->get("/kelola/laporan/penjualan/ekspor?tab=detail&format={$format}")->assertForbidden();
        }
    });
});

describe('Kop laporan seragam (D-43): umur piutang, umur hutang, komisi', function (): void {
    it('tiga laporan bisa diunduh sebagai Excel & CSV berkop usaha, tanpa data pun tetap berheader', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $daftar = [
            '/kelola/piutang/ekspor' => ['Laporan Umur Piutang', 'Nomor penjualan'],
            '/kelola/pembelian/hutang/ekspor' => ['Laporan Umur Hutang', 'Nomor faktur pemasok'],
            '/kelola/karyawan/komisi/laporan/ekspor' => ['Laporan Komisi', 'Komisi bersih'],
        ];

        foreach ($daftar as $alamat => [$judul, $kolom]) {
            $xlsx = BacaUnduhanXlsx($this->get("{$alamat}?format=xlsx")->assertOk()->streamedContent());
            expect($xlsx['xl/worksheets/sheet1.xml'])->toContain($judul)->toContain($kolom)->toContain('Dibuat dengan Payoung');
            expect($this->get("{$alamat}?format=csv")->assertOk()->streamedContent())->toContain($kolom);
        }
    });

    it('Kasir tidak boleh mengunduh umur piutang, umur hutang, maupun komisi', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);

        foreach (['/kelola/piutang/ekspor', '/kelola/pembelian/hutang/ekspor', '/kelola/karyawan/komisi/laporan/ekspor'] as $alamat) {
            $this->get($alamat)->assertForbidden();
        }
    });
});
