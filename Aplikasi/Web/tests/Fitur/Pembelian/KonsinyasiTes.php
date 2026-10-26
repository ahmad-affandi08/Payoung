<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Laporan\Kueri\LaporanStok;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Pembelian\Aksi\BatalkanPembayaranKonsinyasi;
use App\Domain\Pembelian\Aksi\CatatDokumenKonsinyasi;
use App\Domain\Pembelian\Aksi\SimpanPembayaranKonsinyasi;
use App\Domain\Pembelian\Data\DataBarisKonsinyasi;
use App\Domain\Pembelian\Data\DataDokumenKonsinyasi;
use App\Domain\Pembelian\Data\DataPembayaranKonsinyasi;
use App\Domain\Pembelian\Enum\JenisDokumenKonsinyasi;
use App\Domain\Pembelian\Kueri\HutangKonsinyasi;
use App\Domain\Pembelian\Model\DokumenKonsinyasi;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PembayaranKonsinyasi;
use App\Domain\Pembelian\Model\PenitipProduk;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05i konsinyasi (v3.40): titipan masuk dinilai harga titip tanpa jurnal; terjual → J-05.7 (Dr HPP / Cr Hutang
 * Konsinyasi) lewat jurnal penjualan biasa; retur ke penitip tanpa jurnal; setoran Dr Hutang Konsinyasi / Cr kas
 * dibatasi sisa hutang penitip; pembatalan membalik. Satu produk satu penitip. Invarian (termasuk hutang konsinyasi)
 * di tiap skenario.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatTitipan(Gudang $gudang, Pemasok $penitip, JenisDokumenKonsinyasi $jenis, array $baris, int $idPengguna): DokumenKonsinyasi
{
    $infoGudang = app(InfoGudang::class)->AmbilBanyak([$gudang->Id])[$gudang->Id];

    return app(CatatDokumenKonsinyasi::class)->Jalankan(new DataDokumenKonsinyasi(
        $jenis,
        $penitip->Uuid,
        $infoGudang,
        HariBisnisKonsinyasi($gudang->IdOutlet),
        array_map(fn (array $b): DataBarisKonsinyasi => new DataBarisKonsinyasi($b[0]->Uuid, Kuantitas::Dari($b[1]), isset($b[2]) ? Uang::Dari($b[2]) : null), $baris),
        null,
        $idPengguna,
    ));
}

function Setor(Pemasok $penitip, string $jumlah, int $idPengguna): PembayaranKonsinyasi
{
    return app(SimpanPembayaranKonsinyasi::class)->Jalankan(new DataPembayaranKonsinyasi(
        $penitip->Uuid,
        BantuanPembelian::AkunKas()->Uuid,
        HariBisnisKonsinyasi(),
        Uang::Dari($jumlah),
        'Setoran mingguan',
        $idPengguna,
    ));
}

/** Tanggal bisnis outlet (jam tutup buku ikut) atau tenant bila tanpa outlet. */
function HariBisnisKonsinyasi(?int $idOutlet = null): CarbonImmutable
{
    return app(TanggalBisnisOutlet::class)->Hitung($idOutlet);
}

function ProdukTitipan(string $nama = 'Keripik Singkong Pedas Bu Sari 200 gram'): Produk
{
    return BantuanKatalog::BuatProduk(['Nama' => $nama, 'Jenis' => JenisProduk::Konsinyasi], '15000.00');
}

describe('F-05i konsinyasi', function (): void {
    it('titipan masuk tanpa jurnal, terjual = J-05.7, setoran & pembatalannya, retur ke penitip. Hutang & invarian konsisten', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $penitip = BantuanPembelian::BuatPemasok('Bu Sari Keripik Rumahan', idPengguna: $k['Pemilik']->Id);
        $keripik = ProdukTitipan();
        $jurnalAwal = Jurnal::query()->count();

        $masuk = CatatTitipan($k['Gudang'], $penitip, JenisDokumenKonsinyasi::Masuk, [[$keripik, '24', '9000']], $k['Pemilik']->Id);

        expect($masuk->Nomor)->toStartWith('KS/')
            ->and((string) $masuk->TotalNilai)->toBe('216000.00')
            ->and(Jurnal::query()->count())->toBe($jurnalAwal)
            ->and(PenitipProduk::query()->where('IdProduk', $keripik->Id)->value('IdPemasok'))->toBe($penitip->Id)
            ->and(MutasiStok::query()->where('IdProduk', $keripik->Id)->sole()->JenisMutasi)->toBe(JenisMutasi::KonsinyasiMasuk)
            ->and(SaldoStok::query()->where('IdProduk', $keripik->Id)->sole()->JumlahTersedia)->toBe('24.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        // Nilai persediaan laporan stok tidak memuat barang titipan (bukan aset toko).
        $laporan = app(LaporanStok::class)->NilaiPersediaan(CarbonImmutable::now('Asia/Jakarta'), null);
        expect($laporan['Total']['Nilai'])->toBe('0.00');

        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $keripik, 'Jumlah' => '2', 'Harga' => '15000.00']]]);

        expect(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::HutangKonsinyasi))->toBe('-18000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::Hpp))->toBe('18000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->toBe('0.00')
            ->and(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('18000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        expect(B::KodeGalat(fn () => Setor($penitip, '18000.01', $k['Pemilik']->Id)))->toBe('MelebihiHutang');
        $setoran = Setor($penitip, '10000', $k['Pemilik']->Id);

        expect($setoran->Nomor)->toStartWith('BK/')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::HutangKonsinyasi))->toBe('-8000.00')
            ->and(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('8000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        $batal = app(BatalkanPembayaranKonsinyasi::class)->Jalankan($setoran, 'Salah pilih akun kas', $k['Pemilik']->Id);

        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and($batal->IdJurnalPembatalan)->not->toBeNull()
            ->and(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('18000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        expect(B::KodeGalat(fn () => CatatTitipan($k['Gudang'], $penitip, JenisDokumenKonsinyasi::Retur, [[$keripik, '23']], $k['Pemilik']->Id)))->toBe('StokTidakCukup');
        $jurnalSebelumRetur = Jurnal::query()->count();
        $retur = CatatTitipan($k['Gudang'], $penitip, JenisDokumenKonsinyasi::Retur, [[$keripik, '20']], $k['Pemilik']->Id);

        expect((string) $retur->TotalNilai)->toBe('180000.00')
            ->and((string) $retur->Detail()->sole()->HargaSatuan)->toBe('9000.000000')
            ->and(Jurnal::query()->count())->toBe($jurnalSebelumRetur)
            ->and(SaldoStok::query()->where('IdProduk', $keripik->Id)->sole()->JumlahTersedia)->toBe('2.0000')
            ->and(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('18000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('menolak produk bukan konsinyasi, tanpa harga titip, produk milik penitip lain, dan retur barang yang tidak dititipkan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $buSari = BantuanPembelian::BuatPemasok('Bu Sari Keripik Rumahan', idPengguna: $k['Pemilik']->Id);
        $pakDadang = BantuanPembelian::BuatPemasok('Pak Dadang Kue Basah', idPengguna: $k['Pemilik']->Id);
        $keripik = ProdukTitipan();
        $kue = ProdukTitipan('Kue Lapis Legit Potong');
        $biasa = BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Botol 600 ml']);
        $catat = fn (Pemasok $p, JenisDokumenKonsinyasi $j, array $b) => B::KodeGalat(fn () => CatatTitipan($k['Gudang'], $p, $j, $b, $k['Pemilik']->Id));

        expect($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$biasa, '5', '3000']]))->toBe('BukanProdukKonsinyasi')
            ->and($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$keripik, '5']]))->toBe('HargaTitipWajib')
            ->and($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$keripik, '0', '9000']]))->toBe('JumlahTidakValid')
            ->and($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$keripik, '1.5', '9000']]))->toBe('JumlahTidakValid')
            ->and($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$keripik, '1', '9000'], [$keripik, '2', '9000']]))->toBe('ProdukGanda')
            ->and($catat($buSari, JenisDokumenKonsinyasi::Masuk, [[$keripik, '10', '9000']]))->toBeNull()
            ->and($catat($pakDadang, JenisDokumenKonsinyasi::Masuk, [[$keripik, '3', '8500']]))->toBe('ProdukMilikPenitipLain')
            ->and($catat($pakDadang, JenisDokumenKonsinyasi::Retur, [[$kue, '1']]))->toBe('StokTidakCukup')
            ->and(DokumenKonsinyasi::query()->count())->toBe(1)
            ->and(B::KodeGalat(fn () => Setor($pakDadang, '1000', $k['Pemilik']->Id)))->toBe('MelebihiHutang');

        // Retur ke penitip lain dari produk yang punya stok = bukan titipannya.
        CatatTitipan($k['Gudang'], $pakDadang, JenisDokumenKonsinyasi::Masuk, [[$kue, '4', '12000']], $k['Pemilik']->Id);
        expect($catat($buSari, JenisDokumenKonsinyasi::Retur, [[$kue, '1']]))->toBe('ProdukMilikPenitipLain')
            ->and(app(HutangKonsinyasi::class)->Ambil()[$pakDadang->Id]['JumlahProduk'])->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('retur & void penjualan barang titipan membalik hutang konsinyasi', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $penitip = BantuanPembelian::BuatPemasok('Bu Sari Keripik Rumahan', idPengguna: $k['Pemilik']->Id);
        $keripik = ProdukTitipan();
        CatatTitipan($k['Gudang'], $penitip, JenisDokumenKonsinyasi::Masuk, [[$keripik, '10', '9000']], $k['Pemilik']->Id);
        $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $keripik, 'Jumlah' => '3', 'Harga' => '15000.00']]]);

        expect(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('27000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(app(HutangKonsinyasi::class)->AmbilSisa($penitip->Id)->KeString())->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::HutangKonsinyasi))->toBe('0.00')
            ->and(SaldoStok::query()->where('IdProduk', $keripik->Id)->sole()->JumlahTersedia)->toBe('10.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('halaman konsinyasi: penitip, dokumen, form, rincian. Simpan titipan & setoran lewat HTTP. Kasir tanpa izin. Tenant lain 404', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $penitip = BantuanPembelian::BuatPemasok('Bu Sari Keripik Rumahan', idPengguna: $k['Pemilik']->Id);
        $keripik = ProdukTitipan();
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post('/kelola/pembelian/konsinyasi', [
            'Jenis' => 'Masuk',
            'UuidPemasok' => $penitip->Uuid,
            'UuidGudang' => $k['Gudang']->Uuid,
            'Tanggal' => HariBisnisKonsinyasi($k['Outlet']->Id)->format('Y-m-d'),
            'Baris' => [['UuidProduk' => $keripik->Uuid, 'Jumlah' => '12', 'HargaTitip' => '9000']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $dokumen = DokumenKonsinyasi::query()->sole();

        $this->getJson('/kelola/pembelian/konsinyasi/produk/cari?kata=Keripik&gudang='.$k['Gudang']->Uuid)
            ->assertOk()->assertJsonPath('Data.0.Uuid', $keripik->Uuid)->assertJsonPath('Data.0.SaldoDiGudang', '12.0000');
        $this->get('/kelola/pembelian/konsinyasi')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Konsinyasi/Daftar')->where('Penitip.0.Nama', 'Bu Sari Keripik Rumahan')->where('TotalSisa', '0.00'));
        $this->get('/kelola/pembelian/konsinyasi/dokumen')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Konsinyasi/Dokumen/Daftar')->where('Dokumen.Data.0.Nomor', $dokumen->Nomor));
        $this->get('/kelola/pembelian/konsinyasi/buat?jenis=Retur')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Konsinyasi/Form')->where('Jenis', 'Retur'));
        $this->get("/kelola/pembelian/konsinyasi/dokumen/{$dokumen->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Konsinyasi/Dokumen/Detail')->where('Baris.0.Nilai', '108000.00'));

        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $keripik, 'Jumlah' => '1', 'Harga' => '15000.00']]]);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->post("/kelola/pembelian/konsinyasi/penitip/{$penitip->Uuid}/setoran", [
            'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
            'Tanggal' => HariBisnisKonsinyasi()->format('Y-m-d'),
            'Jumlah' => '9000',
        ])->assertRedirect("/kelola/pembelian/konsinyasi/penitip/{$penitip->Uuid}")->assertSessionHasNoErrors();
        $this->get("/kelola/pembelian/konsinyasi/penitip/{$penitip->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Konsinyasi/Penitip/Detail')
            ->where('Hutang.Terjual', '9000.00')->where('Hutang.Sisa', '0.00')->where('Produk.0.Terjual', '1.0000')->where('Produk.0.Saldo', '11.0000')->where('Setoran.0.Status', 'Diposting'));
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $setoran = PembayaranKonsinyasi::query()->sole();
        $this->get("/kelola/pembelian/konsinyasi/setoran/{$setoran->Uuid}")->assertRedirect("/kelola/pembelian/konsinyasi/penitip/{$penitip->Uuid}");
        $this->post("/kelola/pembelian/konsinyasi/setoran/{$setoran->Uuid}/batalkan", ['Alasan' => 'Salah tanggal'])->assertRedirect();
        expect($setoran->fresh()?->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pembelian/konsinyasi')->assertForbidden();

        $lain = BantuanOrganisasi::BuatTenant('Warung Sebelah');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/pembelian/konsinyasi/dokumen/{$dokumen->Uuid}")->assertNotFound();
        $this->get("/kelola/pembelian/konsinyasi/penitip/{$penitip->Uuid}")->assertNotFound();
    });
});
