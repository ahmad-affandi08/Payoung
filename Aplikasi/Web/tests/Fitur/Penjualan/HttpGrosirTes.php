<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Back-office grosir lewat rute (routes/Grosir.php, F-12 §9.7): alur lengkap SO → surat jalan → faktur → retur, setiap halaman
 * Inertia ada di disk, TabelData JSON, izin `grosir.kelola`, dan isolasi tenant.
 *
 * Yang paling penting dijaga di sini adalah **harga tidak datang dari klien**: formulirnya memang tidak punya bidang
 * harga, dan test membuktikan harga tersimpan = harga katalog walau permintaannya tidak menyebut harga sama sekali.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Sumber Pangan');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'piutang.grosir');
    $this->gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kemasan 1 kg'], '15000.00', $this->k['Pcs']);
    $this->satuan = BantuanHarga::SatuanDasar($this->gula);
    BantuanStokAwal::BuatDanPosting(
        $this->k['Gudang'],
        [BantuanStokAwal::Baris($this->gula, '1000', '11000')],
        $this->k['Pemilik']->Id,
        '2026-09-01',
    );
    $this->toko = Pelanggan::query()->create([
        'Nama' => 'Toko Makmur Jaya',
        'NoHp' => '6281355550005',
        'LimitKredit' => '50000000',
        'TerminHari' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('HTTP back-office grosir', function (): void {
    it('produk bernomor seri lewat rute: nomor seri dikirim per unit, tampil di cetak surat jalan (tanpa harga), retur memilih nomor, form retur menawarkan akun pengembalian uang', function (): void {
        $ponsel = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 8/256 GB', 'Pelacakan' => PelacakanProduk::Seri->value], '2500000.00', $this->k['Pcs']);
        BantuanStokAwal::BuatDanPosting(
            $this->k['Gudang'],
            [BantuanStokAwal::Baris($ponsel, '2', '2000000', nomorSeri: ['IMEI-0001', 'IMEI-0002'])],
            $this->k['Pemilik']->Id,
            '2026-09-02',
        );
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/grosir/pesanan', [
            'UuidPelanggan' => $this->toko->Uuid,
            'UuidOutlet' => $this->k['Outlet']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['UuidProduk' => $ponsel->Uuid, 'UuidProdukSatuan' => BantuanHarga::SatuanDasar($ponsel)->Uuid, 'Jumlah' => '2']],
        ])->assertSessionHasNoErrors();
        $pesanan = PesananGrosir::query()->sole();
        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/konfirmasi")->assertSessionHasNoErrors();

        $this->get("/kelola/grosir/pesanan/{$pesanan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.Pelacakan', 'Seri')
            ->where('Baris.0.Konversi', '1.0000'));

        // Jumlah nomor seri tidak sesuai → galat di formulir, tidak ada surat jalan.
        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/kirim", [
            'UuidGudang' => $this->k['Gudang']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '2', 'NomorSeri' => ['IMEI-0001']]],
        ])->assertSessionHasErrors();
        expect(SuratJalan::query()->count())->toBe(0);

        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/kirim", [
            'UuidGudang' => $this->k['Gudang']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '2', 'NomorSeri' => ['IMEI-0001', 'IMEI-0002']]],
        ])->assertSessionHasNoErrors();
        $suratJalan = SuratJalan::query()->sole();

        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.Rincian', 'IMEI-0001, IMEI-0002')
            ->missing('Baris.0.Harga'));
        $this->get("/kelola/grosir/retur/buat/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Retur/Buat')
            ->where('Baris.0.SeriBisaDiretur', ['IMEI-0001', 'IMEI-0002'])
            ->has('OpsiAkunRefund'));

        $this->post('/kelola/grosir/retur', [
            'UuidSuratJalan' => $suratJalan->Uuid,
            'Tanggal' => '2026-09-27',
            'Alasan' => 'Layar retak dari pabrik',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '1', 'Kondisi' => 'LayakJual', 'NomorSeri' => ['IMEI-0002']]],
        ])->assertSessionHasNoErrors();
        $this->get("/kelola/grosir/retur/buat/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.SeriBisaDiretur', ['IMEI-0001']));
    });

    it('alur lengkap lewat rute: draf SO, konfirmasi, kirim, faktur, nomor Faktur Pajak, retur', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/grosir/pesanan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Pesanan/Daftar')->has('OpsiStatus', 5));
        $this->get('/kelola/grosir/pesanan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Pesanan/Form')->where('Isian', null)->has('OpsiOutlet', 1)
            // Dropdown outlet memakai label "Nama (KODE)": tanpa Kode tampil "Nama (undefined)".
            ->where('OpsiOutlet.0.Kode', (string) $this->k['Outlet']->Kode));

        // Perhatikan: tidak ada 'Harga' di permintaan ini sama sekali.
        $this->post('/kelola/grosir/pesanan', [
            'UuidPelanggan' => $this->toko->Uuid,
            'UuidOutlet' => $this->k['Outlet']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['UuidProduk' => $this->gula->Uuid, 'UuidProdukSatuan' => $this->satuan->Uuid, 'Jumlah' => '200']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $pesanan = PesananGrosir::query()->sole();
        expect($pesanan->Status)->toBe(StatusPesananGrosir::Draf)
            // Harga datang dari katalog, bukan dari peramban.
            ->and($pesanan->Detail()->value('Harga'))->toBe('15000.00')
            ->and($pesanan->Total)->toBe('3000000.00');

        $this->get("/kelola/grosir/pesanan/{$pesanan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Pesanan/Detail')
            ->where('Tindakan.Konfirmasi', true)
            ->where('Tindakan.Kirim', false)
            ->where('Pesanan.Total', '3000000.00')
            ->has('Baris', 1));
        $this->get("/kelola/grosir/pesanan/{$pesanan->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Pesanan/Form')->has('Isian.Baris', 1));

        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/konfirmasi")->assertSessionHasNoErrors()->assertRedirect();
        expect($pesanan->refresh()->Status)->toBe(StatusPesananGrosir::Dikonfirmasi);

        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/kirim", [
            'UuidGudang' => $this->k['Gudang']->Uuid,
            'Tanggal' => '2026-09-27',
            'NamaPengirim' => 'Sopir Pak Slamet',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '200']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $suratJalan = SuratJalan::query()->sole();
        expect($suratJalan->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($suratJalan->Total)->toBe('3000000.00')
            ->and($pesanan->refresh()->Status)->toBe(StatusPesananGrosir::Selesai);

        $this->get('/kelola/grosir/surat-jalan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/SuratJalan/Daftar')->has('SuratJalan.Data', 1));
        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/SuratJalan/Detail')
            ->where('Tindakan.Batalkan', true)
            ->where('SuratJalan.TotalHpp', '2200000.00')
            ->has('Jurnal', 1));

        $this->get('/kelola/grosir/faktur/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Faktur/Buat')->has('SuratJalan.Data', 1));
        $this->post('/kelola/grosir/faktur', [
            'UuidSuratJalan' => [$suratJalan->Uuid],
            'Tanggal' => '2026-09-27',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $faktur = FakturPenjualan::query()->sole();
        expect($faktur->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($faktur->Total)->toBe('3000000.00')
            ->and($suratJalan->refresh()->IdFakturPenjualan)->toBe($faktur->Id)
            // BR-12.5: piutangnya memakai tabel yang sama dengan penjualan tempo.
            ->and(Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->exists())->toBeTrue();

        $this->get('/kelola/grosir/faktur')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Faktur/Daftar')->where('Faktur.Data.0.Sisa', '3000000.00'));
        $this->get("/kelola/grosir/faktur/{$faktur->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Faktur/Detail')->has('SuratJalan', 1)->has('Jurnal', 1));

        $this->put("/kelola/grosir/faktur/{$faktur->Uuid}/nomor-pajak", ['NomorFakturPajak' => '0100002512345678'])
            ->assertSessionHasNoErrors()->assertRedirect();
        expect($faktur->refresh()->NomorFakturPajak)->toBe('0100002512345678')
            ->and(LogAudit::query()->where('Peristiwa', 'grosir.faktur-nomor-pajak')->where('IdObjek', $faktur->Id)->exists())->toBeTrue();

        // Retur (BR-12.7): jumlah & kondisi saja yang dikirim; harga dan HPP-nya dari snapshot surat jalan.
        $this->get("/kelola/grosir/retur/buat/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Retur/Buat')
            ->where('Baris.0.SisaRetur', '200.0000')
            ->has('OpsiKondisi', 2));
        $this->post('/kelola/grosir/retur', [
            'UuidSuratJalan' => $suratJalan->Uuid,
            'Tanggal' => '2026-09-27',
            'Alasan' => 'Sepuluh sak basah kena hujan di gudang pembeli.',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '10', 'Kondisi' => 'Rusak']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $retur = ReturGrosir::query()->sole();
        expect($retur->Total)->toBe('150000.00')
            // Penyerahannya sudah difakturkan, jadi returnya menjadi nota kredit yang mengurangi piutang faktur itu.
            ->and($retur->MengurangiPiutang)->toBeTrue()
            ->and($retur->IdFakturPenjualan)->toBe($faktur->Id)
            ->and(Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole()->AmbilSisa()->KeString())->toBe('2850000.00')
            ->and($suratJalan->refresh()->Detail()->value('JumlahDiretur'))->toBe('10.0000');

        $this->get('/kelola/grosir/retur')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Retur/Daftar')->has('Retur.Data', 1)->where('Retur.Data.0.MengurangiPiutang', true));
        $this->get("/kelola/grosir/retur/{$retur->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Retur/Detail')
            ->where('Retur.Total', '150000.00')
            ->where('Baris.0.LabelKondisi', 'Rusak')
            ->has('Jurnal', 1));

        // Sisa yang bisa diretur berkurang, dan returnya tampil di halaman surat jalannya.
        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.SisaRetur', '190.0000')->has('Retur', 1));

        $this->post("/kelola/grosir/retur/{$retur->Uuid}/batalkan", ['Alasan' => 'Salah input, yang basah cuma lima sak.'])
            ->assertSessionHasNoErrors()->assertRedirect();
        expect($retur->refresh()->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole()->AmbilSisa()->KeString())->toBe('3000000.00')
            ->and($suratJalan->refresh()->Detail()->value('JumlahDiretur'))->toBe('0.0000');
    });

    it('keempat halaman cetak menjawab, dan surat jalan & daftar ambil barang tidak memuat harga sama sekali', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/grosir/pesanan', [
            'UuidPelanggan' => $this->toko->Uuid,
            'UuidOutlet' => $this->k['Outlet']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['UuidProduk' => $this->gula->Uuid, 'UuidProdukSatuan' => $this->satuan->Uuid, 'Jumlah' => '200']],
        ])->assertSessionHasNoErrors();
        $pesanan = PesananGrosir::query()->sole();
        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/konfirmasi")->assertSessionHasNoErrors();

        // Daftar ambil barang sebelum pengiriman: sisa = seluruh pesanan.
        $this->get("/kelola/grosir/pesanan/{$pesanan->Uuid}/ambil-barang")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Pesanan/CetakAmbil')
            ->where('Baris.0.SisaKirim', '200.0000')
            ->where('Pesanan.Pelanggan.Nama', 'Toko Makmur Jaya')
            ->has('Usaha.Nama')
            // Tidak ada harga di daftar ambil barang: yang pegang kertas ini petugas gudang.
            ->missing('Baris.0.Harga')
            ->missing('Baris.0.Subtotal'));

        // Kirim separuh, lalu daftar ambil barang hanya menyisakan yang belum dikirim.
        $this->post("/kelola/grosir/pesanan/{$pesanan->Uuid}/kirim", [
            'UuidGudang' => $this->k['Gudang']->Uuid,
            'Tanggal' => '2026-09-27',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '120']],
        ])->assertSessionHasNoErrors();
        $this->get("/kelola/grosir/pesanan/{$pesanan->Uuid}/ambil-barang")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.JumlahTerkirim', '120.0000')
            ->where('Baris.0.SisaKirim', '80.0000'));

        $suratJalan = SuratJalan::query()->sole();
        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/SuratJalan/Cetak')
            ->where('SuratJalan.Nomor', $suratJalan->Nomor)
            ->where('SuratJalan.Pelanggan.NoHp', '6281355550005')
            ->where('Baris.0.Jumlah', '120.0000')
            // Surat jalan dicetak tanpa harga: ini yang dibaca sopir & gudang pembeli.
            ->missing('Baris.0.Harga')
            ->missing('Baris.0.Subtotal')
            ->missing('SuratJalan.Total'));

        $this->post('/kelola/grosir/faktur', ['UuidSuratJalan' => [$suratJalan->Uuid], 'Tanggal' => '2026-09-27'])
            ->assertSessionHasNoErrors();
        $faktur = FakturPenjualan::query()->sole();

        // Faktur justru wajib memuat uang, dan barisnya baris surat jalan yang ditautkan (BR-12.4).
        $this->get("/kelola/grosir/faktur/{$faktur->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Faktur/Cetak')
            ->where('Faktur.Total', '1800000.00')
            ->where('Baris.0.NomorSuratJalan', $suratJalan->Nomor)
            ->where('Baris.0.Harga', '15000.00')
            ->has('SuratJalan', 1));

        $this->post('/kelola/grosir/retur', [
            'UuidSuratJalan' => $suratJalan->Uuid,
            'Tanggal' => '2026-09-27',
            'Alasan' => 'Dua puluh sak sobek saat bongkar di gudang pembeli.',
            'Baris' => [['Urutan' => 1, 'Jumlah' => '20', 'Kondisi' => 'Rusak']],
        ])->assertSessionHasNoErrors();
        $retur = ReturGrosir::query()->sole();

        // Sudah difakturkan, jadi kertasnya nota kredit.
        $this->get("/kelola/grosir/retur/{$retur->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Retur/Cetak')
            ->where('Retur.MengurangiPiutang', true)
            ->where('Retur.NomorFaktur', $faktur->Nomor)
            ->where('Retur.Total', '300000.00')
            ->where('Baris.0.LabelKondisi', 'Rusak'));

        // Dibatalkan pun tetap bisa dibuka, supaya halamannya bisa menandainya DIBATALKAN.
        $this->post("/kelola/grosir/retur/{$retur->Uuid}/batalkan", ['Alasan' => 'Salah hitung sak yang sobek.'])
            ->assertSessionHasNoErrors();
        $this->get("/kelola/grosir/retur/{$retur->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Retur.Status', 'Dibatalkan')
            ->where('Retur.AlasanBatal', 'Salah hitung sak yang sobek.'));
    });

    it('halaman cetak ikut dijaga izin & batas outlet', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/grosir/surat-jalan/'.strtoupper((string) Str::ulid()).'/cetak')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->get('/kelola/grosir/faktur/'.strtoupper((string) Str::ulid()).'/cetak')->assertNotFound();
    });

    it('TabelData JSON & pencarian produk/pelanggan menjawab', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        // `getJson` mengirim Accept: application/json tanpa X-Inertia, jadi yang dikembalikan data tabelnya.
        $this->getJson('/kelola/grosir/pesanan')->assertOk()->assertJsonStructure(['Data', 'Meta']);
        $this->getJson('/kelola/grosir/produk/cari?kata=Gula')->assertOk()
            ->assertJsonPath('Data.0.Nama', 'Gula Pasir Kemasan 1 kg')
            // Satuan jual ikut, dan tidak ada harga di hasil pencarian (harga milik server saat draf disimpan).
            ->assertJsonCount(1, 'Data.0.Satuan');
        $this->getJson('/kelola/grosir/pelanggan/cari?kata=Makmur')->assertOk()
            ->assertJsonCount(1, 'Data')
            ->assertJsonPath('Data.0.Uuid', $this->toko->Uuid)
            ->assertJsonPath('Data.0.LimitKredit', '50000000.00')
            ->assertJsonPath('Data.0.SisaPiutang', '0.00');
        // Dropdown: kata kosong atau pendek menampilkan daftar awal (tanpa minimal huruf), tersaring status aktif.
        $this->getJson('/kelola/grosir/pelanggan/cari?kata=')->assertOk()->assertJsonPath('Data.0.Nama', 'Toko Makmur Jaya');
        $this->getJson('/kelola/grosir/pelanggan/cari?kata=Mk')->assertOk()->assertJsonCount(0, 'Data');
        // Pelanggan diarsipkan tidak ditawarkan; pencarian lewat nomor HP (sebagian) menemukan yang aktif.
        $this->getJson('/kelola/grosir/pelanggan/cari?kata=5550005')->assertOk()->assertJsonCount(1, 'Data');
        $this->toko->forceFill(['Status' => StatusPelanggan::Diarsipkan->value])->save();
        $this->getJson('/kelola/grosir/pelanggan/cari?kata=')->assertOk()->assertJsonCount(0, 'Data');
    });

    it('tanpa izin grosir.kelola ditolak, dan dokumen tenant lain 404', function (): void {
        // Kasir tidak memegang grosir.kelola (izin itu milik Manajer Outlet & Supervisor).
        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/grosir/pesanan')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->get('/kelola/grosir/pesanan/'.strtoupper((string) Str::ulid()))->assertNotFound();
    });
});
