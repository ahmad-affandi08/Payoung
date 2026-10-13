<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a halaman jurnal baca saja (DesainF05a D/E, H-13)', function (): void {
    it('daftar jurnal: terbaru di atas, tautan sumber, penanda pembalik & dibalik, opsi jenis sumber', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $pertama = BantuanJurnal::Posting($dataPertama = BantuanJurnal::DataStokAwal(idSumber: 1, tanggal: '2026-09-20', idOutlet: $t['Outlet']->Id));
        $kedua = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 2, nilai: '750000.00', tanggal: '2026-09-22', idOutlet: $t['Outlet']->Id));
        $balik = app(BalikkanJurnal::class)->Jalankan($pertama->idJurnal, CarbonImmutable::parse('2026-09-24'), 'Pembatalan stok awal SA/2026/09/0001', JenisSumberJurnal::StokAwal, 1, 'Pembatalan', null);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)->get('/kelola/akuntansi/jurnal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Jurnal/Daftar')
                ->where('Jurnal.Meta.Total', 3)
                ->where('Jurnal.Meta.Halaman', 1)
                ->where('Jurnal.Data.0.Nomor', $balik->nomor)
                ->where('Jurnal.Data.0.Pembalik', true)
                ->where('Jurnal.Data.0.Dibalik', false)
                ->where('Jurnal.Data.1.Uuid', $kedua->uuid)
                ->where('Jurnal.Data.1.TotalDebit', '750000.00')
                ->where('Jurnal.Data.2.Uuid', $pertama->uuid)
                ->where('Jurnal.Data.2', [
                    'Uuid' => $pertama->uuid,
                    'Nomor' => 'JU/2026/09/000001',
                    'Tanggal' => '2026-09-20',
                    'JenisSumber' => 'StokAwal',
                    'LabelJenisSumber' => 'Stok awal',
                    'NomorSumber' => 'SA/2026/09/0001',
                    'TautanSumber' => '/kelola/persediaan/stok-awal/'.$dataPertama->uuidSumber,
                    'Keterangan' => 'Stok awal Gudang Toko Outlet Utama',
                    'TotalDebit' => '12345678.90',
                    'Otomatis' => true,
                    'Dibalik' => true,
                    'Pembalik' => false,
                ])
                ->missing('Saring')
                ->where('OpsiJenisSumber', [['Nilai' => 'StokAwal', 'Label' => 'Stok awal'], ['Nilai' => 'MutasiKas', 'Label' => 'Kas masuk/keluar'], ['Nilai' => 'Penjualan', 'Label' => 'Penjualan'], ['Nilai' => 'ReturPenjualan', 'Label' => 'Retur penjualan'], ['Nilai' => 'TutupShift', 'Label' => 'Selisih kas tutup shift'], ['Nilai' => 'TransaksiKasBank', 'Label' => 'Transaksi kas & bank'], ['Nilai' => 'TransferStok', 'Label' => 'Transfer stok'], ['Nilai' => 'StokOpname', 'Label' => 'Stok opname'], ['Nilai' => 'PenyesuaianStok', 'Label' => 'Penyesuaian stok'], ['Nilai' => 'PenerimaanBarang', 'Label' => 'Penerimaan barang'], ['Nilai' => 'FakturPembelian', 'Label' => 'Faktur pembelian'], ['Nilai' => 'PembayaranHutang', 'Label' => 'Pembayaran hutang'], ['Nilai' => 'ReturPembelian', 'Label' => 'Retur pembelian'], ['Nilai' => 'PembayaranPiutang', 'Label' => 'Pelunasan piutang'], ['Nilai' => 'PesananPenjualan', 'Label' => 'Uang muka pre-order'], ['Nilai' => 'TutupTahun', 'Label' => 'Tutup tahun'], ['Nilai' => 'Kasbon', 'Label' => 'Kasbon karyawan'], ['Nilai' => 'PelunasanKasbon', 'Label' => 'Pelunasan kasbon'], ['Nilai' => 'RekapGaji', 'Label' => 'Rekap gaji'], ['Nilai' => 'PenerimaanKlaimPemasok', 'Label' => 'Penerimaan klaim promo pemasok'], ['Nilai' => 'IsiDeposit', 'Label' => 'Isi deposit pelanggan'], ['Nilai' => 'MutasiDeposit', 'Label' => 'Penarikan/penyesuaian deposit'], ['Nilai' => 'PemakaianSesi', 'Label' => 'Pemakaian paket sesi'], ['Nilai' => 'MutasiSesi', 'Label' => 'Pengembalian/hangus paket sesi'], ['Nilai' => 'OrderProduksi', 'Label' => 'Order produksi'], ['Nilai' => 'BahanTerbuang', 'Label' => 'Bahan terbuang'], ['Nilai' => 'SuratJalan', 'Label' => 'Surat jalan grosir'], ['Nilai' => 'FakturPenjualan', 'Label' => 'Faktur penjualan grosir'], ['Nilai' => 'ReturGrosir', 'Label' => 'Retur grosir'], ['Nilai' => 'Pencairan', 'Label' => 'Pencairan dana'], ['Nilai' => 'HapusBukuPiutang', 'Label' => 'Hapus-buku piutang'], ['Nilai' => 'TagihanQris', 'Label' => 'Uang QRIS tanpa penjualan'], ['Nilai' => 'PesananOnline', 'Label' => 'Uang muka pesanan online'], ['Nilai' => 'AsetTetap', 'Label' => 'Aset tetap'], ['Nilai' => 'PenyusutanAset', 'Label' => 'Penyusutan aset tetap'], ['Nilai' => 'PembayaranKonsinyasi', 'Label' => 'Setoran konsinyasi'], ['Nilai' => 'BiayaTambahanPembelian', 'Label' => 'Biaya tambahan pembelian'], ['Nilai' => 'NotaDebitPembelian', 'Label' => 'Nota debit pembelian'], ['Nilai' => 'Giro', 'Label' => 'Pencairan giro'], ['Nilai' => 'JurnalUmum', 'Label' => 'Jurnal umum']]));
    });

    it('saringan kata, rentang tanggal, dan jenis; nilai tidak valid diabaikan; halaman lewat ?halaman', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        foreach (range(1, 52) as $id) {
            BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: $id, nilai: '1000.00', tanggal: $id <= 2 ? '2026-08-10' : '2026-09-10'));
        }
        $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->get('/kelola/akuntansi/jurnal?cari=SA/2026/09/0051')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jurnal.Meta.Total', 1)->where('Jurnal.Data.0.NomorSumber', 'SA/2026/09/0051'));
        $masuk()->get('/kelola/akuntansi/jurnal?cari=JU/2026/08')->assertInertia(fn (AssertableInertia $h) => $h->where('Jurnal.Meta.Total', 2));
        $masuk()->get('/kelola/akuntansi/jurnal?saring[Tanggal]=2026-08-01..2026-08-31&saring[JenisSumber]=StokAwal')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jurnal.Meta.Total', 2));
        // Tanggal tidak sah & jenis tak dikenal diabaikan; `%` dicari sebagai huruf biasa.
        $masuk()->getJson('/kelola/akuntansi/jurnal?saring[Tanggal]=31-08-2026..2026-02-30&saring[JenisSumber]=Produksi&cari=%25')->assertOk()
            ->assertJsonPath('Meta.Total', 0);
        $masuk()->getJson('/kelola/akuntansi/jurnal?saring[Tanggal]=31-08-2026..2026-02-30&saring[JenisSumber]=Produksi')->assertOk()
            ->assertJsonPath('Meta.Total', 52);
        $masuk()->get('/kelola/akuntansi/jurnal?halaman=3')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jurnal.Meta.Total', 52)->where('Jurnal.Meta.Halaman', 3)->where('Jurnal.Meta.JumlahHalaman', 3)->has('Jurnal.Data', 2));
        $masuk()->getJson('/kelola/akuntansi/jurnal?perHalaman=50&halaman=2')->assertOk()->assertJsonCount(2, 'Data');
    });

    it('detail jurnal: baris akun & outlet, total seimbang, pembuat, tautan jurnal dibalik & pembalik', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idOutlet: $t['Outlet']->Id, idPengguna: $t['Pemilik']->Id));
        $balik = app(BalikkanJurnal::class)->Jalankan($asal->idJurnal, CarbonImmutable::parse('2026-09-25'), 'Pembatalan stok awal', JenisSumberJurnal::StokAwal, 1, 'Pembatalan', null);
        $persediaan = Akun::query()->findOrFail(BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang));
        $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->get("/kelola/akuntansi/jurnal/{$asal->uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Jurnal/Detail')
                ->where('Jurnal.Nomor', 'JU/2026/09/000001')
                ->where('Jurnal.Periode', '2026-09')
                ->where('Jurnal.DibuatOleh', $t['Pemilik']->Nama)
                ->where('Jurnal.Dibalik', true)
                ->where('Jurnal.UuidPembalik', $balik->uuid)
                ->where('Jurnal.NomorPembalik', $balik->nomor)
                ->where('Jurnal.UuidJurnalDibalik', null)
                ->has('Jurnal.DibuatPada')
                ->where('Baris.0', [
                    'Urutan' => 1,
                    'KodeAkun' => $persediaan->Kode,
                    'NamaAkun' => $persediaan->Nama,
                    'NamaOutlet' => $t['Outlet']->Nama,
                    'Debit' => '12345678.90',
                    'Kredit' => '0.00',
                    'Memo' => null,
                ])
                ->where('Baris.1.Kredit', '12345678.90')
                ->where('Total', ['Debit' => '12345678.90', 'Kredit' => '12345678.90']));

        $masuk()->get("/kelola/akuntansi/jurnal/{$balik->uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jurnal.Pembalik', true)
            ->where('Jurnal.UuidJurnalDibalik', $asal->uuid)
            ->where('Jurnal.NomorJurnalDibalik', 'JU/2026/09/000001')
            ->where('Jurnal.DibuatOleh', null)
            ->where('Baris.0.Debit', '12345678.90')
            ->where('Baris.0.KodeAkun', Akun::query()->findOrFail(BantuanJurnal::IdAkunPeran(PeranAkun::EkuitasSaldoAwal))->Kode));
    });

    it('H-13: izin laporan.keuangan.lihat wajib (Akuntan boleh; Staf gudang & Manajer outlet 403)', function (PeranTenantBawaan $peran, bool $boleh): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $jurnal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());

        $daftar = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, $peran)->get('/kelola/akuntansi/jurnal');
        $detail = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, $peran)->get("/kelola/akuntansi/jurnal/{$jurnal->uuid}");

        $boleh ? $daftar->assertOk() : $daftar->assertForbidden();
        $boleh ? $detail->assertOk() : $detail->assertForbidden();
    })->with([
        'Akuntan' => [PeranTenantBawaan::Akuntan, true],
        'Admin' => [PeranTenantBawaan::Admin, true],
        'Staf gudang' => [PeranTenantBawaan::StafGudang, false],
        'Manajer outlet' => [PeranTenantBawaan::ManajerOutlet, false],
    ]);

    it('isolasi tenant: jurnal tenant lain tidak muncul di daftar dan detailnya 404; Uuid tidak valid 404', function (): void {
        BantuanPersediaan::SiapkanTenant('Toko Emas Cahaya Abadi');
        $jurnalA = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        $b = BantuanPersediaan::SiapkanTenant('Toko Roti Harum Manis');
        $masukB = fn () => BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);

        $masukB()->get('/kelola/akuntansi/jurnal')->assertInertia(fn (AssertableInertia $h) => $h->where('Jurnal.Meta.Total', 0));
        $masukB()->get("/kelola/akuntansi/jurnal/{$jurnalA->uuid}")->assertNotFound();
        $masukB()->get('/kelola/akuntansi/jurnal/bukan-ulid')->assertNotFound();
    });

    it('akses outlet: pengguna terbatas hanya melihat jurnal yang semua barisnya di outlet aksesnya', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $diSolo = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idOutlet: $solo->Id));
        $diUtama = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 2, idOutlet: $t['Outlet']->Id));
        $tingkatUsaha = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 3));

        $akuntan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id);

        $masuk()->get('/kelola/akuntansi/jurnal')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jurnal.Meta.Total', 1)->where('Jurnal.Data.0.Uuid', $diSolo->uuid));
        $masuk()->get("/kelola/akuntansi/jurnal/{$diSolo->uuid}")->assertOk();
        $masuk()->get("/kelola/akuntansi/jurnal/{$diUtama->uuid}")->assertNotFound();
        $masuk()->get("/kelola/akuntansi/jurnal/{$tingkatUsaha->uuid}")->assertNotFound();

        expect(Jurnal::query()->count())->toBe(3);
    });
});
