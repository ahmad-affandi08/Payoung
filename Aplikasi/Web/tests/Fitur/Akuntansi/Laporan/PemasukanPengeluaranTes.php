<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\PemasukanPengeluaran;
use App\Domain\Akuntansi\Layanan\PenggolongUangKas;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-255 laporan Pemasukan & Pengeluaran (basis kas): uang yang benar-benar masuk dan keluar akun kas & bank. Penjualan
 * dipisah dari biaya layanan, pencairan dana non-tunai dirinci dana kotor dan potongan platform, modal/prive/beli aset
 * di luar hitungan usaha, pindah antar akun kas tidak tampil, dan selisih + di luar usaha = perubahan saldo kas.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatKasBankUang(string $jenis, string $tanggal, string $kodeSumber, string $kodeTujuan, string $jumlah, string $keterangan, ?int $idOutlet = null): void
{
    app(SimpanTransaksiKasBank::class)->Jalankan(new DataTransaksiKasBank(
        JenisTransaksiKasBank::from($jenis),
        CarbonImmutable::parse($tanggal),
        $idOutlet,
        (string) Akun::query()->where('Kode', $kodeSumber)->value('Uuid'),
        (string) Akun::query()->where('Kode', $kodeTujuan)->value('Uuid'),
        Uang::Dari($jumlah),
        $keterangan,
        null,
        null,
    ));
}

/**
 * @param  list<DataBarisJurnal>  $baris
 */
function PostingJurnalUang(JenisSumberJurnal $jenis, int $idSumber, string $tanggal, array $baris): void
{
    app(PostingJurnal::class)->Jalankan(new DataJurnal($jenis, $idSumber, null, null, CarbonImmutable::parse($tanggal), 'Jurnal uji', $baris, null));
}

/** @return array<string, mixed> */
function LaporanUang(mixed $uji, int $idTenant, string $dari, string $sampai, PeranTenantBawaan $peran = PeranTenantBawaan::Akuntan): array
{
    $hasil = null;
    BantuanPersediaan::MasukSebagai($uji, $idTenant, $peran)
        ->get("/kelola/laporan/pemasukan-pengeluaran?dari={$dari}&sampai={$sampai}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $h) use (&$hasil): void {
            $h->component('Kelola/Laporan/PemasukanPengeluaran');
            $hasil = $h->toArray()['props']['Laporan'];
        });

    return $hasil;
}

describe('D-255 pemasukan & pengeluaran', function (): void {
    it('penjualan tunai masuk, beban keluar, modal dan beli aset di luar usaha, transfer antar kas tidak tampil, selisih + di luar usaha = perubahan kas', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $hari = CarbonImmutable::parse($jual->TanggalBisnis->toDateString());
        $awal = $hari->startOfMonth()->toDateString();

        CatatKasBankUang('Penerimaan', $hari->toDateString(), '3-1000', '1-1200', '5000000.00', 'Tambahan modal pemilik');
        CatatKasBankUang('Pengeluaran', $hari->toDateString(), '1-1200', '1-2000', '1500000.00', 'Beli etalase kaca');
        CatatKasBankUang('Pengeluaran', $hari->toDateString(), '1-1100', '6-2000', '250000.00', 'Bayar listrik PLN', $k['Outlet']->Id);
        CatatKasBankUang('Transfer', $hari->toDateString(), '1-1200', '1-1150', '1000000.00', 'Ambil tunai ke brankas');

        $laporan = LaporanUang($this, $k['Tenant']->Id, $awal, $hari->toDateString());
        $penjualan = Uang::Dari($jual->TotalAkhir);
        $selisih = $penjualan->Kurangi(Uang::Dari('250000.00'));
        $r = $laporan['Ringkasan'];

        expect($r['Masuk'])->toBe($penjualan->KeString())
            ->and($r['Keluar'])->toBe('250000.00')
            ->and($r['Selisih'])->toBe($selisih->KeString())
            ->and($r['DiLuarUsaha'])->toBe('3500000.00')
            ->and($r['PerubahanKas'])->toBe($selisih->Tambah(Uang::Dari('3500000.00'))->KeString())
            ->and($laporan['Kecocokan'])->toBe(['Cocok' => true, 'Selisih' => '0.00'])
            ->and($laporan['BelumDikelompokkan'])->toBe('0.00')
            ->and($laporan['AdaAkunKas'])->toBeTrue();

        $masuk = collect($laporan['Masuk']);
        $keluar = collect($laporan['Keluar']);
        expect($masuk->count())->toBe(1)
            ->and($masuk->first())->toMatchArray(['Label' => 'Penjualan', 'Kelompok' => 'Penjualan', 'Nilai' => $penjualan->KeString()])
            ->and($keluar->count())->toBe(1)
            ->and($keluar->first())->toMatchArray(['Kelompok' => PenggolongUangKas::KELUAR_OPERASIONAL, 'Nilai' => '250000.00'])
            ->and(collect($laporan['DiLuarUsahaRincian'])->pluck('Kelompok')->sort()->values()->all())
            ->toBe([PenggolongUangKas::DI_LUAR_ASET, PenggolongUangKas::DI_LUAR_MODAL])
            ->and(collect($laporan['DiLuarUsahaRincian'])->firstWhere('Kelompok', PenggolongUangKas::DI_LUAR_ASET)['Nilai'])->toBe('-1500000.00')
            ->and(collect($laporan['DiLuarUsahaRincian'])->firstWhere('Kelompok', PenggolongUangKas::DI_LUAR_MODAL)['Nilai'])->toBe('5000000.00')
            ->and(collect($laporan['KelompokKeluar'])->pluck('Kelompok')->all())->toBe([PenggolongUangKas::KELUAR_OPERASIONAL])
            ->and($laporan['Grafik']['Satuan'])->toBe('Hari');

        $titik = collect($laporan['Grafik']['Titik'])->keyBy('Kunci');
        expect($titik[$hari->toDateString()]['Masuk'])->toBe($penjualan->KeString())
            ->and($titik[$hari->toDateString()]['Keluar'])->toBe('250000.00');
    });

    it('biaya layanan dipisah dari penjualan dan dibatasi kas yang benar-benar diterima', function (): void {
        $uji = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Solo Baru');
        PostingJurnalUang(JenisSumberJurnal::Penjualan, 9003, '2026-09-10', [
            DataBarisJurnal::Debit(PeranAkun::KasOutlet, Uang::Dari('1100000.00')),
            DataBarisJurnal::Kredit(PeranAkun::Penjualan, Uang::Dari('1000000.00')),
            DataBarisJurnal::Kredit(PeranAkun::PendapatanBiayaLayanan, Uang::Dari('100000.00')),
        ]);
        PostingJurnalUang(JenisSumberJurnal::Penjualan, 9004, '2026-09-11', [
            DataBarisJurnal::Debit(PeranAkun::PiutangPencairan, Uang::Dari('550000.00')),
            DataBarisJurnal::Kredit(PeranAkun::Penjualan, Uang::Dari('500000.00')),
            DataBarisJurnal::Kredit(PeranAkun::PendapatanBiayaLayanan, Uang::Dari('50000.00')),
        ]);

        $laporan = LaporanUang($this, $uji['Tenant']->Id, '2026-09-01', '2026-09-30');
        $masuk = collect($laporan['Masuk'])->keyBy('Label');

        expect($laporan['Ringkasan']['Masuk'])->toBe('1100000.00')
            ->and($masuk['Penjualan']['Nilai'])->toBe('1000000.00')
            ->and($masuk['Biaya layanan (service charge)']['Nilai'])->toBe('100000.00')
            ->and($laporan['Kecocokan']['Cocok'])->toBeTrue();
    });

    it('pencairan dana non-tunai: dana kotor masuk, potongan platform keluar, selisihnya sama dengan uang yang tiba di bank', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Warung Makan Bu Sri Pasar Gede');
        // Kliring Rp 1.000.000 dicairkan: bank terima 950.000, potongan platform 50.000.
        PostingJurnalUang(JenisSumberJurnal::Pencairan, 9101, '2026-09-15', [
            DataBarisJurnal::Debit(PeranAkun::Bank, Uang::Dari('950000.00')),
            DataBarisJurnal::Debit(PeranAkun::BebanBiayaPembayaran, Uang::Dari('50000.00')),
            DataBarisJurnal::Kredit(PeranAkun::PiutangPencairan, Uang::Dari('1000000.00')),
        ]);

        $laporan = LaporanUang($this, $a['Tenant']->Id, '2026-09-01', '2026-09-30');
        $masuk = collect($laporan['Masuk'])->keyBy('Label');
        $keluar = collect($laporan['Keluar'])->keyBy('Label');

        expect($masuk['Penjualan non-tunai yang sudah cair']['Nilai'])->toBe('1000000.00')
            ->and($keluar['Potongan platform dan biaya pembayaran']['Nilai'])->toBe('50000.00')
            ->and($laporan['Ringkasan']['Selisih'])->toBe('950000.00')
            ->and($laporan['Ringkasan']['PerubahanKas'])->toBe('950000.00')
            ->and($laporan['Kecocokan']['Cocok'])->toBeTrue();
    });

    it('pembanding periode sebelumnya, perubahan persen, dan periode tanpa mutasi bernilai nol', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Bangunan Sumber Rejeki');
        CatatKasBankUang('Penerimaan', '2026-08-01', '3-1000', '1-1100', '1000000.00', 'Modal awal');
        CatatKasBankUang('Pengeluaran', '2026-08-10', '1-1100', '6-2000', '100000.00', 'Listrik Agustus');
        CatatKasBankUang('Pengeluaran', '2026-09-10', '1-1100', '6-2000', '150000.00', 'Listrik September');

        $september = LaporanUang($this, $a['Tenant']->Id, '2026-09-01', '2026-09-30');
        $r = $september['Ringkasan'];

        expect($september['Periode']['DariSebelumnya'])->toBe('2026-08-01')
            ->and($september['Periode']['SampaiSebelumnya'])->toBe('2026-08-31')
            ->and($r['Keluar'])->toBe('150000.00')
            ->and($r['KeluarSebelumnya'])->toBe('100000.00')
            ->and($r['PerubahanKeluar'])->toBe('50.0')
            ->and($r['PerubahanMasuk'])->toBeNull()
            ->and($r['Masuk'])->toBe('0.00')
            ->and(collect($september['Keluar'])->first()['NilaiSebelumnya'])->toBe('100000.00');

        $oktober = LaporanUang($this, $a['Tenant']->Id, '2026-10-01', '2026-10-31');
        expect($oktober['Ringkasan']['Masuk'])->toBe('0.00')
            ->and($oktober['Ringkasan']['Keluar'])->toBe('0.00')
            ->and($oktober['Masuk'])->toBe([])
            // Kategori yang hanya ada di bulan lalu tetap tampil sebagai baris bernilai nol dengan pembandingnya.
            ->and(collect($oktober['Keluar'])->pluck('Nilai')->unique()->all())->toBe(['0.00'])
            ->and(collect($oktober['Keluar'])->first()['NilaiSebelumnya'])->toBe('150000.00')
            ->and($oktober['Kecocokan']['Cocok'])->toBeTrue();
    });

    it('rentang panjang menampilkan grafik per bulan, catatan hutang dan piutang dibaca dari saldo akun', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Pakaian Mawar Indah');
        // Piutang Rp 500.000 dari penjualan tempo (tanpa uang di kas), lalu dilunasi Rp 300.000.
        PostingJurnalUang(JenisSumberJurnal::Penjualan, 9200, '2026-02-01', [
            DataBarisJurnal::Debit(PeranAkun::PiutangUsaha, Uang::Dari('500000.00')),
            DataBarisJurnal::Kredit(PeranAkun::Penjualan, Uang::Dari('500000.00')),
        ]);
        PostingJurnalUang(JenisSumberJurnal::PembayaranPiutang, 9201, '2026-03-05', [
            DataBarisJurnal::Debit(PeranAkun::Bank, Uang::Dari('300000.00')),
            DataBarisJurnal::Kredit(PeranAkun::PiutangUsaha, Uang::Dari('300000.00')),
        ]);
        // Barang diterima Rp 800.000 belum difakturkan, lalu fakturnya masuk: hutang pemasok tetap Rp 800.000.
        PostingJurnalUang(JenisSumberJurnal::PenerimaanBarang, 9203, '2026-04-01', [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('800000.00')),
            DataBarisJurnal::Kredit(PeranAkun::HutangBelumDifakturkan, Uang::Dari('800000.00')),
        ]);
        PostingJurnalUang(JenisSumberJurnal::FakturPembelian, 9202, '2026-04-05', [
            DataBarisJurnal::Debit(PeranAkun::HutangBelumDifakturkan, Uang::Dari('800000.00')),
            DataBarisJurnal::Kredit(PeranAkun::HutangUsaha, Uang::Dari('800000.00')),
        ]);

        $laporan = LaporanUang($this, $a['Tenant']->Id, '2026-01-01', '2026-06-30');
        $titik = collect($laporan['Grafik']['Titik']);

        expect($laporan['Grafik']['Satuan'])->toBe('Bulan')
            ->and($titik->pluck('Kunci')->all())->toBe(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'])
            ->and($titik->firstWhere('Kunci', '2026-03')['Masuk'])->toBe('300000.00')
            ->and($laporan['Catatan']['Piutang'])->toBe('200000.00')
            ->and($laporan['Catatan']['Hutang'])->toBe('800000.00')
            ->and(collect($laporan['Masuk'])->first())->toMatchArray(['Label' => 'Pelunasan piutang', 'Kelompok' => PenggolongUangKas::MASUK_PIUTANG]);
    });

    it('ekspor CSV templat seragam, izin laporan keuangan, dan isolasi tenant', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        CatatKasBankUang('Penerimaan', '2026-09-01', '3-1000', '1-1100', '1000000.00', 'Modal awal');
        CatatKasBankUang('Pengeluaran', '2026-09-05', '1-1100', '6-2000', '15000.00', '=HYPERLINK("http://jahat")');
        $masuk = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $csv = $masuk->get('/kelola/laporan/pemasukan-pengeluaran/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect($csv->streamedContent())->toStartWith("\xEF\xBB\xBFArah,Kelompok,Kategori,")
            ->toContain('"Uang keluar","Biaya operasional",')
            ->toContain(',15000.00,');

        foreach (['pemasukan-pengeluaran', 'pemasukan-pengeluaran/ekspor'] as $laporan) {
            BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/laporan/{$laporan}")->assertForbidden();
        }

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        $milikB = LaporanUang($this, $b['Tenant']->Id, '2026-09-01', '2026-09-30');
        expect($milikB['Ringkasan']['Keluar'])->toBe('0.00')
            ->and($milikB['Keluar'])->toBe([])
            ->and($milikB['DiLuarUsahaRincian'])->toBe([]);
    });

    it('pembanding yang adil: hari lawan hari, pekan berjalan, bulan dan tahun berjalan sampai tanggal yang sama, bulan penuh', function (string $dari, string $sampai, array $harapan): void {
        expect(PemasukanPengeluaran::HitungPembanding($dari, $sampai))->toBe($harapan);
    })->with([
        'satu hari' => ['2026-11-10', '2026-11-10', ['2026-11-09', '2026-11-09', 'Kemarin']],
        'pekan berjalan (Senin sampai Rabu)' => ['2026-11-09', '2026-11-11', ['2026-11-02', '2026-11-04', 'MingguLalu']],
        'pekan penuh' => ['2026-11-02', '2026-11-08', ['2026-10-26', '2026-11-01', 'MingguLalu']],
        'bulan berjalan sampai tanggal 10' => ['2026-11-01', '2026-11-10', ['2026-10-01', '2026-10-10', 'BulanLalu']],
        'bulan berjalan lewat panjang bulan lalu' => ['2026-03-01', '2026-03-30', ['2026-02-01', '2026-02-28', 'BulanLalu']],
        'bulan penuh' => ['2026-03-01', '2026-03-31', ['2026-02-01', '2026-02-28', 'BulanLalu']],
        'awal tahun sampai hari ini' => ['2026-01-01', '2026-11-10', ['2025-01-01', '2025-11-10', 'TahunLalu']],
        'tahun penuh' => ['2026-01-01', '2026-12-31', ['2025-01-01', '2025-12-31', 'TahunLalu']],
        'Januari berjalan' => ['2026-01-01', '2026-01-15', ['2025-12-01', '2025-12-15', 'BulanLalu']],
        'rentang bebas' => ['2026-10-05', '2026-10-14', ['2026-09-25', '2026-10-04', 'PeriodeSebelumnya']],
    ]);

    it('penggolong: jenis sumber ke bahasa pemilik, akun beban gaji dan operasional, pajak, dan akun di luar usaha', function (): void {
        $akun = fn (string $kode, string $nama, TipeAkun $tipe): Akun => (new Akun)->forceFill(['Kode' => $kode, 'Nama' => $nama, 'Jenis' => $tipe]);

        expect(PenggolongUangKas::DariSumber(JenisSumberJurnal::Penjualan, true))->toBe(['Label' => 'Penjualan', 'Kelompok' => 'Penjualan', 'DiLuar' => false])
            ->and(PenggolongUangKas::DariSumber(JenisSumberJurnal::RekapGaji, false)['Kelompok'])->toBe(PenggolongUangKas::KELUAR_GAJI)
            ->and(PenggolongUangKas::DariSumber(JenisSumberJurnal::PenerimaanBarang, false)['Kelompok'])->toBe(PenggolongUangKas::KELUAR_BELANJA)
            ->and(PenggolongUangKas::DariSumber(JenisSumberJurnal::AsetTetap, false)['DiLuar'])->toBeTrue()
            ->and(PenggolongUangKas::DariSumber(null, true, 'SumberBaru')['Kelompok'])->toBe(PenggolongUangKas::BELUM_DIKELOMPOKKAN)
            ->and(PenggolongUangKas::DariAkun($akun('6-1000', 'Beban Gaji & Komisi', TipeAkun::Beban), false, false)['Kelompok'])->toBe(PenggolongUangKas::KELUAR_GAJI)
            ->and(PenggolongUangKas::DariAkun($akun('6-2300', 'Beban Sewa', TipeAkun::Beban), false, false))->toBe(['Label' => 'Beban Sewa', 'Kelompok' => PenggolongUangKas::KELUAR_OPERASIONAL, 'DiLuar' => false])
            ->and(PenggolongUangKas::DariAkun($akun('2-1300', 'Hutang PB1', TipeAkun::Kewajiban), false, true)['Kelompok'])->toBe(PenggolongUangKas::KELUAR_PAJAK)
            ->and(PenggolongUangKas::DariAkun($akun('3-1000', 'Modal Pemilik', TipeAkun::Ekuitas), true, false)['DiLuar'])->toBeTrue()
            ->and(PenggolongUangKas::DariAkun($akun('1-2000', 'Peralatan', TipeAkun::Aset), false, false)['Kelompok'])->toBe(PenggolongUangKas::DI_LUAR_ASET);
    });
});
