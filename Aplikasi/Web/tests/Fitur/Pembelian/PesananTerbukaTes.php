<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Pembelian\Layanan\PenyediaTindakanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-04 jalan masuk "Terima barang" yang menyatu dengan pesanan pembelian: tanpa pesanan terbuka langsung ke form tanpa
 * pesanan, ada pesanan terbuka tampil halaman pilih pesanan (sisa barang & nilai, terlambat), penerimaan tanpa pesanan
 * tetap lewat ?tanpa-pesanan=1 dengan saran pesanan terbuka dari pemasok yang sama, butir Kotak Tindakan untuk pesanan
 * yang terlambat, dan rantai permintaan → pesanan di detail pesanan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Pesanan disetujui 10 pcs minyak dari satu pemasok (belum ada penerimaan).
 *
 * @return array<string, mixed>
 */
function SiapkanPesananTerbuka(): array
{
    $t = BantuanPembelian::SiapkanTenant();
    $pemasok = BantuanPembelian::BuatPemasok('PT Minyak Nusantara Sejahtera');
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$minyak, '10', '31500']], $t['Pemilik']->Id);

    return $t + ['Pemasok' => $pemasok, 'Minyak' => $minyak, 'Po' => $po];
}

/** Atur perkiraan tiba lewat basis data (PO disetujui sudah terkunci dari perubahan biasa). */
function AturPerkiraanTiba(PesananPembelian $po, ?string $tanggal): void
{
    DB::table('PesananPembelian')->where('Id', $po->Id)->update(['PerkiraanTiba' => $tanggal]);
}

describe('F-04 Terima barang dari pesanan terbuka', function (): void {
    it('tanpa pesanan terbuka: langsung ke form tanpa pesanan, tanpa langkah tambahan', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/pembelian/penerimaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->where('Mode', 'Penerimaan')->where('Pesanan', null)->has('PesananTerbuka', 0));
    });

    it('ada pesanan terbuka: tampil halaman pilih pesanan lengkap dengan sisa barang dan nilai', function (): void {
        $k = SiapkanPesananTerbuka();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/penerimaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Pilih')
            ->has('PesananTerbuka', 1)
            ->where('PesananTerbuka.0.Uuid', $k['Po']->Uuid)
            ->where('PesananTerbuka.0.NamaPemasok', 'PT Minyak Nusantara Sejahtera')
            ->where('PesananTerbuka.0.UuidPemasok', $k['Pemasok']->Uuid)
            ->where('PesananTerbuka.0.JumlahBarisSisa', 1)
            ->where('PesananTerbuka.0.NilaiSisa', '315000.00')
            ->where('PesananTerbuka.0.Terlambat', false));
    });

    it('?pesanan= tetap membuka form terisi, dan ?tanpa-pesanan=1 membuka form tanpa pesanan berikut saran', function (): void {
        $k = SiapkanPesananTerbuka();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get("/kelola/pembelian/penerimaan/buat?pesanan={$k['Po']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->where('Pesanan.Uuid', $k['Po']->Uuid)->has('PesananTerbuka', 0));
        $this->get('/kelola/pembelian/penerimaan/buat?tanpa-pesanan=1')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->where('Pesanan', null)->has('PesananTerbuka', 1)->where('PesananTerbuka.0.Nomor', $k['Po']->Nomor));
        $this->get('/kelola/pembelian/belanja-stok')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Mode', 'BelanjaStok')->has('PesananTerbuka', 1));
    });

    it('pesanan diterima sebagian tetap muncul dengan sisa, diterima penuh tidak muncul lagi', function (): void {
        $k = SiapkanPesananTerbuka();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        BantuanPembelian::TerimaDariPo($k['Po'], ['4'], $k['Pemilik']->Id);

        $this->get('/kelola/pembelian/penerimaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Pilih')->where('PesananTerbuka.0.LabelStatus', 'Diterima sebagian')->where('PesananTerbuka.0.NilaiSisa', '189000.00'));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPembelian::TerimaDariPo($k['Po']->refresh(), ['6'], $k['Pemilik']->Id);

        $this->get('/kelola/pembelian/penerimaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->has('PesananTerbuka', 0));
    });

    it('terlambat: lewat perkiraan tiba, atau lama menunggu tanpa perkiraan. Draf dan batal tidak ikut', function (): void {
        $k = SiapkanPesananTerbuka();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $dilihat = function (): array {
            $pesanan = [];
            $this->get('/kelola/pembelian/penerimaan/buat')->assertInertia(function (AssertableInertia $h) use (&$pesanan): void {
                $pesanan = $h->toArray()['props']['PesananTerbuka'];
            });

            return $pesanan[0];
        };

        AturPerkiraanTiba($k['Po'], BantuanPembelian::Hari(3)->toDateString());
        expect($dilihat())->toMatchArray(['Terlambat' => true, 'HariTerlambat' => 3]);

        AturPerkiraanTiba($k['Po'], BantuanPembelian::Hari()->addDays(2)->toDateString());
        expect($dilihat())->toMatchArray(['Terlambat' => false, 'HariTerlambat' => 0]);

        AturPerkiraanTiba($k['Po'], null);
        expect($dilihat()['Terlambat'])->toBeFalse();
        DB::table('PesananPembelian')->where('Id', $k['Po']->Id)->update(['Tanggal' => BantuanPembelian::Hari(10)->toDateString()]);
        expect($dilihat())->toMatchArray(['Terlambat' => true, 'PerkiraanTiba' => null]);

        BantuanPembelian::BuatPo($k['Pemasok'], $k['Gudang'], [[$k['Minyak'], '1', '31500']], $k['Pemilik']->Id);
        $this->get('/kelola/pembelian/penerimaan/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('PesananTerbuka', 1));
    });

    it('isolasi tenant dan batas outlet: pesanan tenant lain atau outlet lain tidak muncul', function (): void {
        $a = SiapkanPesananTerbuka();

        $b = BantuanPembelian::SiapkanTenant(namaUsaha: 'Warung Kelontong Berkah Sejahtera');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get('/kelola/pembelian/penerimaan/buat')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->has('PesananTerbuka', 0));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        $staf = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::StafPembelian, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $staf->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::StafPembelian)->Id]);
        BantuanOrganisasi::Masuk($this, $staf, $a['Tenant']->Id);
        $this->get('/kelola/pembelian/penerimaan/buat')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->has('PesananTerbuka', 0));
    });

    it('Kotak Tindakan menghitung pesanan yang terlambat saja dan menaut ke form terima dari pesanan', function (): void {
        $k = SiapkanPesananTerbuka();
        $konteks = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, BantuanPembelian::Hari());
        $ambil = fn (): ?DataButirTindakan => collect(app(PenyediaTindakanPembelian::class)->Kumpulkan($konteks))->firstWhere('kunci', 'pesanan-pembelian.menunggu-barang');

        AturPerkiraanTiba($k['Po'], BantuanPembelian::Hari()->addDays(5)->toDateString());
        expect($ambil()?->jumlah ?? 0)->toBe(0);

        AturPerkiraanTiba($k['Po'], BantuanPembelian::Hari(2)->toDateString());
        $butir = $ambil();
        expect($butir?->jumlah)->toBe(1)
            ->and($butir->rincian[0]->tautan)->toBe("/kelola/pembelian/penerimaan/buat?pesanan={$k['Po']->Uuid}")
            ->and($butir->rincian[0]->keterangan)->toContain('PT Minyak Nusantara Sejahtera')->toContain('terlambat 2 hari')->toContain('Rp 315.000');

        BantuanPembelian::TerimaDariPo($k['Po'], ['10'], $k['Pemilik']->Id);
        expect($ambil()?->jumlah ?? 0)->toBe(0);
    });

    it('detail pesanan menampilkan permintaan asalnya (rantai dokumen)', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $pemasok = BantuanPembelian::BuatPemasok('PT Minyak Nusantara Sejahtera');
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/pembelian/permintaan', [
            'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => BantuanPembelian::Hari()->toDateString(),
            'Baris' => [['UuidProduk' => $minyak->Uuid, 'Jumlah' => '6', 'Harga' => '31500']],
        ])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po", ['UuidPemasok' => $pemasok->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $po = PesananPembelian::query()->sole();

        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Detail')->where('Permintaan.Uuid', $pr->Uuid)->where('Permintaan.Nomor', $pr->Nomor));

        $lain = BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$minyak, '2', '31500']], $t['Pemilik']->Id);
        $this->get("/kelola/pembelian/pesanan/{$lain->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Permintaan', null));
    });
});
