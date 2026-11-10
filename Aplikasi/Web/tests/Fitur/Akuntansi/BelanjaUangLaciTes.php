<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\PemasukanPengeluaran;
use App\Domain\Akuntansi\Kueri\SaldoUangLaci;
use App\Domain\Akuntansi\Layanan\PenggolongUangKas;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Pembelian\Aksi\BatalkanPenerimaanBarang;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStok;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Layanan\PenyediaTindakanPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * D-256 belanja stok dari uang laci: kasir mencatat kas keluar kategori "Belanja stok dari uang laci" di POS (uang masuk
 * akun penampung, bukan beban), lalu Belanja stok di back-office melunasi penampung itu. Kas tidak berkurang dua kali,
 * stok dan HPP terbentuk, saldo penampung = uang laci yang barangnya belum dicatat.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant dengan kasir, kategori laci bawaan, dan kas keluar `$nilai` yang sudah diterima server.
 *
 * @return array<string, mixed>
 */
function SiapkanUangLaci(TestCase $tes, string $nilai = '150000.00'): array
{
    $k = BantuanKasir::Siapkan($tes);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(SiapkanKategoriBiayaBawaan::class)->Jalankan();
    $kategori = KategoriKas::query()->where('Nama', SiapkanKategoriBiayaBawaan::KATEGORI_BELANJA_LACI)->sole();
    $shift = BantuanKasir::ItemBukaShift($k['Kasir']);
    $keluar = BantuanKasir::ItemMutasiKas($shift['Uuid'], $k['Kasir'], 'Keluar', $nilai, $kategori, ['Catatan' => 'Beli kopi 5 kg dan gula di pasar']);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$shift, $keluar]))->toBe([['Diterima', null], ['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $gudang = Gudang::query()->where('IdOutlet', $k['Outlet']->Id)->orderBy('Id')->firstOrFail();

    return $k + ['Gudang' => $gudang, 'Kategori' => $kategori];
}

function BelanjaDariLaci(array $k, Produk $produk, string $harga, string $jumlah = '1', bool $laci = true): DataBelanjaStok
{
    return new DataBelanjaStok(
        null,
        $k['Gudang']->Id,
        BantuanPembelian::Hari(),
        BantuanPembelian::AkunKas()->Uuid,
        'NOTA-PASAR-12',
        Uang::Nol(),
        'Belanja pasar pagi',
        [new DataBarisPenerimaanBarang(null, $produk->Uuid, null, Kuantitas::Dari($jumlah), Uang::Dari($harga), Uang::Nol())],
        null,
        $k['Pemilik']->Id,
        $laci,
    );
}

describe('D-256 uang laci dipakai belanja stok', function (): void {
    it('kas keluar kasir menambah saldo penampung, bukan beban, dan tampil terpisah di laporan Pemasukan & Pengeluaran', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');

        expect(app(SaldoUangLaci::class)->Outlet($k['Outlet']->Id)->KeString())->toBe('150000.00')
            ->and(app(SaldoUangLaci::class)->Total()->KeString())->toBe('150000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KliringBelanjaLaci))->toBe('150000.00');

        $hari = BantuanPembelian::Hari();
        $laporan = app(PemasukanPengeluaran::class)->Ambil(new SaringLaporanKeuangan($hari->startOfMonth()->toDateString(), $hari->toDateString(), null, null));
        $keluar = collect($laporan['KelompokKeluar'])->keyBy('Kelompok');

        expect($keluar[PenggolongUangKas::KELUAR_BELANJA_LACI]['Nilai'])->toBe('150000.00')
            ->and($keluar->has(PenggolongUangKas::KELUAR_LAIN))->toBeFalse()
            ->and($keluar->has(PenggolongUangKas::KELUAR_OPERASIONAL))->toBeFalse();
    });

    it('Belanja stok dari uang laci melunasi penampung: stok & HPP terbentuk, kas tidak berkurang lagi, invarian terjaga', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);
        $kasSebelum = BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet);

        $grn = app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '100000', '1'));

        expect($grn->BelanjaStok)->toBeTrue()
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet))->toBe($kasSebelum)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KliringBelanjaLaci))->toBe('50000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->toBe('100000.00')
            ->and(app(SaldoUangLaci::class)->Outlet($k['Outlet']->Id)->KeString())->toBe('50000.00')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);

        // Laporan: belanja lewat laci tidak menambah uang keluar (uangnya sudah keluar saat kasir mencatat).
        $hari = BantuanPembelian::Hari();
        $laporan = app(PemasukanPengeluaran::class)->Ambil(new SaringLaporanKeuangan($hari->startOfMonth()->toDateString(), $hari->toDateString(), null, null));
        expect($laporan['Ringkasan']['Keluar'])->toBe('150000.00');
    });

    it('belanja melebihi uang laci outlet ditolak tanpa meninggalkan penerimaan barang', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);

        $coba = fn () => app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '150001', '1'));

        expect($coba)->toThrow(PelanggaranAturanBisnis::class, 'kurang dari total belanja')
            ->and(PenerimaanBarang::query()->count())->toBe(0)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KliringBelanjaLaci))->toBe('150000.00');
    });

    it('tanpa uang laci yang dicatat kasir ditolak: saldo nol, atau penampung belum disiapkan (tenant lama)', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);
        $k = ['Gudang' => $t['Gudang'], 'Pemilik' => $t['Pemilik']];

        expect(fn () => app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '1000')))
            ->toThrow(PelanggaranAturanBisnis::class, 'kurang dari total belanja');

        PemetaanAkun::query()->where('Kunci', PeranAkun::KliringBelanjaLaci->value)->delete();

        expect(fn () => app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '1000')))
            ->toThrow(PelanggaranAturanBisnis::class, 'Belum ada uang laci');
    });

    it('membatalkan belanja dari uang laci mengembalikan saldo penampung', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);
        $grn = app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '100000', '1'));

        app(BatalkanPenerimaanBarang::class)->Jalankan($grn, 'Salah catat barang', $k['Pemilik']->Id);

        expect(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KliringBelanjaLaci))->toBe('150000.00')
            ->and(app(SaldoUangLaci::class)->Outlet($k['Outlet']->Id)->KeString())->toBe('150000.00')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('Kotak Tindakan mengingatkan selama saldo penampung belum nol dan hilang setelah barangnya dicatat', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);
        $konteks = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, BantuanPembelian::Hari());
        $ambil = fn (): ?App\Domain\Bersama\Tindakan\Data\DataButirTindakan => collect(app(PenyediaTindakanPembelian::class)->Kumpulkan($konteks))->firstWhere('kunci', 'belanja-stok.uang-laci');

        $butir = $ambil();
        expect($butir)->not->toBeNull()
            ->and($butir->jumlah)->toBe(1)
            ->and($butir->keterangan)->toContain('Rp 150.000')
            ->and($butir->rincian[0]->keterangan)->toContain('Rp 150.000');

        app(SimpanBelanjaStok::class)->Jalankan(BelanjaDariLaci($k, $kopi, '150000', '1'));

        expect($ambil()?->jumlah ?? 0)->toBe(0);
    });

    it('halaman Belanja stok memuat saldo uang laci per lokasi dan menyimpan lewat DariUangLaci', function (): void {
        $k = SiapkanUangLaci($this, '150000.00');
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Arabika Gayo Sangrai 1 kg']);
        $uuidGudang = $k['Gudang']->Uuid;
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/belanja-stok')->assertOk()->assertInertia(fn ($h) => $h
            ->where('UangLaci.Total', '150000.00')
            ->where("UangLaci.PerGudang.{$uuidGudang}", '150000.00'));

        $this->post('/kelola/pembelian/belanja-stok', [
            'UuidGudang' => $uuidGudang,
            'DariUangLaci' => true,
            'Tanggal' => BantuanPembelian::Hari()->toDateString(),
            'Ongkir' => '0',
            'Baris' => [['UuidProduk' => $kopi->Uuid, 'Jumlah' => '2', 'Harga' => '50000']],
        ])->assertRedirect()->assertSessionHas('Kilat');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(SaldoUangLaci::class)->Outlet($k['Outlet']->Id)->KeString())->toBe('50000.00');

        // Melebihi saldo: galat di bidang UuidAkun, tanpa penerimaan baru.
        $this->post('/kelola/pembelian/belanja-stok', [
            'UuidGudang' => $uuidGudang,
            'DariUangLaci' => true,
            'Tanggal' => BantuanPembelian::Hari()->toDateString(),
            'Baris' => [['UuidProduk' => $kopi->Uuid, 'Jumlah' => '1', 'Harga' => '90000']],
        ])->assertSessionHasErrors('UuidAkun');
        expect(PenerimaanBarang::query()->count())->toBe(1);
    });
});
