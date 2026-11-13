<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-04, D-287: dokumen turunan pembelian yang dibuka tanpa dokumen asal (retur, biaya tambahan, nota debit, faktur)
 * tidak lagi melempar pengguna ke daftar dengan pesan galat. Halaman "pilih dokumen" menampilkan dokumen hulu yang
 * nyata, satu ketukan membuka form terisi. Form PO menawarkan permintaan yang sudah disetujui. Isolasi tenant dan batas
 * outlet berlaku, dan jalan lama (`?penerimaan=`, `?faktur=`, `?manual=1`) tetap bekerja.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Satu penerimaan tanpa PO dari satu pemasok (10 pcs minyak).
 *
 * @return array<string, mixed>
 */
function SiapkanPenerimaanHulu(): array
{
    $t = BantuanPembelian::SiapkanTenant();
    $pemasok = BantuanPembelian::BuatPemasok('PT Minyak Nusantara Sejahtera');
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$minyak, '10', '31500']], $t['Pemilik']->Id);

    return $t + ['Pemasok' => $pemasok, 'Minyak' => $minyak, 'Grn' => $grn];
}

describe('F-04 halaman pilih dokumen asal (D-287)', function (): void {
    it('retur: tanpa penerimaan asal tampil pilihan penerimaan yang masih ada barangnya, ?penerimaan= tetap membuka form', function (): void {
        $k = SiapkanPenerimaanHulu();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/retur/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Bersama/PilihDokumen')
            ->where('Teks.Judul', 'Retur barang ke pemasok')
            ->where('Teks.LabelAksi', 'Retur barang')
            ->has('Butir', 1)
            ->where('Butir.0.Uuid', $k['Grn']->Uuid)
            ->where('Butir.0.Nomor', $k['Grn']->Nomor)
            ->where('Butir.0.Pihak', 'PT Minyak Nusantara Sejahtera')
            ->where('Butir.0.Tautan', "/kelola/pembelian/retur/buat?penerimaan={$k['Grn']->Uuid}")
            ->where('Butir.0.Lencana', null)
            ->where('Alternatif', null));
        $this->get("/kelola/pembelian/retur/buat?penerimaan={$k['Grn']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Retur/Form'));

        // Penerimaan yang seluruh barangnya sudah diretur, atau sudah dibatalkan, tidak ditawarkan lagi.
        DB::table('PenerimaanBarangDetail')->where('IdPenerimaanBarang', $k['Grn']->Id)->update(['JumlahDiretur' => DB::raw('JumlahDasar')]);
        $this->get('/kelola/pembelian/retur/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('Butir', 0));

        DB::table('PenerimaanBarangDetail')->where('IdPenerimaanBarang', $k['Grn']->Id)->update(['JumlahDiretur' => 0]);
        DB::table('PenerimaanBarang')->where('Id', $k['Grn']->Id)->update(['Status' => StatusDokumenTerposting::Dibatalkan->value]);
        $this->get('/kelola/pembelian/retur/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('Butir', 0));
    });

    it('belanja stok tanpa pemasok ikut bisa diretur dan diberi lencana', function (): void {
        $k = SiapkanPenerimaanHulu();
        $grn = BantuanPembelian::TerimaTanpaPo(null, $k['Gudang'], [[$k['Minyak'], '3', '30000']], $k['Pemilik']->Id);
        DB::table('PenerimaanBarang')->where('Id', $grn->Id)->update(['BelanjaStok' => true]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/retur/buat')->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Butir', 2)
            ->where('Butir.0.Uuid', $grn->Uuid)
            ->where('Butir.0.Pihak', 'Belanja tanpa pemasok')
            ->where('Butir.0.Lencana.Teks', 'Belanja stok'));
    });

    it('biaya tambahan: tanpa penerimaan asal tampil pilihan penerimaan, ?penerimaan= tetap membuka form', function (): void {
        $k = SiapkanPenerimaanHulu();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/biaya-tambahan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Bersama/PilihDokumen')
            ->where('Teks.Judul', 'Biaya tambahan pembelian')
            ->where('Teks.LabelAksi', 'Catat biaya')
            ->has('Butir', 1)
            ->where('Butir.0.Tautan', "/kelola/pembelian/biaya-tambahan/buat?penerimaan={$k['Grn']->Uuid}"));
        $this->get("/kelola/pembelian/biaya-tambahan/buat?penerimaan={$k['Grn']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/BiayaTambahan/Buat'));
    });

    it('nota debit: tanpa faktur asal tampil pilihan faktur yang belum dibatalkan dengan sisa hutangnya', function (): void {
        $k = SiapkanPenerimaanHulu();
        $faktur = BantuanPembelian::Fakturkan($k['Pemasok'], [$k['Grn']], $k['Pemilik']->Id);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/nota-debit/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Bersama/PilihDokumen')
            ->where('Teks.Judul', 'Nota debit pembelian')
            ->has('Butir', 1)
            ->where('Butir.0.Uuid', $faktur->Uuid)
            ->where('Butir.0.Pihak', 'PT Minyak Nusantara Sejahtera')
            ->where('Butir.0.Lencana.Teks', 'Belum dibayar')
            ->where('Butir.0.Tautan', "/kelola/pembelian/nota-debit/buat?faktur={$faktur->Uuid}")
            ->where('Butir.0.Rincian', fn ($r) => str_contains((string) $r, 'sisa hutang Rp 315.000')));
        $this->get("/kelola/pembelian/nota-debit/buat?faktur={$faktur->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/NotaDebit/Buat'));

        DB::table('FakturPembelian')->where('Id', $faktur->Id)->update(['Status' => StatusFakturPembelian::Dibatalkan->value]);
        $this->get('/kelola/pembelian/nota-debit/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('Butir', 0));
    });

    it('faktur: tanpa pemasok maupun penerimaan tampil penerimaan belum difakturkan dari semua pemasok, jalan lama tetap ada', function (): void {
        $k = SiapkanPenerimaanHulu();
        $pemasokLain = BantuanPembelian::BuatPemasok('CV Gula Pasir Makmur');
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih Lokal 1 kg']);
        $grnLain = BantuanPembelian::TerimaTanpaPo($pemasokLain, $k['Gudang'], [[$gula, '20', '16500']], $k['Pemilik']->Id);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pembelian/faktur/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Bersama/PilihDokumen')
            ->where('Teks.Judul', 'Faktur pembelian')
            ->has('Butir', 2)
            ->where('Butir.0.Uuid', $k['Grn']->Uuid)
            ->where('Butir.1.Uuid', $grnLain->Uuid)
            ->where('Butir.1.Pihak', 'CV Gula Pasir Makmur')
            ->where('Butir.1.Tautan', "/kelola/pembelian/faktur/buat?penerimaan={$grnLain->Uuid}")
            ->where('Alternatif.Tautan.0.Href', '/kelola/pembelian/faktur/buat?manual=1'));

        // Jalan lama: pilih pemasok sendiri, atau datang dengan penerimaan/pemasok.
        $this->get('/kelola/pembelian/faktur/buat?manual=1')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Form')->where('UuidPemasok', null));
        $this->get("/kelola/pembelian/faktur/buat?penerimaan={$k['Grn']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Form')->where('UuidPemasok', $k['Pemasok']->Uuid));
        $this->get("/kelola/pembelian/faktur/buat?pemasok={$pemasokLain->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Form'));

        // Penerimaan yang sudah difakturkan hilang dari pilihan. Tanpa sisa, langsung ke form seperti dulu.
        BantuanPembelian::Fakturkan($k['Pemasok'], [$k['Grn']], $k['Pemilik']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPembelian::Fakturkan($pemasokLain, [$grnLain], $k['Pemilik']->Id);
        $this->get('/kelola/pembelian/faktur/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Form'));
    });

    it('isolasi tenant dan batas outlet: dokumen tenant lain atau outlet lain tidak ditawarkan', function (): void {
        $a = SiapkanPenerimaanHulu();
        BantuanPembelian::Fakturkan($a['Pemasok'], [$a['Grn']], $a['Pemilik']->Id);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);

        $b = BantuanPembelian::SiapkanTenant(namaUsaha: 'Warung Kelontong Berkah Sejahtera');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);

        foreach (['retur', 'biaya-tambahan'] as $jalur) {
            $this->get("/kelola/pembelian/{$jalur}/buat")->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Bersama/PilihDokumen')->has('Butir', 0));
        }
        $this->get('/kelola/pembelian/nota-debit/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('Butir', 0));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        $staf = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::StafPembelian, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $staf->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::StafPembelian)->Id]);
        BantuanOrganisasi::Masuk($this, $staf, $a['Tenant']->Id);

        foreach (['retur', 'biaya-tambahan', 'nota-debit'] as $jalur) {
            $this->get("/kelola/pembelian/{$jalur}/buat")->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Bersama/PilihDokumen')->has('Butir', 0));
        }
    });
});

describe('F-04 form PO menawarkan permintaan yang sudah disetujui (D-287)', function (): void {
    it('permintaan disetujui ditawarkan di form PO baru dengan tautan ke rinciannya, dan hilang setelah dijadikan PO', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $pemasok = BantuanPembelian::BuatPemasok('PT Beras Delanggu Sejahtera');
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Delanggu Premium Pulen 1 kg']);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/pembelian/pesanan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Form')->where('Mode', 'Buat')->has('PermintaanSiap', 0));

        $kiriman = [
            'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => BantuanPembelian::Hari()->toDateString(), 'DibutuhkanPada' => BantuanPembelian::Hari()->addDays(3)->toDateString(),
            'Baris' => [['UuidProduk' => $beras->Uuid, 'Jumlah' => '4', 'Harga' => '135000']],
        ];
        $this->post('/kelola/pembelian/permintaan', $kiriman)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        // Masih draf: belum ditawarkan. Disetujui: ditawarkan.
        $this->get('/kelola/pembelian/pesanan/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('PermintaanSiap', 0));
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        $this->get('/kelola/pembelian/pesanan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('PermintaanSiap', 1)
            ->where('PermintaanSiap.0.Uuid', $pr->Uuid)
            ->where('PermintaanSiap.0.Nomor', $pr->Nomor)
            ->where('PermintaanSiap.0.Pihak', 'Pemasok belum dipilih')
            ->where('PermintaanSiap.0.Tautan', "/kelola/pembelian/permintaan/{$pr->Uuid}")
            ->where('PermintaanSiap.0.Rincian', fn ($r) => str_contains((string) $r, '1 barang')));

        // Mode ubah tidak menawarkan apa pun, dan setelah dijadikan PO permintaannya tidak ditawarkan lagi.
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po", ['UuidPemasok' => $pemasok->Uuid])->assertSessionHasNoErrors();
        $this->get('/kelola/pembelian/pesanan/buat')->assertInertia(fn (AssertableInertia $h) => $h->has('PermintaanSiap', 0));
    });
});
