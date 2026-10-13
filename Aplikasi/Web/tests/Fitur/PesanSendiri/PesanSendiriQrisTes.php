<?php

declare(strict_types=1);

use App\Domain\Katalog\Aksi\UbahKetersediaanProduk;
use App\Domain\Penjualan\Aksi\KedaluwarsakanPesananOnline;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 SLS-04 (v6.05): menu self-order QR meja disegarkan berkala (produk "86" hilang, BR-17.2) dan tamu bisa membayar
 * QRIS dari HP lewat jalur PesananOnline (Sumber Meja); bayar di kasir (PesananSendiri) tidak berubah.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/** Restoran siap + QRIS toko siap (gerbang, sakelar, metode). */
function SiapkanMejaQris(object $tes): array
{
    $k = BantuanPesanSendiri::Siapkan($tes);
    $gerbang = BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->create(['Aktif' => false, 'QrisAktif' => true]);
    BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');

    return $k + ['Gerbang' => $gerbang];
}

describe('menu disegarkan berkala (BR-17.2)', function (): void {
    it('GET /menu mengembalikan menu terkini dan produk yang ditandai habis hilang', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        $awal = $this->getJson("{$k['Alamat']}/menu")->assertOk();
        $nama = collect($awal->json('Produk'))->pluck('Nama')->all();
        expect($nama)->toContain($k['Nasi']->Nama);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Nasi']->Uuid, true, null, $k['Pemilik']->Id);

        $kini = $this->getJson("{$k['Alamat']}/menu")->assertOk();
        expect(collect($kini->json('Produk'))->pluck('Nama')->all())->not->toContain($k['Nasi']->Nama);
    });

    it('menu hanya untuk meja aktif: token salah 404', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        $this->getJson("/{$k['Slug']}/meja/".Str::random(32).'/menu')->assertNotFound();
    });
});

describe('bayar QRIS dari HP tamu', function (): void {
    it('halaman memberi tahu apakah QRIS tersedia', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $this->get($k['Alamat'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.Qris', false));

        $k = SiapkanMejaQris($this);
        $this->get($k['Alamat'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.Qris', true));
    });

    it('QRIS ditolak selama gerbang atau sakelar belum siap; pesanan bayar di kasir tetap jalan', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);

        $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'MetodePembayaranTidakAktif');
        $this->postJson("{$k['Alamat']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananOnline::query()->count())->toBe(0);
    });

    it('membuat pesanan menunggu pembayaran bernama meja, idempoten per Uuid; Uuid meja lain 409', function (): void {
        $k = SiapkanMejaQris($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 2]]);

        $a = $this->postJson("{$k['Alamat']}/pesan-qris", $kiriman)->assertCreated()
            ->assertJsonPath('Status', 'MenungguPembayaran')->assertJsonPath('PerluBayar', true)->assertJsonPath('SudahDibayar', false);
        $b = $this->postJson("{$k['Alamat']}/pesan-qris", $kiriman)->assertOk();

        expect($b->json('KodeAkses'))->toBe($a->json('KodeAkses'));
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananOnline::query()->sole();
        expect($pesanan->Sumber)->toBe(SumberPesananOnline::Meja)
            ->and($pesanan->IdMeja)->toBe($k['Meja']->Id)
            ->and($pesanan->NamaPelanggan)->toBe('Meja 7 | Bu Ratna')
            ->and($pesanan->Nomor)->toStartWith('ME/')
            ->and($pesanan->NoHp)->toBe('')
            ->and($pesanan->Detail)->toHaveCount(1);

        $this->postJson("{$k['Alamat9']}/pesan-qris", $kiriman)->assertStatus(409)->assertJsonPath('Galat.Kode', 'UuidDipakai');
    });

    it('QR/halaman bayar dibuat sekali, pembayaran masuk memindahkan pesanan ke menunggu kasir', function (): void {
        Http::fake(function (PermintaanHttp $r) {
            if (BantuanGerbangTenant::CekPermintaanBuat($r)) {
                return BantuanGerbangTenant::ResponsBuat($r, 'trx-meja');
            }

            return BantuanGerbangTenant::ResponsStatus('PENDING');
        });
        $k = SiapkanMejaQris($this);
        $kode = $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated()->json('KodeAkses');

        $bayar = $this->postJson("{$k['Alamat']}/pesan-qris/{$kode}/bayar")->assertCreated()->assertJsonPath('SudahDibayar', false);
        expect($bayar->json('UrlBayar'))->toStartWith('https://sandbox.doku.com/checkout/link/');
        $this->postJson("{$k['Alamat']}/pesan-qris/{$kode}/bayar")->assertOk();
        expect(count(Http::recorded(fn (PermintaanHttp $r): bool => BantuanGerbangTenant::CekPermintaanBuat($r))))->toBe(1);
        $this->getJson("{$k['Alamat']}/pesan-qris/{$kode}/status")->assertOk()->assertJsonPath('PerluBayar', true)->assertJsonPath('StatusTagihan', 'Menunggu');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();
        BantuanGerbangTenant::KirimWebhook($this, $k['Gerbang']->TokenWebhook, $tagihan->NomorPesanan, $tagihan->Jumlah)->assertOk();

        $this->getJson("{$k['Alamat']}/pesan-qris/{$kode}/status")->assertOk()
            ->assertJsonPath('PerluBayar', false)->assertJsonPath('SudahDibayar', true)->assertJsonPath('Status', 'MenungguKonfirmasi');
    });

    it('kode akses meja lain, kios, atau toko online tidak bisa dibaca atau dibayar lewat meja ini', function (): void {
        $k = SiapkanMejaQris($this);
        $kode = $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated()->json('KodeAkses');

        $this->getJson("{$k['Alamat9']}/pesan-qris/{$kode}/status")->assertNotFound();
        $this->postJson("{$k['Alamat9']}/pesan-qris/{$kode}/bayar")->assertNotFound();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PesananOnline::query()->where('KodeAkses', $kode)->update(['Sumber' => SumberPesananOnline::Web->value]);
        $this->getJson("{$k['Alamat']}/pesan-qris/{$kode}/status")->assertNotFound();
        $this->getJson("{$k['Alamat']}/pesan-qris/XXXXXXXXXXXXXXXX/status")->assertNotFound();
    });

    it('paling banyak 5 pesanan menunggu pembayaran per meja (429)', function (): void {
        $k = SiapkanMejaQris($this);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
        }

        $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPesanan');
        $this->postJson("{$k['Alamat9']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
    });

    it('produk habis ditolak dan pesanan belum dibayar hangus setelah 30 menit', function (): void {
        $k = SiapkanMejaQris($this);
        $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananOnline::query()->sole();

        expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(0);
        $this->travel(31)->minutes();
        expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(1)
            ->and($pesanan->refresh()->Status)->toBe(StatusPesananOnline::Kedaluwarsa);

        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Nasi']->Uuid, true, null, $k['Pemilik']->Id);
        $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertUnprocessable();
    });

    it('keranjang kosong dan jumlah tidak wajar ditolak validasi', function (): void {
        $k = SiapkanMejaQris($this);

        $this->postJson("{$k['Alamat']}/pesan-qris", [...BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]), 'Baris' => []])->assertUnprocessable();
        $this->postJson("{$k['Alamat']}/pesan-qris", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 100]]))->assertUnprocessable();
    });
});
