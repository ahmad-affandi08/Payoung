<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Layanan\PenyimpanGambarQris;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-07b halaman back-office penjualan', function (): void {
    it('daftar & detail penjualan: baris, pajak, pembayaran, mutasi stok bertautan kartu stok, jurnal, shift. Detail shift menampilkan penjualannya', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $item = BantuanPenjualan::Item($k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12]],
            'Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '20000.00'], ['Metode' => $k['Tunai'], 'Jumlah' => '100000.00']],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->get('/kelola/penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penjualan/Daftar')
            ->where('Penjualan.Meta.Total', 1)
            ->where('Penjualan.Data.0.Uuid', $item['Uuid'])
            ->where('Penjualan.Data.0.Nomor', $item['Data']['Nomor'])
            ->where('Penjualan.Data.0.NamaKasir', $k['Kasir']->Nama)
            ->where('Penjualan.Data.0.TotalAkhir', '85470.00')
            ->where('Penjualan.Data.0.Metode', ['QRIS Toko Berkah', 'Tunai'])
            ->where('Penjualan.Data.0.Status', 'Lunas')
            ->has('OpsiStatus')
            ->has('OpsiKanal'));

        $this->get("/kelola/penjualan/{$item['Uuid']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penjualan/Detail')
            ->where('Penjualan.Nomor', $item['Data']['Nomor'])
            ->where('Penjualan.UuidShift', $k['UuidShift'])
            ->where('Penjualan.Kembalian', '34530.00')
            // Outlet belum PKP & produk tanpa kelompok pajak, tetapi perangkat memungut PPN (PRD v1.46): alasan tinjauan
            // tampil dengan label manusiawi.
            ->where('Penjualan.PerluTinjauan', true)
            ->where('Penjualan.DaftarAlasanTinjauan', [[
                'Kode' => 'PajakBerbeda',
                'Label' => 'Pajak di perangkat berbeda dengan pengaturan pajak',
                'Keterangan' => 'pajak per produk Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter (perangkat PPN, seharusnya tanpa pajak)',
            ]])
            ->has('Baris', 1)
            ->where('Baris.0.SimbolSatuan', 'pcs')
            ->where('Pajak.0.KodeJenisPajak', 'Ppn')
            ->where('Pajak.0.Jumlah', '8470.00')
            ->has('Pembayaran', 2)
            ->where('MutasiStok.0.Jumlah', '-2.0000')
            ->where('MutasiStok.0.TautanKartuStok', fn (?string $tautan): bool => is_string($tautan) && str_contains($tautan, "produk={$minyak->Uuid}"))
            ->where('Jurnal.0.Nomor', fn (?string $nomor): bool => is_string($nomor) && str_starts_with($nomor, 'JU/')));

        $this->get("/kelola/kasir/shift/{$k['UuidShift']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Shift/Detail')
            ->where('Penjualan.JumlahTransaksi', 1)
            ->where('Penjualan.DaftarTerpotong', false)
            ->where('Penjualan.TotalPenjualan', '85470.00')
            ->where('Penjualan.Daftar.0.Uuid', $item['Uuid']));
    });

    it('D-16 TabelData: JSON {Data, Meta} di URL yang sama. Cari nomor, filter tinjauan/status/tanggal, urut total', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '3');
        $kecil = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $besar = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '5', 'Harga' => '38500.00']]]);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$kecil, $besar]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);
        $json = fn (string $query) => $this->getJson('/kelola/penjualan'.$query)->assertOk();

        expect($json('')->json('Meta.Total'))->toBe(2)
            ->and(array_column($json('?urut=TotalAkhir')->json('Data'), 'Uuid'))->toBe([$kecil['Uuid'], $besar['Uuid']])
            ->and(array_column($json('?cari='.urlencode($besar['Data']['Nomor']))->json('Data'), 'Uuid'))->toBe([$besar['Uuid']])
            ->and(array_column($json('?saring[PerluTinjauan]=1')->json('Data'), 'Uuid'))->toBe([$besar['Uuid']])
            ->and($json('?saring[Status]=Void')->json('Meta.Total'))->toBe(0)
            ->and($json('?saring[TanggalBisnis]=2000-01-01..2000-01-31')->json('Meta.Total'))->toBe(0)
            ->and($json('?urut=Id;DROP TABLE Penjualan&saring[IdTenant]=1')->json('Meta.Total'))->toBe(2);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->getJson('/kelola/penjualan')->assertForbidden();
    });

    it('izin laporan.penjualan.lihat (Kasir & Supervisor 403). Pengguna per outlet hanya melihat penjualan outletnya. Penjualan tenant lain 404', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        $minyak = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
        $item = BantuanPenjualan::Item($a, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        BantuanKasir::KirimRingkas($this, $a['Token'], [$item]);

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/penjualan')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get("/kelola/penjualan/{$item['Uuid']}")->assertForbidden();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanOrganisasi::Masuk($this, $manajerCabang, $a['Tenant']->Id);

        $this->get('/kelola/penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Penjualan.Meta.Total', 0));
        $this->get("/kelola/penjualan/{$item['Uuid']}")->assertNotFound();

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/penjualan/{$item['Uuid']}")->assertNotFound();
        $this->get('/kelola/penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Penjualan.Meta.Total', 0));
    });
});

describe('F-07b gambar QRIS untuk POS', function (): void {
    it('GET /api/pos/v1/metode-pembayaran/{uuid}/gambar-qris: gambar metode QRIS statis aktif tenant perangkat, tanpa gambar, bukan QRIS, nonaktif, atau tenant lain = 404. Data-awal menandai AdaGambarQris', function (): void {
        Storage::fake('local');
        $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        $path = app(PenyimpanGambarQris::class)->Simpan($a['Tenant']->Id, UploadedFile::fake()->image('qris.png', 300, 300));
        $a['Qris']->forceFill(['PathGambarQris' => $path])->save();

        $this->withToken($a['Token'])->get("/api/pos/v1/metode-pembayaran/{$a['Qris']->Uuid}/gambar-qris")->assertOk();
        $this->withToken($a['Token'])->getJson("/api/pos/v1/metode-pembayaran/{$a['Edc']->Uuid}/gambar-qris")->assertNotFound();

        $qrisTanpaGambar = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisStatis, 'QRIS Cabang');
        $this->withToken($a['Token'])->getJson("/api/pos/v1/metode-pembayaran/{$qrisTanpaGambar->Uuid}/gambar-qris")->assertNotFound();

        // Metode nonaktif tidak ada di data-awal, jadi gambarnya juga tidak disajikan.
        $qrisNonaktif = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisStatis, 'QRIS Lama Sudah Ditutup', false);
        $qrisNonaktif->forceFill(['PathGambarQris' => $path])->save();
        $this->withToken($a['Token'])->getJson("/api/pos/v1/metode-pembayaran/{$qrisNonaktif->Uuid}/gambar-qris")->assertNotFound();

        $dataAwal = $this->withToken($a['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        expect(collect($dataAwal->json('MetodePembayaran'))->firstWhere('Uuid', $a['Qris']->Uuid)['AdaGambarQris'])->toBeTrue();

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        $this->withToken($b['Token'])->getJson("/api/pos/v1/metode-pembayaran/{$a['Qris']->Uuid}/gambar-qris")->assertNotFound();
        $this->withoutToken()->getJson("/api/pos/v1/metode-pembayaran/{$a['Qris']->Uuid}/gambar-qris")->assertUnauthorized();
        unset($b);
    });
});

describe('F-07b pengaturan kasir: batas diskon & pembulatan tunai', function (): void {
    it('bawaan 10%/30% tanpa pembulatan. Simpan batas & pembulatan tercatat audit. Nilai tidak valid ditolak. Nilai tersimpan rusak kembali ke bawaan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);

        $this->get('/kelola/kasir/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Pengaturan')
            ->where('BatasDiskonManual', '10.00')
            ->where('BatasDiskonPenyetuju', '30.00')
            ->where('PembulatanTunai', null)
            ->has('OpsiArahPembulatan', 3));

        $isi = ['BatasKasKeluar' => '200000', 'ShiftBersama' => false];
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasDiskonManual' => '101', 'BatasDiskonPenyetuju' => '30'])->assertSessionHasErrors('BatasDiskonManual');
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasDiskonManual' => '20', 'BatasDiskonPenyetuju' => '15'])->assertSessionHasErrors('BatasDiskonPenyetuju');
        $this->put('/kelola/kasir/pengaturan', $isi + ['PembulatanTunai' => ['Kelipatan' => 0, 'Arah' => 'Bawah']])->assertSessionHasErrors('PembulatanTunai.Kelipatan');
        // PRD v1.46: kelipatan pembulatan tunai hanya 1–1.000 (sama dengan validasi sinkron penjualan).
        $this->put('/kelola/kasir/pengaturan', $isi + ['PembulatanTunai' => ['Kelipatan' => 1001, 'Arah' => 'Bawah']])->assertSessionHasErrors('PembulatanTunai.Kelipatan');
        $this->put('/kelola/kasir/pengaturan', $isi + ['PembulatanTunai' => ['Kelipatan' => 100, 'Arah' => 'Samping']])->assertSessionHasErrors('PembulatanTunai.Arah');
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasDiskonManual' => '5', 'BatasDiskonPenyetuju' => '25.5', 'PembulatanTunai' => ['Kelipatan' => 500, 'Arah' => 'Terdekat']])->assertRedirect('/kelola/kasir/pengaturan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = app(PengaturanKasirTenant::class)->Ambil();
        expect((string) $p->batasDiskonManual)->toBe('5.00')
            ->and((string) $p->batasDiskonPenyetuju)->toBe('25.50')
            ->and($p->AmbilPembulatanTunaiLarik())->toBe(['Kelipatan' => 500, 'Arah' => 'Terdekat'])
            ->and(LogAudit::query()->where('Peristiwa', 'kasir.pengaturan.ubah')->count())->toBe(1);

        // Bidang F-07b yang tidak dikirim dipertahankan; PembulatanTunai null = tanpa pembulatan.
        $this->put('/kelola/kasir/pengaturan', $isi)->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->AmbilPembulatanTunaiLarik())->toBe(['Kelipatan' => 500, 'Arah' => 'Terdekat']);
        $this->put('/kelola/kasir/pengaturan', $isi + ['PembulatanTunai' => null])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->pembulatanTunai)->toBeNull();

        $tenant = $k['Tenant']->refresh();
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'BatasDiskonManual' => 'abc', 'BatasDiskonPenyetuju' => '250', 'PembulatanTunai' => ['Kelipatan' => '100', 'Arah' => 'Bawah']];
        $tenant->save();
        $rusak = app(PengaturanKasirTenant::class)->Ambil();
        expect((string) $rusak->batasDiskonManual)->toBe('10.00')
            ->and((string) $rusak->batasDiskonPenyetuju)->toBe('30.00')
            ->and($rusak->pembulatanTunai)->toBeNull();

        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PembulatanTunai' => ['Kelipatan' => 5000, 'Arah' => 'Bawah']];
        $tenant->save();
        expect(app(PengaturanKasirTenant::class)->Ambil()->pembulatanTunai)->toBeNull();
    });

    it('template sektor mengisi PembulatanTunai dan data-awal meneruskannya ke POS', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $tenant = $k['Tenant']->refresh();
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PembulatanTunai' => ['Kelipatan' => 100, 'Arah' => 'Bawah']];
        $tenant->save();

        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Pengaturan.PembulatanTunai'))->toBe(['Kelipatan' => 100, 'Arah' => 'Bawah']);
        unset($k);
    });
});

describe('F-07b izin penjualan.diskon.setujui', function (): void {
    it('peran bawaan: Supervisor, Manajer Outlet, Admin memegang penjualan.diskon.setujui; Kasir tidak', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $perangkat = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Teras');
        $izin = fn (PeranTenantBawaan $peran): array => PeranIzin::query()
            ->where('IdPeran', BantuanOrganisasi::Peran($k['Tenant']->Id, $peran)->Id)
            ->pluck('KunciIzin')
            ->all();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($izin(PeranTenantBawaan::Supervisor))->toContain('penjualan.diskon.setujui')
            ->and($izin(PeranTenantBawaan::ManajerOutlet))->toContain('penjualan.diskon.setujui')
            ->and($izin(PeranTenantBawaan::Admin))->toContain('penjualan.diskon.setujui')
            ->and($izin(PeranTenantBawaan::Kasir))->not->toContain('penjualan.diskon.setujui');
        unset($perangkat);
    });
});

describe('F-07b ekspor daftar penjualan', function (): void {
    it('ekspor sesuai filter dan ekspor terpilih (uuid) memuat nomor, metode, dan total; Uuid tak sah diabaikan; Kasir tanpa izin laporan ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $item = fn (string $harga) => BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => $harga]],
            'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '100000.00']],
        ]);
        $pertama = $item('38500.00');
        $kedua = $item('41000.00');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$pertama, $kedua]))->toBe([['Diterima', null], ['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $semua = $this->get('/kelola/penjualan/ekspor?format=csv')->assertOk()->streamedContent();
        expect($semua)->toContain($pertama['Data']['Nomor'])->and($semua)->toContain($kedua['Data']['Nomor'])->and($semua)->toContain('Tunai');

        // Terpilih: hanya satu; Uuid yang bukan ULID diabaikan, bukan menjadi saringan kosong.
        $satu = $this->get("/kelola/penjualan/ekspor?format=csv&uuid={$kedua['Uuid']},bukan-uuid")->assertOk()->streamedContent();
        expect($satu)->toContain($kedua['Data']['Nomor'])->and($satu)->not->toContain($pertama['Data']['Nomor']);
        $abaikan = $this->get('/kelola/penjualan/ekspor?format=csv&uuid=bukan-uuid')->assertOk()->streamedContent();
        expect($abaikan)->toContain($pertama['Data']['Nomor'])->and($abaikan)->toContain($kedua['Data']['Nomor']);

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->get('/kelola/penjualan/ekspor?format=csv')->assertForbidden();
    });
});
