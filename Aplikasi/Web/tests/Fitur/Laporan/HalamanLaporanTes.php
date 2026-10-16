<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Persediaan\Model\BatchStok;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Laporan\BantuanLaporan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    // Prasyarat pendaftaran (dokumen legal berlaku sejak "kemarin") dibuat pada tanggal tetap sebelum semua tanggal
    // skenario, agar tidak bergantung pada tanggal saat test dijalankan.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    // Rabu 7 Oktober 2026 pukul 12.00 WIB (tanggal bisnis = tanggal kalender lokal).
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

describe('F-14a dasbor pemilik di beranda /kelola', function (): void {
    it('hari ini (langsung dari dokumen) vs kemarin & minggu lalu (dari ringkasan), laba kotor, rata-rata keranjang, grafik 14 hari, produk terlaris, per outlet, shift terbuka, stok kritis', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 05:00:00', 'UTC'));
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        ProdukGudang::query()->create(['IdProduk' => $minyak->Id, 'IdGudang' => $k['Gudang']->Id, 'StokMinimum' => '6']);
        $jual = fn (string $jumlah) => BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => $jumlah, 'Harga' => '38500.00']]]);
        $jual('1');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
        $jual('2');
        $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
        $jual('1');
        $perluTinjauan = Penjualan::query()->where('PerluTinjauan', true)->count();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Beranda')
            ->where('Dasbor.Tanggal', '2026-10-07')
            ->where('Dasbor.Angka.Bersih', '38500.00')
            ->where('Dasbor.Angka.LabaKotor', '8500.00')
            ->where('Dasbor.Angka.JumlahTransaksi', 1)
            ->where('Dasbor.Angka.RataRataKeranjang', '38500.00')
            ->where('Dasbor.Pembanding.0.Angka.Bersih', '77000.00')
            ->where('Dasbor.Pembanding.0.Angka.LabaKotor', '17000.00')
            ->where('Dasbor.Pembanding.0.Angka.JumlahTransaksi', 1)
            ->where('Dasbor.Pembanding.1.Angka.Bersih', '38500.00')
            ->has('Dasbor.Grafik', 14)
            ->where('Dasbor.Grafik.0.Tanggal', '2026-09-24')
            ->where('Dasbor.Grafik.6', ['Tanggal' => '2026-09-30', 'Bersih' => '38500.00', 'LabaKotor' => '8500.00', 'JumlahTransaksi' => 1])
            ->where('Dasbor.Grafik.12.Bersih', '77000.00')
            ->where('Dasbor.Grafik.13.Bersih', '38500.00')
            ->where('Dasbor.ProdukTerlaris', [['Kunci' => (string) $minyak->Id, 'NamaProduk' => $minyak->Nama, 'Qty' => '3.0000', 'Bersih' => '115500.00']])
            ->where('Dasbor.PerOutlet', [['Kunci' => $k['Outlet']->Uuid, 'NamaOutlet' => $k['Outlet']->Nama, 'Bersih' => '38500.00', 'JumlahTransaksi' => 1]])
            ->where('Dasbor.StokKritis.Jumlah', 1)
            ->where('Dasbor.StokKritis.Baris.0.Saldo', '6.0000')
            ->where('Dasbor.StokKritis.Baris.0.StokMinimum', '6.0000')
            ->where('Dasbor.Shift.JumlahTerbuka', 1)
            ->where('Dasbor.Shift.Terbuka.0.NamaKasir', $k['Kasir']->Nama)
            ->where('Dasbor.JumlahPerluTinjauan', $perluTinjauan));
    });

    it('rentang tanggal: bawaan hari ini; beberapa hari dibanding periode sebelumnya; akhir tidak melewati hari ini; salah format & rentang terlalu panjang dinormalkan', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 05:00:00', 'UTC'));
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $jual = fn (string $jumlah) => BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => $jumlah, 'Harga' => '38500.00']]]);
        $jual('1');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
        $jual('2');
        $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
        $jual('1');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        // Tanpa query: hari ini saja.
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode', ['Dari' => '2026-10-07', 'Sampai' => '2026-10-07', 'Hari' => 1, 'SatuHari' => true, 'HariIni' => '2026-10-07', 'JumlahHariTerlaris' => 7])
            ->where('Dasbor.Angka.Bersih', '38500.00'));

        // Delapan hari (30 Sep - 7 Okt): jumlah seluruh periode, pembanding = 8 hari sebelumnya (kosong), grafik = rentang.
        $this->get('/kelola?dari=2026-09-30&sampai=2026-10-07')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode.Hari', 8)
            ->where('Dasbor.Periode.SatuHari', false)
            ->where('Dasbor.Angka.Bersih', '154000.00')
            ->where('Dasbor.Angka.JumlahTransaksi', 3)
            ->has('Dasbor.Pembanding', 1)
            ->where('Dasbor.Pembanding.0.Nama', 'periodeSebelumnya')
            ->where('Dasbor.Pembanding.0.Angka.Bersih', '0.00')
            ->has('Dasbor.Grafik', 8)
            ->where('Dasbor.Grafik.0.Tanggal', '2026-09-30')
            ->where('Dasbor.Grafik.7.Tanggal', '2026-10-07')
            ->where('Dasbor.ProdukTerlaris.0.Qty', '4.0000')
            ->where('Dasbor.PerOutlet.0.Bersih', '154000.00'));

        // Satu hari lampau: pembanding kemarin & minggu lalu, grafik 14 hari berakhir di hari itu.
        $this->get('/kelola?dari=2026-10-06&sampai=2026-10-06')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Angka.Bersih', '77000.00')
            ->where('Dasbor.Pembanding.0.Nama', 'kemarin')
            ->where('Dasbor.Pembanding.1.Nama', 'mingguLalu')
            ->has('Dasbor.Grafik', 14)
            ->where('Dasbor.Grafik.13.Tanggal', '2026-10-06'));

        // Akhir di masa depan dipotong ke hari ini; format salah diabaikan (bawaan hari ini); rentang terlalu panjang dipotong 92 hari.
        $this->get('/kelola?dari=2026-10-07&sampai=2027-01-01')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode.Sampai', '2026-10-07')
            ->where('Dasbor.Periode.Hari', 1));
        $this->get('/kelola?dari=bukan-tanggal&sampai=2026-13-45')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode.Dari', '2026-10-07')
            ->where('Dasbor.Periode.Hari', 1));
        $this->get('/kelola?dari=2025-01-01&sampai=2026-10-07')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode.Hari', 92)
            ->has('Dasbor.Grafik', 92));
        // Dari setelah sampai: dirapatkan ke satu hari (akhir).
        $this->get('/kelola?dari=2026-10-07&sampai=2026-10-01')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Periode.Dari', '2026-10-01')
            ->where('Dasbor.Periode.Sampai', '2026-10-01'));
    });

    it('tanpa izin laporan.penjualan.lihat beranda tanpa angka; dibatasi outlet akses; tenant lain tidak melihat angka tenant ini', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda')->where('Dasbor', null));

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Dasbor', null));

        // Manajer outlet ber-izin laporan tetapi tanpa izin persediaan tidak melihat stok kritis.
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Angka.Bersih', '83150.00')
            ->where('Dasbor.Angka.LabaKotor', '23150.00'));

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanOrganisasi::Masuk($this, $manajerCabang, $d['Tenant']->Id);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Angka.Bersih', '0.00')
            ->where('Dasbor.Angka.JumlahTransaksi', 0)
            ->where('Dasbor.PerOutlet', [['Kunci' => $cabang->Uuid, 'NamaOutlet' => $cabang->Nama, 'Bersih' => '0.00', 'JumlahTransaksi' => 0]])
            ->where('Dasbor.ProdukTerlaris', [])
            ->where('Dasbor.JumlahPerluTinjauan', 0));

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dasbor.Angka.Bersih', '0.00')
            ->where('Dasbor.Grafik.13.Bersih', '0.00')
            ->where('Dasbor.ProdukTerlaris', []));
    });
});

describe('F-14a laporan penjualan /kelola/laporan/penjualan', function (): void {
    it('tab ringkasan harian, per produk (server), kategori, jam (heatmap lokal outlet), kasir, kanal, metode bayar, diskon cocok dengan data uji; void dikeluarkan, retur mengurangi', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $sembako = Kategori::query()->create(['Nama' => 'Sembako & Kebutuhan Dapur']);
        $d['Minyak']->forceFill(['IdKategori' => $sembako->Id])->save();
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        $url = '/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07';

        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Laporan/Penjualan')
            ->where('Saring', ['Tab' => 'harian', 'Dari' => '2026-10-01', 'Sampai' => '2026-10-07', 'Outlet' => '', 'Kasir' => '', 'Kanal' => '', 'Target' => 15])
            ->where('Peringatan', null)
            ->where('Total.Kotor', '125500.00')
            ->where('Total.Diskon', '3850.00')
            ->where('Total.Retur', '38500.00')
            ->where('Total.Bersih', '83150.00')
            ->where('Total.Pajak', '9146.50')
            ->where('Total.Hpp', '60000.00')
            ->where('Total.LabaKotor', '23150.00')
            ->where('Total.JumlahTransaksi', 2)
            ->where('OpsiKasir', [['Nilai' => $d['Kasir']->Uuid, 'Label' => $d['Kasir']->Nama]])
            ->has('Isi', 1)
            ->where('Isi.0.Tanggal', '2026-10-07')
            ->where('Isi.0.Bersih', '83150.00')
            ->where('Isi.0.RataRataKeranjang', '41575.00'));

        $this->get("{$url}&tab=produk")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.Meta.Total', 2)
            ->where('Isi.Data.0', [
                'IdProduk' => $d['Minyak']->Id, 'NamaProduk' => $d['Minyak']->Nama, 'Qty' => '2.0000', 'Kotor' => '115500.00', 'Diskon' => '3850.00',
                'Retur' => '38500.00', 'Bersih' => '73150.00', 'Pajak' => '8046.50', 'BiayaLayanan' => '0.00', 'Hpp' => '60000.00', 'LabaKotor' => '13150.00',
                'JumlahTransaksi' => 2, 'JumlahRetur' => 1,
            ])
            ->where('Isi.Data.1.NamaProduk', 'Jasa Antar Belanja Dalam Kota')
            ->where('Isi.Data.1.Bersih', '10000.00'));

        // TabelData mode server: URL yang sama dengan Accept JSON (cari, urut).
        $json = $this->getJson("{$url}&tab=produk&cari=jasa")->assertOk();
        expect($json->json('Meta.Total'))->toBe(1)->and($json->json('Data.0.Bersih'))->toBe('10000.00');
        expect($this->getJson("{$url}&tab=produk&urut=Bersih")->json('Data.0.NamaProduk'))->toBe('Jasa Antar Belanja Dalam Kota');

        $this->get("{$url}&tab=kategori")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.0.NamaKategori', 'Sembako & Kebutuhan Dapur')
            ->where('Isi.0.Bersih', '73150.00')
            ->where('Isi.1.NamaKategori', 'Tanpa kategori')
            ->where('Isi.1.Bersih', '10000.00'));

        // Dibuat 11.55 WIB hari Rabu (Hari 3); retur tidak masuk heatmap jam penjualan.
        $this->get("{$url}&tab=jam")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.Sel', [['Hari' => 3, 'Jam' => 11, 'Bersih' => '121650.00', 'JumlahTransaksi' => 2]])
            ->where('Isi.PerJam', [['Jam' => 11, 'Bersih' => '121650.00', 'JumlahTransaksi' => 2]]));

        $this->get("{$url}&tab=kasir")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.0.NamaKasir', $d['Kasir']->Nama)
            ->where('Isi.0.Bersih', '83150.00')
            ->where('Isi.0.JumlahRetur', 1));

        $this->get("{$url}&tab=kanal")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.0.Kunci', 'BawaPulang')
            ->where('Isi.0.Kotor', '87000.00')
            ->where('Isi.0.Retur', '38500.00')
            ->where('Isi.0.Bersih', '48500.00')
            ->where('Isi.1.Kunci', 'MakanDiTempat')
            ->where('Isi.1.Diskon', '3850.00')
            ->where('Isi.1.Bersih', '34650.00'));

        $this->get("{$url}&tab=metode")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.0', ['Kunci' => (string) $d['Qris']->Id, 'NamaMetode' => 'QRIS Toko Berkah', 'LabelJenis' => 'QRIS statis', 'Diterima' => '50000.00', 'Refund' => '0.00', 'Bersih' => '50000.00', 'JumlahTransaksi' => 1])
            ->where('Isi.1.Diterima', '85031.50')
            ->where('Isi.1.Refund', '42735.00')
            ->where('Isi.1.Bersih', '42296.50')
            ->where('Isi.1.JumlahTransaksi', 2));

        $this->get("{$url}&tab=diskon")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isi.0.NamaKasir', $d['Kasir']->Nama)
            ->where('Isi.0.JumlahTransaksi', 2)
            ->where('Isi.0.JumlahBerdiskon', 1)
            ->where('Isi.0.JumlahDisetujui', 1)
            ->where('Isi.0.DiskonPesanan', '3850.00')
            ->where('Isi.0.TotalDiskon', '3850.00'));
    });

    it('saring kanal, kasir, outlet; periode lebih dari 92 hari dipotong dengan peringatan; ekspor CSV mengikuti saring', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get('/kelola/laporan/penjualan?kanal=MakanDiTempat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Dari', '2026-10-01')
            ->where('Total.Bersih', '34650.00')
            ->where('Total.JumlahTransaksi', 1)
            ->where('Isi.0.Bersih', '34650.00'));
        $this->get("/kelola/laporan/penjualan?kasir={$d['Supervisor']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.JumlahTransaksi', 0)->where('Isi', []));
        $this->get("/kelola/laporan/penjualan?kasir={$d['Kasir']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.Bersih', '83150.00'));
        $this->get("/kelola/laporan/penjualan?outlet={$d['Outlet']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.Bersih', '83150.00'));
        $this->get('/kelola/laporan/penjualan?dari=2026-01-01&sampai=2026-10-07')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Dari', '2026-01-01')
            ->where('Saring.Sampai', '2026-04-02')
            ->where('Peringatan', fn (?string $p): bool => is_string($p) && str_contains($p, '92 hari'))
            ->where('Total.JumlahTransaksi', 0));

        $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=produk&dari=2026-10-01&sampai=2026-10-07')->assertOk();
        expect($csv->headers->get('Content-Type'))->toContain('text/csv');
        $isi = $csv->streamedContent();
        expect($isi)->toStartWith("\xEF\xBB\xBFProduk,")
            ->and($isi)->toContain('"'.$d['Minyak']->Nama.'",2.0000,115500.00,3850.00,38500.00,73150.00,8046.50,0.00,60000.00,13150.00,2,1')
            ->and($isi)->toContain('Jasa Antar Belanja Dalam Kota');

        $kanal = $this->get('/kelola/laporan/penjualan/ekspor?tab=harian&kanal=MakanDiTempat')->assertOk()->streamedContent();
        expect($kanal)->toContain('2026-10-07,38500.00,3850.00,0.00,34650.00');
    });

    it('izin laporan.penjualan.lihat (Kasir 403), outlet di luar akses kosong, tenant lain tidak melihat data', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/laporan/penjualan')->assertForbidden();
        $this->get('/kelola/laporan/penjualan/ekspor')->assertForbidden();

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanOrganisasi::Masuk($this, $manajerCabang, $d['Tenant']->Id);
        $this->get('/kelola/laporan/penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.JumlahTransaksi', 0)->where('OpsiOutlet', [['Nilai' => $cabang->Uuid, 'Label' => $cabang->Nama]]));
        $this->get("/kelola/laporan/penjualan?outlet={$d['Outlet']->Uuid}&tab=produk")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.JumlahTransaksi', 0)->where('Isi.Meta.Total', 0));

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/laporan/penjualan?outlet={$d['Outlet']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Total.JumlahTransaksi', 0));
        $this->get('/kelola/laporan/penjualan?tab=kasir')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Isi', [])->where('OpsiKasir', []));
    });
});

describe('F-14a laporan pajak /kelola/laporan/pajak', function (): void {
    it('PPN keluaran per bulan dari PenjualanPajak dikurangi pajak retur (DPP proporsional); void dikeluarkan; ekspor CSV; izin laporan.keuangan.lihat', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->get('/kelola/laporan/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Laporan/Pajak')
            ->where('Saring', ['Dari' => '2026-10-01', 'Sampai' => '2026-10-07', 'Outlet' => ''])
            ->where('Pbjt', [])
            ->has('Ppn', 1)
            ->where('Ppn.0.Bulan', '2026-10')
            ->where('Ppn.0.KodeJenisPajak', 'Ppn')
            ->where('Ppn.0.LabelKategori', 'PPN')
            ->where('Ppn.0.Tarif', '12.000000')
            ->where('Ppn.0.Dpp', '111512.50')
            ->where('Ppn.0.Pajak', '13381.50')
            ->where('Ppn.0.DppRetur', '35291.67')
            ->where('Ppn.0.PajakRetur', '4235.00')
            ->where('Ppn.0.DppBersih', '76220.83')
            ->where('Ppn.0.PajakBersih', '9146.50')
            ->where('Ppn.0.JumlahTransaksi', 2));

        $csv = $this->get('/kelola/laporan/pajak/ekspor?jenis=ppn')->assertOk()->streamedContent();
        expect($csv)->toContain('2026-10,')->and($csv)->toContain('12.000000,111512.50,13381.50,35291.67,4235.00,76220.83,9146.50,2');

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $this->get('/kelola/laporan/pajak')->assertForbidden();

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get('/kelola/laporan/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Ppn', [])->where('Pbjt', []));
    });

    it('PB1/PBJT per outlet per bulan per tarif (TAX-04): DPP termasuk biaya layanan, pajak retur mengurangi', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $k['Outlet']->forceFill(['KodeKota' => '33.72'])->save();
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren Ukuran Besar', 'Jenis' => JenisProduk::NonStok], '27500.00');
        BantuanPenjualan::AturProfilPajak($k, pungutPbjt: true, persenBiayaLayanan: '5.00');
        BantuanPenjualan::PasangKelompokPajak('Uji laporan: makan & minum PBJT', ['PbjtMakananMinuman' => 'SubtotalPlusLayanan'], $kopi);
        $jual = BantuanPenjualan::Jual($this, $k, [
            'PersenBiayaLayanan' => '5',
            'Pajak' => [['PbjtMakananMinuman', '10.000000', 1, 1, 'SubtotalPlusLayanan']],
            'Baris' => [['Produk' => $kopi, 'Jumlah' => '2', 'Harga' => '27500.00']],
        ]);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']])]))->toBe([['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        // DPP = 55.000 + layanan 2.750 = 57.750; pajak 5.775; retur separuh.
        $this->get('/kelola/laporan/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ppn', [])
            ->has('Pbjt', 1)
            ->where('Pbjt.0.NamaOutlet', $k['Outlet']->Nama)
            ->where('Pbjt.0.KodeJenisPajak', 'PbjtMakananMinuman')
            ->where('Pbjt.0.Tarif', '10.000000')
            ->where('Pbjt.0.Dpp', '57750.00')
            ->where('Pbjt.0.Pajak', '5775.00')
            ->where('Pbjt.0.PajakRetur', '2887.50')
            ->where('Pbjt.0.DppRetur', '28875.00')
            ->where('Pbjt.0.PajakBersih', '2887.50'));
    });
});

describe('F-14a laporan stok /kelola/laporan/stok', function (): void {
    it('nilai persediaan per lokasi & kategori pada tanggal (dari MutasiStok) dan stok kritis (saldo ≤ minimum); ekspor CSV; izin persediaan.lihat; isolasi tenant', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $sembako = Kategori::query()->create(['Nama' => 'Sembako & Kebutuhan Dapur']);
        $d['Minyak']->forceFill(['IdKategori' => $sembako->Id])->save();
        $gula = BantuanPenjualan::BuatProdukBerstok($d['Gudang'], $d['Pemilik']->Id, 'Gula Pasir Kristal Putih Premium 1 kg', '10', '14000', '17500.00');
        ProdukGudang::query()->create(['IdProduk' => $d['Minyak']->Id, 'IdGudang' => $d['Gudang']->Id, 'StokMinimum' => '8']);
        ProdukGudang::query()->create(['IdProduk' => $gula->Id, 'IdGudang' => $d['Gudang']->Id, 'StokMinimum' => '5']);

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::StafGudang);
        // Minyak: 10 − 3 + 1 = 8 × 30.000 = 240.000; gula 10 × 14.000 = 140.000.
        $this->get('/kelola/laporan/stok')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Laporan/Stok')
            ->where('Saring', ['Tab' => 'nilai', 'Tanggal' => '2026-10-07', 'Gudang' => '', 'Hari' => 14])
            ->where('Nilai.Total', ['Nilai' => '380000.00', 'JumlahProduk' => 2])
            ->where('Nilai.PerGudang.0.Kunci', $d['Gudang']->Uuid)
            ->where('Nilai.PerGudang.0.Nilai', '380000.00')
            ->where('Nilai.PerKategori.0.NamaKategori', 'Sembako & Kebutuhan Dapur')
            ->where('Nilai.PerKategori.0.Nilai', '240000.00')
            ->where('Nilai.PerKategori.1.NamaKategori', 'Tanpa kategori')
            ->where('Kritis', null));

        // Sebelum penjualan hari ini: stok awal utuh (10 × 30.000 + 10 × 14.000); sebelum stok awal: nol.
        $this->get('/kelola/laporan/stok?tanggal=2026-10-06')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Nilai.Total', ['Nilai' => '440000.00', 'JumlahProduk' => 2]));
        $this->get('/kelola/laporan/stok?tanggal=2026-01-01')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Nilai.Total', ['Nilai' => '0.00', 'JumlahProduk' => 0])
            ->where('Nilai.PerGudang', []));

        $this->get('/kelola/laporan/stok?tab=kritis')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Nilai', null)
            ->where('Kritis.Jumlah', 1)
            ->where('Kritis.Baris.0.UuidProduk', $d['Minyak']->Uuid)
            ->where('Kritis.Baris.0.Saldo', '8.0000')
            ->where('Kritis.Baris.0.StokMinimum', '8.0000')
            ->where('Kritis.Baris.0.Kekurangan', '0.0000'));

        $csv = $this->get('/kelola/laporan/stok/ekspor?tab=kritis')->assertOk()->streamedContent();
        expect($csv)->toContain($d['Minyak']->Nama)->and($csv)->not->toContain('Gula Pasir');
        expect($this->get('/kelola/laporan/stok/ekspor')->assertOk()->streamedContent())->toContain('380000.00');

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/laporan/stok')->assertForbidden();

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/laporan/stok?gudang={$d['Gudang']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Nilai.Total.Nilai', '0.00'));
        $this->get('/kelola/laporan/stok?tab=kritis')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Kritis.Jumlah', 0));
    });
});

describe('F-05g batch kedaluwarsa: laporan stok & Kotak Tindakan', function (): void {
    it('tab kedaluwarsa memuat batch bersisa yang lewat atau jatuh dalam 30 hari (urut terdekat; batch habis & yang masih jauh tidak ikut); ekspor CSV; butir stok.kedaluwarsa Penting bila ada yang lewat', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Tenant boleh mematikan kewajiban kedaluwarsa batch; fixture ini punya satu batch tanpa tanggal (UHT-TANPA).
        config(['persediaan.StokAwal.WajibKedaluwarsaBatch' => false]);
        $susu = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter', 'Pelacakan' => PelacakanProduk::Batch], '19500.00');
        BantuanStokAwal::BuatDanPosting($k['Gudang'], [
            BantuanStokAwal::Baris($susu, '4', '10000', 'UHT-LEWAT', '2026-10-04'),
            BantuanStokAwal::Baris($susu, '10', '10000', 'UHT-SEGERA', '2026-10-12'),
            BantuanStokAwal::Baris($susu, '6', '10000', 'UHT-JAUH', '2026-12-31'),
            BantuanStokAwal::Baris($susu, '3', '10000', 'UHT-HABIS', '2026-10-05'),
            BantuanStokAwal::Baris($susu, '2', '10000', 'UHT-TANPA'),
        ], $k['Pemilik']->Id);
        BantuanStokAwal::Jual($susu, $k['Gudang'], '3', idBatchStok: BatchStok::query()->where('NomorBatch', 'UHT-HABIS')->value('Id'));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $this->get('/kelola/laporan/stok?tab=kedaluwarsa')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Nilai', null)
            ->where('Kritis', null)
            ->where('Kedaluwarsa.Jumlah', 2)
            ->where('Kedaluwarsa.JumlahLewat', 1)
            ->where('Kedaluwarsa.Baris.0.NomorBatch', 'UHT-LEWAT')
            ->where('Kedaluwarsa.Baris.0.Status', 'Lewat')
            ->where('Kedaluwarsa.Baris.0.SisaHari', -3)
            ->where('Kedaluwarsa.Baris.0.Sisa', '4.0000')
            ->where('Kedaluwarsa.Baris.1.NomorBatch', 'UHT-SEGERA')
            ->where('Kedaluwarsa.Baris.1.Status', 'Segera')
            ->where('Kedaluwarsa.Baris.1.SisaHari', 5));

        $csv = $this->get('/kelola/laporan/stok/ekspor?tab=kedaluwarsa')->assertOk()->streamedContent();
        expect($csv)->toContain('UHT-LEWAT')->toContain('UHT-SEGERA')->not->toContain('UHT-JAUH')->not->toContain('UHT-HABIS')->not->toContain('UHT-TANPA');

        $this->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h): void {
            $butir = collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'stok.kedaluwarsa');
            expect($butir['Jumlah'])->toBe(2)
                ->and($butir['Tingkat'])->toBe('Penting')
                ->and($butir['Tautan'])->toBe('/kelola/laporan/stok?tab=kedaluwarsa')
                ->and($butir['Rincian'][0]['Judul'])->toContain('UHT-LEWAT')
                ->and($butir['Rincian'][0]['Keterangan'])->toContain('lewat 3 hari');
        });

        // Tanpa batch yang lewat, butir turun ke Perhatian; tanpa batch mendekat, butir hilang.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 05:00:00', 'UTC'));
        $this->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h): void {
            $butir = collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'stok.kedaluwarsa');
            expect($butir['Tingkat'])->toBe('Perhatian');
        });

        // Isolasi tenant.
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get('/kelola/laporan/stok?tab=kedaluwarsa')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Kedaluwarsa.Jumlah', 0));
    });
});
