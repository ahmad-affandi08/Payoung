<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-09 GET /api/pos/v1/penjualan/cari (struk asal untuk retur)', function (): void {
    it('mengembalikan penjualan outlet perangkat beserta baris (snapshot, jumlah sudah/bisa diretur, sisa nilai), pembayaran, dan retur sebelumnya', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, [
            'Baris' => [['Produk' => $minyak, 'Jumlah' => '3', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '15500.00', 'Referensi' => 'QR-77'], ['Metode' => $k['Tunai'], 'Jumlah' => '100000.00']],
        ]);
        $d = $p->Detail()->firstOrFail();
        $retur = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Diterima', null]]);

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))->assertOk();

        expect($respons->json('Penjualan'))->toMatchArray([
            'Uuid' => $p->Uuid,
            'Nomor' => $p->Nomor,
            'Status' => 'DireturSebagian',
            'TotalAkhir' => '115500.00',
            'Kembalian' => '0.00',
            'BatasHariRetur' => 7,
            'BisaDiretur' => true,
            'AlasanTidakBisaDiretur' => null,
            'SisaPiutang' => null,
        ])
            ->and($respons->json('Baris'))->toHaveCount(1)
            ->and($respons->json('Baris.0'))->toMatchArray([
                'Uuid' => $d->Uuid,
                'UuidProduk' => $minyak->Uuid,
                'Jumlah' => '3.0000',
                'TotalBaris' => '115500.00',
                'JumlahSudahDiretur' => '1.0000',
                'JumlahBisaDiretur' => '2.0000',
                'NilaiBisaDiretur' => '77000.00',
            ])
            ->and($respons->json('Pembayaran.0'))->toMatchArray(['UuidMetodePembayaran' => $k['Qris']->Uuid, 'JenisMetode' => 'QrisStatis', 'Jumlah' => '15500.00', 'Referensi' => 'QR-77'])
            ->and($respons->json('Pembayaran.1.JenisMetode'))->toBe('Tunai')
            ->and($respons->json('Retur'))->toBe([[
                'Uuid' => $retur['Uuid'],
                'Nomor' => $retur['Data']['Nomor'],
                'DibuatPada' => $retur['Data']['DibuatPada'],
                'TotalRefund' => '38500.00',
            ]]);
    });

    it('baris memuat UuidProdukSatuan (satuan jual yang dipakai) dan BolehDesimal (satuan dasar produk). Kunci tambahan, kompatibel mundur', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '30');
        $dus = BantuanKomposisi::TambahSatuanProduk($minyak, BantuanKomposisi::Satuan('Dus', 'dus', false), '12');
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Curah Kiloan', 'Jenis' => JenisProduk::NonStok], '17500.00', BantuanKomposisi::Satuan('Kilogram', 'kg'));
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [
            ['Produk' => $minyak, 'Satuan' => $dus, 'Jumlah' => '1', 'Harga' => '450000.00'],
            ['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00'],
            ['Produk' => $gula, 'Jumlah' => '1.5', 'Harga' => '17500.00'],
        ]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $satuanDasarMinyak = ProdukSatuan::query()->where('IdProduk', $minyak->Id)->where('IdSatuan', $minyak->IdSatuanDasar)->value('Uuid');

        $baris = $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))->assertOk()->json('Baris');

        expect(array_map(fn (array $b): array => [$b['UuidProdukSatuan'], $b['BolehDesimal']], $baris))->toBe([
            [$dus->Uuid, false],
            [$satuanDasarMinyak, false],
            [ProdukSatuan::query()->where('IdProduk', $gula->Id)->value('Uuid'), true],
        ]);
    });

    it('penjualan void, diretur penuh, dan lewat batas hari: BisaDiretur false dengan alasan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $void = BantuanPenjualan::Jual($this, $k, $baris);
        $penuh = BantuanPenjualan::Jual($this, $k, $baris);
        $lama = BantuanPenjualan::Jual($this, $k, $baris);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $void),
            BantuanPenjualan::ItemRetur($k, $penuh, [['Detail' => $penuh->Detail()->firstOrFail()]]),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
        DB::table('Penjualan')->where('Id', $lama->Id)->update(['TanggalBisnis' => $lama->TanggalBisnis->copy()->subDays(10)->toDateString()]);

        $cari = fn (string $nomor) => $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($nomor))->assertOk();

        expect($cari($void->Nomor)->json('Penjualan.AlasanTidakBisaDiretur'))->toBe('Void')
            ->and($cari($penuh->Nomor)->json('Penjualan'))->toMatchArray(['BisaDiretur' => false, 'AlasanTidakBisaDiretur' => 'SudahDireturPenuh'])
            ->and($cari($penuh->Nomor)->json('Baris.0.JumlahBisaDiretur'))->toBe('0.0000')
            ->and($cari($lama->Nomor)->json('Penjualan'))->toMatchArray(['BisaDiretur' => false, 'AlasanTidakBisaDiretur' => 'LewatBatasHari']);
    });

    it('404 PenjualanTidakDitemukan untuk nomor tidak ada, outlet lain, dan tenant lain; 422 tanpa nomor; 401 tanpa token', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor=INV/UTAMA/000000/X-0001')
            ->assertNotFound()
            ->assertJsonPath('Galat.Kode', 'PenjualanTidakDitemukan');
        $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari')->assertUnprocessable();

        // Perangkat di outlet lain tenant yang sama.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $outletLain = Outlet::query()->create(['IdMerek' => $k['Outlet']->IdMerek, 'Kode' => 'CBG2', 'Nama' => 'Cabang Kartasura']);
        $perangkatLain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletLain);
        $this->withToken($perangkatLain['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))
            ->assertNotFound()
            ->assertJsonPath('Galat.Kode', 'PenjualanTidakDitemukan');

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        $this->withToken($b['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($p->Nomor))->assertUnauthorized();
    });

    it('menemukan struk dari isi QR struk digital (tautan, kode, atau Uuid), tanpa mengetik nomor utuh. Outlet lain tetap 404', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $kode = KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid);
        $cari = fn (string $isi) => $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($isi));

        foreach ([KodeStrukDigital::AmbilAwalan($k['Tenant']->Id).strtoupper($p->Uuid), 'https://dashboard.payoung.id/s/'.$kode.'?x=1', $kode, $p->Uuid] as $isi) {
            $cari($isi)->assertOk()->assertJsonPath('Penjualan.Nomor', $p->Nomor);
        }
        $cari('https://dashboard.payoung.id/s/'.$kode.'0')->assertNotFound();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $outletLain = Outlet::query()->create(['IdMerek' => $k['Outlet']->IdMerek, 'Kode' => 'CBG3', 'Nama' => 'Cabang Palur']);
        $perangkatLain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletLain);
        $this->withToken($perangkatLain['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($kode))->assertNotFound();
    });
});

describe('F-09 GET /api/pos/v1/penjualan/kandidat (pilih struk untuk retur tanpa mengetik nomor utuh)', function (): void {
    it('tanpa kata: penjualan terbaru yang masih bisa diretur, dengan kata: nomor yang memuat kata itu. Void, diretur penuh, dan lewat batas hari tidak muncul', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $pertama = BantuanPenjualan::Jual($this, $k, $baris);
        $kedua = BantuanPenjualan::Jual($this, $k, $baris);
        $void = BantuanPenjualan::Jual($this, $k, $baris);
        $penuh = BantuanPenjualan::Jual($this, $k, $baris);
        $lama = BantuanPenjualan::Jual($this, $k, $baris);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $void),
            BantuanPenjualan::ItemRetur($k, $penuh, [['Detail' => $penuh->Detail()->firstOrFail()]]),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
        DB::table('Penjualan')->where('Id', $lama->Id)->update(['TanggalBisnis' => $lama->TanggalBisnis->copy()->subDays(10)->toDateString()]);

        $daftar = fn (string $kata) => $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/kandidat?kata='.urlencode($kata))->assertOk()->json('Penjualan');

        expect(array_column($daftar(''), 'Nomor'))->toEqualCanonicalizing([$pertama->Nomor, $kedua->Nomor])
            ->and(array_column($daftar(substr($kedua->Nomor, -4)), 'Nomor'))->toContain($kedua->Nomor)
            ->and(array_column($daftar($kedua->Nomor), 'Nomor'))->toBe([$kedua->Nomor])
            ->and(array_column($daftar((string) (int) $kedua->TotalAkhir), 'Nomor'))->toContain($kedua->Nomor)
            ->and($daftar('x'))->toBe([])
            ->and($daftar('%'))->toBe([])
            ->and($daftar(''))->each->toHaveKeys(['Uuid', 'Nomor', 'Status', 'LabelStatus', 'TanggalBisnis', 'DibuatPada', 'TotalAkhir']);
    });

    it('hanya penjualan outlet perangkat; 401 tanpa token', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        expect($this->withToken($b['Token'])->getJson('/api/pos/v1/penjualan/kandidat')->assertOk()->json('Penjualan'))->toBe([]);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/pos/v1/penjualan/kandidat')->assertUnauthorized();
    });
});
