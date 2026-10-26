<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Penjualan\Enum\StatusPesananSendiri;
use App\Domain\Penjualan\Model\PesananSendiri;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 Self-Order QR Meja (X12, SLS-04), halaman publik tanpa login: menu per outlet (harga kanal MakanDiTempat),
 * sakelar outlet & fitur `kanal.self-order`, harga dihitung ulang server (harga peramban diabaikan), kirim pesanan
 * idempoten per Uuid, validasi pilihan min/maks & jumlah, batas 5 pesanan tertunda per meja, polling status,
 * kedaluwarsa 30 menit, dan isolasi tenant.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('F-17 halaman publik pesan sendiri', function (): void {
    it('menampilkan menu meja: kategori, harga server, kelompok pilihan. Produk tersembunyi/bahan baku tidak ikut', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanKatalog::BuatProduk(['Nama' => 'Gula Aren Cair Bahan Baku', 'Jenis' => JenisProduk::BahanBaku]);
        BantuanKatalog::BuatProduk(['Nama' => 'Menu Rahasia Dapur', 'TampilDiPos' => false]);
        BantuanKatalog::BuatProduk(['Nama' => 'Menu Musiman Diarsipkan', 'Aktif' => false]);
        BantuanKatalog::BuatProduk(['Nama' => 'Produk Tanpa Harga Jual'], null);

        $this->get($k['Alamat'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/PesanSendiri')
            ->where('Aktif', true)
            ->where('Toko.Nama', 'Kedai Kopi Senja Rasa Nusantara')
            ->where('Toko.NamaOutlet', $k['Outlet']->Nama)
            ->where('Meja.Nama', '7')
            ->where('Token', $k['TokenMeja'])
            ->where('Slug', $k['Slug'])
            ->has('Menu.Kategori', 2)
            ->has('Menu.Produk', 2)
            ->where('Menu.Produk.0.Nama', 'Es Kopi Susu Gula Aren Ukuran Besar')
            ->where('Menu.Produk.0.Harga', '25000.00')
            ->where('Menu.Produk.0.UuidKategori', $k['Minuman']->Uuid)
            ->where('Menu.Produk.0.UrlGambar', null)
            ->where('Menu.Produk.0.KelompokPilihan.0.Nama', 'Level Gula')
            ->where('Menu.Produk.0.KelompokPilihan.0.MinimalPilih', 1)
            ->where('Menu.Produk.0.KelompokPilihan.1.Pilihan.0', ['Uuid' => $k['Boba']->Uuid, 'Nama' => 'Boba brown sugar', 'Harga' => '5000.00'])
            ->where('Menu.Produk.1.Nama', 'Nasi Goreng Kampung Spesial Telur Mata Sapi')
            ->where('Menu.Produk.1.KelompokPilihan', []));
    });

    it('slug atau token tidak dikenal = halaman tidak ditemukan (404). Rute sistem tidak tertutupi', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        $this->get("/{$k['Slug']}/meja/".Str::random(32))->assertNotFound()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Publik/PesanSendiri')->where('Meja', null)->where('Aktif', false));
        $this->get("/usaha-tidak-ada/meja/{$k['TokenMeja']}")->assertNotFound();
        $this->get("/{$k['Slug']}/meja/pendek")->assertNotFound();
        $this->postJson("/usaha-tidak-ada/meja/{$k['TokenMeja']}/hitung", ['Baris' => []])->assertNotFound()->assertJsonPath('Galat.Kode', 'MejaTidakDitemukan');
        $this->get('/masuk')->assertOk();
    });

    it('sakelar outlet mati, fitur kanal.self-order tidak aktif, atau meja diarsipkan: tidak bisa memesan', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]);

        $k['Outlet']->forceFill(['PesanSendiriAktif' => false])->save();
        $this->get($k['Alamat'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', false)->where('Menu.Produk', [])->where('Meja.Nama', '7'));
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertStatus(409)->assertJsonPath('Galat.Kode', 'PesanSendiriTidakAktif');
        $this->postJson("{$k['Alamat']}/hitung", ['Baris' => []])->assertStatus(409);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $k['Outlet']->forceFill(['PesanSendiriAktif' => true])->save();
        OverrideTenant::query()->where('IdTenant', $k['Tenant']->Id)->delete();
        BantuanPesanSendiri::CabutFiturDariPaket();
        $this->get($k['Alamat'])->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', false));
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertStatus(409)->assertJsonPath('Galat.Kode', 'PesanSendiriTidakAktif');

        BantuanPesanSendiri::AktifkanFitur($k['Tenant']);
        $k['Meja']->forceFill(['Status' => 'Diarsipkan'])->save();
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertStatus(409);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananSendiri::query()->count())->toBe(0);
    });

    it('hitung: harga dari server (harga peramban diabaikan), pilihan dijumlahkan, perkiraan total = subtotal bila outlet tanpa pajak', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [
            ['UuidProduk' => $k['Kopi']->Uuid, 'Jumlah' => 2, 'Pilihan' => [$k['GulaSedikit']->Uuid, $k['Boba']->Uuid, $k['Keju']->Uuid], 'HargaSatuan' => '1.00'],
            ['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 1, 'Harga' => '0.00'],
        ]])->assertOk()->assertExactJson([
            'Baris' => [
                ['UuidProduk' => $k['Kopi']->Uuid, 'NamaProduk' => 'Es Kopi Susu Gula Aren Ukuran Besar', 'Jumlah' => '2.0000', 'HargaSatuan' => '25000.00', 'HargaPilihan' => '11000.00', 'Total' => '72000.00'],
                ['UuidProduk' => $k['Nasi']->Uuid, 'NamaProduk' => 'Nasi Goreng Kampung Spesial Telur Mata Sapi', 'Jumlah' => '1.0000', 'HargaSatuan' => '35000.00', 'HargaPilihan' => '0.00', 'Total' => '35000.00'],
            ],
            'Subtotal' => '107000.00',
            // PRD v2.06: estimasi total dari mesin kalkulasi (outlet ini tanpa pajak & biaya layanan).
            'Diskon' => '0.00',
            'BiayaLayanan' => '0.00',
            'Pajak' => [],
            'PajakTermasukHarga' => '0.00',
            'Pembulatan' => '0.00',
            'Total' => '107000.00',
            'Catatan' => 'Perkiraan. Total akhir mengikuti tagihan di kasir (promo, pembulatan, metode bayar).',
        ]);
    });

    it('kirim pesanan: 201 bernomor QR per outlet per hari, snapshot harga server, idempoten per Uuid (200), Uuid di meja lain ditolak', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Kopi'], 2, [$k['GulaNormal'], $k['Jeli']], 'Es dipisah ya'], [$k['Nasi'], 1]], nama: 'Bu Ratna', catatan: 'Tolong cepat, mau rapat');
        $kiriman['Baris'][0]['HargaSatuan'] = '100.00';
        $tanggal = CarbonImmutable::now('Asia/Jakarta')->format('ymd');
        $nomor = "QR/{$k['Outlet']->Kode}/{$tanggal}-0001";

        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated()
            ->assertExactJson(['Uuid' => $kiriman['Uuid'], 'Nomor' => $nomor, 'Status' => 'MenungguKonfirmasi']);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertOk()
            ->assertExactJson(['Uuid' => $kiriman['Uuid'], 'Nomor' => $nomor, 'Status' => 'MenungguKonfirmasi']);
        $this->postJson("{$k['Alamat9']}/pesan", $kiriman)->assertStatus(409)->assertJsonPath('Galat.Kode', 'UuidDipakai');
        $this->postJson("{$k['Alamat9']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 3]]))->assertCreated()
            ->assertJsonPath('Nomor', "QR/{$k['Outlet']->Kode}/{$tanggal}-0002");

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananSendiri::query()->where('Uuid', $kiriman['Uuid'])->sole();
        expect($pesanan->Status)->toBe(StatusPesananSendiri::MenungguKonfirmasi)
            ->and($pesanan->IdMeja)->toBe($k['Meja']->Id)
            ->and($pesanan->NamaPemesan)->toBe('Bu Ratna')
            ->and($pesanan->Catatan)->toBe('Tolong cepat, mau rapat')
            ->and($pesanan->Subtotal)->toBe('93000.00')
            ->and($pesanan->HashIp)->toHaveLength(64)
            ->and($pesanan->HashIp)->not->toContain('127.0.0.1')
            ->and($pesanan->Baris[0])->toEqual([
                'Uuid' => $kiriman['Baris'][0]['Uuid'],
                'UuidProduk' => $k['Kopi']->Uuid,
                'UuidProdukSatuan' => $pesanan->Baris[0]['UuidProdukSatuan'],
                'NamaProduk' => 'Es Kopi Susu Gula Aren Ukuran Besar',
                'Jumlah' => '2.0000',
                'HargaSatuan' => '25000.00',
                'HargaPilihan' => '4000.00',
                'Pilihan' => [
                    ['UuidPilihan' => $k['GulaNormal']->Uuid, 'Nama' => 'Normal', 'Harga' => '0.00'],
                    ['UuidPilihan' => $k['Jeli']->Uuid, 'Nama' => 'Jeli kopi', 'Harga' => '4000.00'],
                ],
                'Catatan' => 'Es dipisah ya',
            ])
            ->and($pesanan->Baris[0]['UuidProdukSatuan'])->toHaveLength(26)
            ->and(PesananSendiri::query()->count())->toBe(2);
    });

    it('validasi: pilihan wajib/maksimal/milik produk, produk tidak tersedia, jumlah bulat 1–50, maksimal 30 baris', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $bahan = BantuanKatalog::BuatProduk(['Nama' => 'Gula Aren Cair Bahan Baku', 'Jenis' => JenisProduk::BahanBaku]);
        $alamat = "{$k['Alamat']}/pesan";
        $kirim = fn (array $baris) => $this->postJson($alamat, BantuanPesanSendiri::Kiriman($baris));

        $kirim([[$k['Kopi'], 1]])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PilihanTidakValid')
            ->assertJsonPath('Galat.Pesan', 'Pilih tepat 1 Level Gula untuk Es Kopi Susu Gula Aren Ukuran Besar.');
        $kirim([[$k['Kopi'], 1, [$k['GulaNormal'], $k['GulaSedikit']]]])->assertJsonPath('Galat.Kode', 'PilihanTidakValid');
        $kirim([[$k['Kopi'], 1, [$k['GulaNormal'], $k['Boba'], $k['Keju'], $k['Jeli']]]])->assertJsonPath('Galat.Kode', 'PilihanTidakValid');
        $kirim([[$k['Nasi'], 1, [$k['Boba']]]])->assertJsonPath('Galat.Kode', 'PilihanTidakValid');
        $kirim([[$bahan, 1]])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');
        $this->postJson($alamat, [...BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]), 'Baris' => [['Uuid' => (string) Str::ulid(), 'UuidProduk' => (string) Str::ulid(), 'Jumlah' => 1]]])
            ->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');

        foreach ([0, 51, 1.5, '2a'] as $jumlah) {
            $salah = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]);
            $salah['Baris'][0]['Jumlah'] = $jumlah;
            $this->postJson($alamat, $salah)->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        }

        $this->postJson($alamat, BantuanPesanSendiri::Kiriman(array_fill(0, 31, [$k['Nasi'], 1])))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ValidasiGagal')
            ->assertJsonPath('Galat.Pesan', 'Paling banyak 30 menu dalam satu pesanan.');
        $this->postJson($alamat, BantuanPesanSendiri::Kiriman([]))->assertUnprocessable()->assertJsonPath('Galat.Pesan', 'Keranjang masih kosong.');
        $this->postJson($alamat, [...BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]), 'Uuid' => 'bukan-ulid'])->assertUnprocessable();
        $this->postJson($alamat, BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]], nama: str_repeat('a', 61)))->assertUnprocessable();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananSendiri::query()->count())->toBe(0);
        $kirim([[$k['Kopi'], 1, [$k['GulaSedikit'], $k['Boba'], $k['Keju']]]])->assertCreated();
    });

    it('batas 5 pesanan menunggu per meja. Kedaluwarsa 30 menit membebaskan jatah. Meja lain tidak terpengaruh', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        foreach (range(1, 5) as $_) {
            $this->postJson("{$k['Alamat']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
        }

        $this->postJson("{$k['Alamat']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPesanan');
        $this->postJson("{$k['Alamat9']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();

        $this->travel(31)->minutes();
        $this->postJson("{$k['Alamat']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananSendiri::query()->where('IdMeja', $k['Meja']->Id)->where('Status', 'Kedaluwarsa')->count())->toBe(5);
    });

    it('polling status: menunggu → kedaluwarsa setelah 30 menit. Hanya dari meja yang sama', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 2]]);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();

        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertOk()
            ->assertJsonPath('Status', 'MenungguKonfirmasi')
            ->assertJsonPath('Subtotal', '70000.00')
            ->assertJsonPath('AlasanTolak', null)
            ->assertJsonPath('Baris.0.NamaProduk', 'Nasi Goreng Kampung Spesial Telur Mata Sapi')
            ->assertJsonStructure(['Uuid', 'Nomor', 'Status', 'Baris', 'Subtotal', 'AlasanTolak']);
        $this->getJson("{$k['Alamat9']}/pesanan/{$kiriman['Uuid']}")->assertNotFound()->assertJsonPath('Galat.Kode', 'PesananTidakDitemukan');

        $this->travel(31)->minutes();
        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertOk()->assertJsonPath('Status', 'Kedaluwarsa');
    });

    it('isolasi tenant: token meja tenant A lewat slug tenant B tidak dikenal', function (): void {
        $a = BantuanPesanSendiri::Siapkan($this);
        ['Tenant' => $b] = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        $slugB = $b->refresh()->Slug;

        $this->get("/{$slugB}/meja/{$a['TokenMeja']}")->assertNotFound();
        $this->postJson("/{$slugB}/meja/{$a['TokenMeja']}/pesan", BantuanPesanSendiri::Kiriman([[$a['Nasi'], 1]]))
            ->assertNotFound()->assertJsonPath('Galat.Kode', 'MejaTidakDitemukan');
    });
});
