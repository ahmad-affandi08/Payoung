<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\MulaiStokOpname;
use App\Domain\Persediaan\Aksi\SimpanHitungStokOpname;
use App\Domain\Persediaan\Data\DataHitungOpname;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b HTTP transfer, stok opname, penyesuaian (routes/PersediaanDokumen.php): alur lewat form, izin
 * (persediaan.lihat/kelola/penyesuaian.setujui), batas outlet akses (penerima dibatasi outlet tujuan), isolasi tenant,
 * hitung buta (jumlah sistem tidak ada di respons), TabelData mode server, dan pengaturan batas persetujuan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanHttpDokumen(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '137', '38500'),
        BantuanStokAwal::Baris($p['Batch'], '40', '19500', 'UHT-2609A', '2027-03-31'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p, 'Cabang' => $cabang, 'GudangCabang' => $gudangCabang];
}

/** Anggota ber-peran `peran` yang hanya boleh mengakses outlet `idOutlet`, lalu masuk. */
function MasukOutlet(object $tes, int $idTenant, int $idOutlet, PeranTenantBawaan $peran = PeranTenantBawaan::ManajerOutlet): object
{
    $pengguna = BantuanOrganisasi::TambahAnggota($idTenant, $peran, semuaOutlet: false);
    OutletPengguna::query()->create(['IdOutlet' => $idOutlet, 'IdPengguna' => $pengguna->Id, 'IdPeran' => BantuanOrganisasi::Peran($idTenant, $peran)->Id]);
    BantuanOrganisasi::AturKonteks($idTenant);

    return BantuanOrganisasi::Masuk($tes, $pengguna, $idTenant);
}

describe('F-05b HTTP transfer stok', function (): void {
    it('buat draf, kirim, terima sebagian, tutup lewat form; daftar TabelData JSON', function (): void {
        $t = SiapkanHttpDokumen();
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->sole();
        $uuid = (string) Str::ulid();
        $isi = [
            'Uuid' => $uuid, 'UuidGudangAsal' => $t['Gudang']->Uuid, 'UuidGudangTujuan' => $t['GudangCabang']->Uuid, 'Tanggal' => B::Kemarin(), 'Catatan' => 'Isi ulang rak cabang',
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '12'], ['UuidProduk' => $t['Produk']['Batch']->Uuid, 'Jumlah' => '5', 'UuidBatchStok' => $batch->Uuid]],
        ];
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/transfer', $isi)->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/transfer/{$uuid}");
        $this->post('/kelola/persediaan/transfer', $isi)->assertRedirect("/kelola/persediaan/transfer/{$uuid}");
        expect(TransferStok::query()->count())->toBe(1);

        $this->get("/kelola/persediaan/transfer/{$uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Transfer/Detail')
            ->where('Transfer.Status', 'Draf')
            ->where('Tindakan.Kirim', true)
            ->where('Tindakan.Terima', false)
            ->where('Baris.1.UuidBatchStok', $batch->Uuid));

        $this->post("/kelola/persediaan/transfer/{$uuid}/kirim")->assertSessionHasNoErrors();
        $this->post("/kelola/persediaan/transfer/{$uuid}/terima", ['Tanggal' => B::Kemarin(), 'Baris' => [['Urutan' => 1, 'Jumlah' => '12'], ['Urutan' => 2, 'Jumlah' => '4']]])->assertSessionHasNoErrors();
        $this->post("/kelola/persediaan/transfer/{$uuid}/terima", ['Tanggal' => B::Kemarin(), 'Baris' => [['Urutan' => 2, 'Jumlah' => '9']]])->assertSessionHasErrors();
        $this->post("/kelola/persediaan/transfer/{$uuid}/tutup", ['Alasan' => 'ok'])->assertSessionHasErrors(['Alasan']);
        $this->post("/kelola/persediaan/transfer/{$uuid}/tutup", ['Alasan' => 'Satu kotak susu penyok, dikembalikan pemasok'])->assertSessionHasNoErrors();

        expect(TransferStok::query()->where('Uuid', $uuid)->value('Status'))->toBe(StatusTransferStok::Diterima)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        $this->getJson('/kelola/persediaan/transfer?saring[Status]=Diterima&cari=Minyak')->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Data.0.Uuid', $uuid)
            ->assertJsonPath('Data.0.LabelStatus', 'Diterima');
    });

    it('D-94 cetak surat jalan transfer: draf 409; setelah kirim memuat barang & batch tanpa nilai; outlet lain 404', function (): void {
        $t = SiapkanHttpDokumen();
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->sole();
        $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '12'), B::Baris($t['Produk']['Batch'], '5', null, $batch->Id)]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get("/kelola/persediaan/transfer/{$draf->Uuid}/cetak")->assertStatus(409);

        app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id);

        $this->get("/kelola/persediaan/transfer/{$draf->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Transfer/Cetak')
            ->where('Transfer.Status', 'Dikirim')
            ->where('Transfer.NamaGudangTujuan', 'Toko Cabang Solo Baru')
            ->has('Baris', 2)
            ->where('Baris.0.Jumlah', '12.0000')
            ->where('Baris.1.NomorBatch', 'UHT-2609A')
            // Nilai barang tidak boleh ikut ke kertas yang dipegang pembawa.
            ->missing('Baris.0.NilaiKirim')
            ->missing('Transfer.TotalNilaiKirim')
            ->has('Usaha.Nama'));

        $lain = B::BuatOutlet('KLATEN', 'Cabang Klaten');
        MasukOutlet($this, $t['Tenant']->Id, $lain->Id)->get("/kelola/persediaan/transfer/{$draf->Uuid}/cetak")->assertNotFound();
    });

    it('izin: persediaan.lihat melihat tetapi tidak membuat/mengirim (403); tanpa persediaan.lihat 403', function (): void {
        $t = SiapkanHttpDokumen();
        $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '1')]);
        $kasir = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $akuntan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->get('/kelola/persediaan/transfer')->assertForbidden();
        BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id)->get("/kelola/persediaan/transfer/{$draf->Uuid}")->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Tindakan.Kirim', false));
        BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id)->post("/kelola/persediaan/transfer/{$draf->Uuid}/kirim")->assertForbidden();
        expect(TransferStok::query()->findOrFail($draf->Id)->Status)->toBe(StatusTransferStok::Draf);
    });

    it('batas outlet: pengguna outlet asal tidak bisa menerima (403); pengguna outlet tujuan bisa; outlet lain = 404', function (): void {
        $t = SiapkanHttpDokumen();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '3')]), $t['Pemilik']->Id);
        $badan = ['Tanggal' => B::Kemarin(), 'Baris' => [['Urutan' => 1, 'Jumlah' => '3']]];

        MasukOutlet($this, $t['Tenant']->Id, $t['Outlet']->Id)->post("/kelola/persediaan/transfer/{$transfer->Uuid}/terima", $badan)->assertForbidden();

        $lain = B::BuatOutlet('KLATEN', 'Cabang Klaten');
        MasukOutlet($this, $t['Tenant']->Id, $lain->Id)->get("/kelola/persediaan/transfer/{$transfer->Uuid}")->assertNotFound();
        MasukOutlet($this, $t['Tenant']->Id, $lain->Id)->getJson('/kelola/persediaan/transfer')->assertOk()->assertJsonPath('Meta.Total', 0);

        MasukOutlet($this, $t['Tenant']->Id, $t['Cabang']->Id)->post("/kelola/persediaan/transfer/{$transfer->Uuid}/terima", $badan)->assertSessionHasNoErrors();
        expect(TransferStok::query()->findOrFail($transfer->Id)->Status)->toBe(StatusTransferStok::Diterima);

        // Pengguna outlet cabang tidak bisa mengirim dari lokasi outlet utama (lokasi asal di luar akses = 404).
        MasukOutlet($this, $t['Tenant']->Id, $t['Cabang']->Id)->post('/kelola/persediaan/transfer', [
            'UuidGudangAsal' => $t['Gudang']->Uuid, 'UuidGudangTujuan' => $t['GudangCabang']->Uuid, 'Tanggal' => B::Kemarin(),
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '1']],
        ])->assertNotFound();
    });
});

describe('F-05b HTTP stok opname', function (): void {
    it('hitung buta: jumlah sistem tidak ada di respons selama berlangsung; tampil setelah diajukan', function (): void {
        $t = SiapkanHttpDokumen();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $uuid = (string) Str::ulid();

        $this->post('/kelola/persediaan/opname', ['Uuid' => $uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'UuidKategori' => null, 'HitungButa' => true, 'Catatan' => 'Opname buta akhir bulan'])
            ->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/opname/{$uuid}");
        $opname = StokOpname::query()->where('Uuid', $uuid)->sole();
        $urutan = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['Stok']->Id)->value('Urutan');

        $respons = $this->get("/kelola/persediaan/opname/{$uuid}")->assertOk();
        $respons->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Opname/Detail')
            ->where('Opname.SistemTersembunyi', true)
            ->where('Baris.0.JumlahSistem', null)
            ->where('Baris.0.Selisih', null));
        expect($respons->getContent())->not->toContain('137.0000')->not->toContain('"JumlahSistem":"');

        $this->put("/kelola/persediaan/opname/{$uuid}/hitung", ['Hitung' => [['Urutan' => $urutan, 'JumlahFisik' => '135']]])->assertSessionHasNoErrors();
        $sesudahHitung = $this->get("/kelola/persediaan/opname/{$uuid}")->assertOk();
        expect($sesudahHitung->getContent())->not->toContain('137.0000');

        $this->post("/kelola/persediaan/opname/{$uuid}/ajukan")->assertSessionHasNoErrors();
        $this->get("/kelola/persediaan/opname/{$uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Opname.SistemTersembunyi', false)
            ->where('Baris', fn ($baris) => collect($baris)->firstWhere('Urutan', $urutan)['JumlahSistem'] === '137.0000'
                && collect($baris)->firstWhere('Urutan', $urutan)['Selisih'] === '-2.0000'));

        $this->post("/kelola/persediaan/opname/{$uuid}/setujui")->assertSessionHasNoErrors();
        expect(StokOpname::query()->where('Uuid', $uuid)->value('Status'))->toBe(StatusStokOpname::Disetujui)
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('135.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('menyetujui butuh persediaan.penyesuaian.setujui (StafGudang 403); opname outlet lain 404', function (): void {
        $t = SiapkanHttpDokumen();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);
        app(SimpanHitungStokOpname::class)->Jalankan($opname, [new DataHitungOpname(1, null, Kuantitas::Dari('1'))], $t['Pemilik']->Id);
        $staf = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::StafGudang);

        BantuanOrganisasi::Masuk($this, $staf, $t['Tenant']->Id)->post("/kelola/persediaan/opname/{$opname->Uuid}/ajukan")->assertSessionHasNoErrors();
        BantuanOrganisasi::Masuk($this, $staf, $t['Tenant']->Id)->post("/kelola/persediaan/opname/{$opname->Uuid}/setujui")->assertForbidden();
        MasukOutlet($this, $t['Tenant']->Id, $t['Cabang']->Id)->get("/kelola/persediaan/opname/{$opname->Uuid}")->assertNotFound();
        expect(StokOpname::query()->findOrFail($opname->Id)->Status)->toBe(StatusStokOpname::Ditinjau);
    });
});

describe('F-05b HTTP penyesuaian stok', function (): void {
    it('form → ajukan di atas batas → penyetuju lain menyetujui; pembuat tidak melihat tombol setujui', function (): void {
        $t = SiapkanHttpDokumen();
        $uuid = (string) Str::ulid();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->post('/kelola/persediaan/penyesuaian', [
            'Uuid' => $uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => B::Kemarin(), 'KodeAlasan' => 'Hilang', 'Keterangan' => null,
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Arah' => 'Keluar', 'Jumlah' => '20']],
        ])->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/penyesuaian/{$uuid}");
        $this->post("/kelola/persediaan/penyesuaian/{$uuid}/ajukan")->assertSessionHasNoErrors();
        $this->get("/kelola/persediaan/penyesuaian/{$uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Penyesuaian/Detail')
            ->where('Penyesuaian.Status', 'MenungguPersetujuan')
            ->where('Tindakan.Setujui', false)
            ->where('BatasPersetujuan', '500000.00'));
        $this->post("/kelola/persediaan/penyesuaian/{$uuid}/setujui")->assertSessionHasErrors();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Admin)->post("/kelola/persediaan/penyesuaian/{$uuid}/setujui")->assertSessionHasNoErrors();
        expect(PenyesuaianStok::query()->where('Uuid', $uuid)->value('Status'))->toBe(StatusPenyesuaianStok::Diposting)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('StafGudang tidak boleh menyetujui (403); pelacakan batch per lokasi; pengaturan batas lewat halaman pengaturan', function (): void {
        $t = SiapkanHttpDokumen();
        $menunggu = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Rusak, [B::Baris($t['Produk']['Stok'], '-20')]), $t['Pemilik']->Id);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang)->post("/kelola/persediaan/penyesuaian/{$menunggu->Uuid}/setujui")->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)->getJson("/kelola/persediaan/pelacakan?produk={$t['Produk']['Batch']->Uuid}&gudang={$t['Gudang']->Uuid}")
            ->assertOk()->assertJsonPath('Batch.0.NomorBatch', 'UHT-2609A')->assertJsonPath('Batch.0.JumlahSisa', '40.0000');

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)
            ->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'RataRata', 'StokBolehMinus' => false, 'BatasPersetujuanPenyesuaian' => '1000000'])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->batasPersetujuanPenyesuaian->KeString())->toBe('1000000.00');
        $this->get('/kelola/persediaan/pengaturan')->assertInertia(fn (AssertableInertia $h) => $h->where('BatasPersetujuanPenyesuaian', '1000000.00'));
    });
});

describe('F-05b HTTP isolasi tenant', function (): void {
    it('dokumen, lokasi, dan pelacakan tenant lain = 404 dan tidak muncul di daftar', function (): void {
        $a = SiapkanHttpDokumen();
        $transfer = B::DrafTransfer($a['Gudang'], $a['GudangCabang'], [B::Baris($a['Produk']['Stok'], '1')]);
        $opname = app(MulaiStokOpname::class)->Jalankan($a['Gudang']->Id, null, false, null, $a['Pemilik']->Id);
        $penyesuaian = B::DrafPenyesuaian($a['Gudang'], AlasanPenyesuaian::Rusak, [B::Baris($a['Produk']['Stok'], '-1')]);

        $b = BantuanPersediaan::SiapkanTenant('Toko Kelontong Lain');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);

        foreach ([
            "/kelola/persediaan/transfer/{$transfer->Uuid}", "/kelola/persediaan/opname/{$opname->Uuid}", "/kelola/persediaan/penyesuaian/{$penyesuaian->Uuid}",
            "/kelola/persediaan/pelacakan?produk={$a['Produk']['Batch']->Uuid}&gudang={$a['Gudang']->Uuid}",
        ] as $alamat) {
            $this->get($alamat)->assertNotFound();
        }

        $this->post("/kelola/persediaan/transfer/{$transfer->Uuid}/kirim")->assertNotFound();
        $this->post("/kelola/persediaan/penyesuaian/{$penyesuaian->Uuid}/ajukan")->assertNotFound();
        $this->post('/kelola/persediaan/opname', ['UuidGudang' => $a['Gudang']->Uuid, 'HitungButa' => false])->assertNotFound();

        foreach (['transfer', 'opname', 'penyesuaian'] as $jenis) {
            $this->getJson("/kelola/persediaan/{$jenis}")->assertOk()->assertJsonPath('Meta.Total', 0);
        }

        expect(TransferStok::query()->withoutGlobalScopes()->findOrFail($transfer->Id)->Status)->toBe(StatusTransferStok::Draf);
    });
});
