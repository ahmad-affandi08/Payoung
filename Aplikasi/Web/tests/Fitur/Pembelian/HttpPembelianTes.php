<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\ReturPembelian;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-04 fase 1 HTTP (routes/Pembelian.php): alur lengkap lewat rute back-office (pemasok → PO → persetujuan →
 * penerimaan → faktur → pembayaran → retur), setiap halaman Inertia ada di disk (ensure_pages_exist), TabelData JSON,
 * izin pembelian.kelola / pembelian.po.setujui, batas outlet akses, dan isolasi tenant (dokumen tenant lain = 404).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-04 HTTP pembelian', function (): void {
    it('alur lengkap lewat rute: pemasok, PO, persetujuan, terima, faktur, bayar, retur. Semua halaman tampil', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/pembelian/pemasok/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pemasok/Buat'));
        $this->post('/kelola/pembelian/pemasok', [
            'Kode' => 'SUP-001', 'Nama' => 'PT Sumber Pangan Nusantara', 'Pkp' => false, 'TerminHari' => 30,
        ])->assertSessionHasNoErrors()->assertRedirect('/kelola/pembelian/pemasok');
        $pemasok = Pemasok::query()->where('Kode', 'SUP-001')->sole();

        $this->get('/kelola/pembelian/pesanan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Form')->where('Mode', 'Buat')->has('OpsiPemasok', 1));

        $this->post('/kelola/pembelian/pesanan', [
            'UuidPemasok' => $pemasok->Uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'Ongkir' => '0', 'Baris' => [['UuidProduk' => $minyak->Uuid, 'Jumlah' => '24', 'Harga' => '31500']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $po = PesananPembelian::query()->sole();
        expect($po->Status)->toBe(StatusPesananPembelian::Draf);

        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Detail')->where('Tindakan.Ajukan', true)->where('Pesanan.Total', '756000.00'));
        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Form')->where('Mode', 'Ubah'));
        $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/ajukan")->assertRedirect("/kelola/pembelian/pesanan/{$po->Uuid}");
        expect($po->refresh()->Status)->toBe(StatusPesananPembelian::Disetujui);
        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pesanan/Cetak')->has('Baris', 1));

        $this->get("/kelola/pembelian/penerimaan/buat?pesanan={$po->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')
            ->where('Mode', 'Penerimaan')
            ->where('Pesanan.TanggalPenerimaan', $po->AmbilTanggalPenerimaanBawaan()->format('Y-m-d'))
            ->has('Pesanan.Baris', 1));
        $idBaris = $po->Detail()->value('Id');
        $this->post('/kelola/pembelian/penerimaan', [
            'UuidPesananPembelian' => $po->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'Baris' => [['IdBarisPesanan' => $idBaris, 'Jumlah' => '24']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $grn = PenerimaanBarang::query()->sole();
        expect($po->refresh()->Status)->toBe(StatusPesananPembelian::Diterima);
        // Penolakan dikirim sebagai galat Umum (bukan Kilat "Berhasil"): PO Diterima tidak bisa menerima/diubah lagi.
        $this->get("/kelola/pembelian/penerimaan/buat?pesanan={$po->Uuid}")
            ->assertRedirect("/kelola/pembelian/pesanan/{$po->Uuid}")->assertSessionHasErrors('Umum')->assertSessionMissing('Kilat');
        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}/ubah")
            ->assertRedirect("/kelola/pembelian/pesanan/{$po->Uuid}")->assertSessionHasErrors('Umum')->assertSessionMissing('Kilat');
        $this->get('/kelola/pembelian/retur/buat')
            ->assertRedirect('/kelola/pembelian/penerimaan')->assertSessionHasErrors('Umum')->assertSessionMissing('Kilat');
        $this->get("/kelola/pembelian/penerimaan/{$grn->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Detail')->where('Tindakan.Fakturkan', true)->where('Penerimaan.TotalNilai', '756000.00')
            // PRD-10: pintasan Cetak label barang diterima = satu stiker per satuan dasar (24 pcs).
            ->where('Baris.0.UuidSatuanLabel', ProdukSatuan::query()->where('IdProduk', $minyak->Id)->value('Uuid'))
            ->where('Baris.0.JumlahLabel', 24));
        // Jumlah dasar pecahan (mis. kilogram) tidak punya jumlah stiker yang bermakna: pintasan tidak ditawarkan.
        PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->update(['JumlahDasar' => '2.5000']);
        $this->get("/kelola/pembelian/penerimaan/{$grn->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.UuidSatuanLabel', null)->where('Baris.0.JumlahLabel', null));
        PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->update(['JumlahDasar' => '24.0000']);

        $this->get("/kelola/pembelian/faktur/buat?penerimaan={$grn->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Form')->where('UuidPemasok', $pemasok->Uuid)->has('Penerimaan', 1));
        $this->post('/kelola/pembelian/faktur', [
            'UuidPemasok' => $pemasok->Uuid, 'NomorFakturPemasok' => 'INV-SPN-0921', 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'UuidPenerimaan' => [$grn->Uuid],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $faktur = FakturPembelian::query()->sole();
        expect($faktur->Status)->toBe(StatusFakturPembelian::BelumDibayar);
        $this->get("/kelola/pembelian/faktur/{$faktur->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Faktur/Detail')->where('Tindakan.Bayar', true));

        $this->get('/kelola/pembelian/hutang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Hutang/Daftar')->has('Hutang.Data', 1)->where('Hutang.Ringkasan.Total', '756000.00'));
        $this->get("/kelola/pembelian/pembayaran/buat?pemasok={$pemasok->Uuid}&faktur={$faktur->Uuid}")->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Pembayaran/Form')->has('Faktur', 1)->where('UuidFakturAwal', $faktur->Uuid));
        // Aksi massal "Bayar faktur terpilih": beberapa Uuid dipisah koma lolos; selain Uuid sah dibuang.
        $this->get("/kelola/pembelian/pembayaran/buat?pemasok={$pemasok->Uuid}&faktur={$faktur->Uuid},bukan-uuid,<script>")->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('UuidFakturAwal', $faktur->Uuid));
        $this->get("/kelola/pembelian/pembayaran/buat?pemasok={$pemasok->Uuid}&faktur=bukan-uuid")->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('UuidFakturAwal', null));
        $akun = BantuanPembelian::AkunKas();
        $this->post('/kelola/pembelian/pembayaran', [
            'UuidPemasok' => $pemasok->Uuid, 'UuidAkun' => $akun->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'Alokasi' => [['UuidFaktur' => $faktur->Uuid, 'Jumlah' => '500000']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $bayar = PembayaranHutang::query()->sole();
        expect($faktur->refresh()->Status)->toBe(StatusFakturPembelian::DibayarSebagian);
        $this->get("/kelola/pembelian/pembayaran/{$bayar->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pembayaran/Detail')->has('Alokasi', 1));

        $this->get("/kelola/pembelian/retur/buat?penerimaan={$grn->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Retur/Form')->has('Baris', 1));
        $idBarisGrn = $grn->Detail()->value('Id');
        $this->post('/kelola/pembelian/retur', [
            'UuidPenerimaan' => $grn->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'), 'Alasan' => 'Kemasan bocor saat diterima',
            'Baris' => [['IdBarisPenerimaan' => $idBarisGrn, 'Jumlah' => '2']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $retur = ReturPembelian::query()->sole();
        $this->get("/kelola/pembelian/retur/{$retur->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Retur/Detail')->where('Retur.Total', '63000.00'));

        foreach (['pesanan' => 'Pesanan', 'penerimaan' => 'Penerimaan', 'faktur' => 'Faktur', 'pembayaran' => 'Pembayaran', 'retur' => 'Retur'] as $rute => $halaman) {
            $this->get("/kelola/pembelian/{$rute}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
                ->component("Kelola/Pembelian/{$halaman}/Daftar")->has("{$halaman}.Data", 1));
            $this->getJson("/kelola/pembelian/{$rute}?cari=")->assertOk()->assertJsonPath('Meta.Total', 1);
        }

        $this->get('/kelola/pembelian/belanja-stok')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Penerimaan/Form')->where('Mode', 'BelanjaStok'));
        $this->get('/kelola/pembelian/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pengaturan')->where('Pengaturan.BatasPersetujuanPo', '5000000.00'));
        $this->get('/kelola/pembelian/pemasok')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Pemasok/Daftar'));

        expect(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('izin: tanpa pembelian.kelola 403; StafPembelian tidak bisa pengaturan atau menyetujui PO', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pembelian/pesanan')->assertForbidden();
        $this->get('/kelola/pembelian/pemasok')->assertForbidden();
        $this->get('/kelola/pembelian/pemasok/buat')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafPembelian);
        $this->get('/kelola/pembelian/pesanan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Izin.Kelola', true)->where('Izin.Setujui', false));
        $this->get('/kelola/pembelian/pengaturan')->assertForbidden();
        $this->put('/kelola/pembelian/pengaturan', ['BatasPersetujuanPo' => '0', 'ToleransiPenerimaanPersen' => '0'])->assertForbidden();
    });

    it('isolasi tenant: dokumen & pemasok tenant lain 404. Batas outlet: dokumen lokasi outlet lain 404', function (): void {
        $a = BantuanPembelian::SiapkanTenant();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
        $pemasok = BantuanPembelian::BuatPemasok();
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $a['Gudang'], [[$minyak, '10', '31500']], $a['Pemilik']->Id);
        $grn = BantuanPembelian::TerimaDariPo($po, ['10'], $a['Pemilik']->Id);

        $b = BantuanPembelian::SiapkanTenant(namaUsaha: 'Warung Kelontong Berkah Sejahtera');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/pembelian/pesanan/{$po->Uuid}")->assertNotFound();
        $this->get("/kelola/pembelian/penerimaan/{$grn->Uuid}")->assertNotFound();
        $this->put("/kelola/pembelian/pemasok/{$pemasok->Uuid}", ['Kode' => 'X', 'Nama' => 'X', 'Pkp' => false, 'TerminHari' => 0])->assertNotFound();
        $this->getJson('/kelola/pembelian/pesanan')->assertOk()->assertJsonPath('Meta.Total', 0);

        // Batas outlet: anggota yang hanya boleh outlet cabang tidak melihat dokumen lokasi toko utama.
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        $staf = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::StafPembelian, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $staf->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::StafPembelian)->Id]);
        BantuanOrganisasi::Masuk($this, $staf, $a['Tenant']->Id);
        $this->get("/kelola/pembelian/penerimaan/{$grn->Uuid}")->assertNotFound();
        $this->getJson('/kelola/pembelian/penerimaan')->assertOk()->assertJsonPath('Meta.Total', 0);
    });
});
