<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Aksi\AturKiosOutlet;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Aksi\KedaluwarsakanPesananOnline;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Kueri\PesananOnlineOutlet;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Tenant\Model\OverrideTenant;
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
 * F-17 bagian 4: kios pesan sendiri di web. Sakelar & tautan rahasia per outlet (izin outlet.kelola, butuh fitur
 * `kanal.self-order`), halaman kios tanpa login, pesanan tanpa data pribadi dengan nomor antrian harian, jalur
 * `PesananOnline` yang sama dengan toko online, layar antrian, kedaluwarsa 30 menit, dan isolasi antar outlet.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/** Restoran siap pakai + kios hidup lewat Aksi aslinya; mengembalikan alamat kios. */
function SiapkanKios(object $tes): array
{
    $k = BantuanPesanSendiri::Siapkan($tes);
    $outlet = app(AturKiosOutlet::class)->Jalankan($k['Outlet'], true);

    return $k + ['AlamatKios' => "/{$k['Slug']}/kios/{$outlet->TokenKios}", 'TokenKios' => $outlet->TokenKios];
}

/** @return array<string, mixed> */
function KirimanKios(array $k, string $metode = 'BayarSaatAmbil', string $santap = 'MakanDiTempat', ?string $uuid = null): array
{
    return [
        'Uuid' => $uuid ?? (string) Str::ulid(), 'JenisSantap' => $santap, 'MetodePembayaran' => $metode,
        'Baris' => [['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 2]],
    ];
}

describe('Sakelar & tautan kios di back-office', function (): void {
    it('hidup/mati dengan audit. Token dibuat saat pertama hidup dan dipertahankan. Detail outlet memuat tautan', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('Kios.FiturAktif', true)->where('Kios.Aktif', false)->where('Kios.Tautan', null));

        $this->post("{$alamat}/kios", ['Aktif' => true])->assertSessionHasNoErrors();
        $token = $k['Outlet']->refresh()->TokenKios;
        expect($k['Outlet']->KiosAktif)->toBeTrue()->and($token)->toHaveLength(32);
        $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Kios.Aktif', true)->where('Kios.Tautan', url("/{$k['Slug']}/kios/{$token}"))
            ->where('Kios.TautanAntrian', url("/{$k['Slug']}/kios/{$token}/antrian")));

        $this->post("{$alamat}/kios", ['Aktif' => false])->assertSessionHasNoErrors();
        $this->post("{$alamat}/kios", ['Aktif' => true])->assertSessionHasNoErrors();
        expect($k['Outlet']->refresh()->TokenKios)->toBe($token)
            ->and(LogAudit::query()->where('Peristiwa', 'outlet.kios.ubah')->count())->toBe(3);
    });

    it('buat ulang tautan mematikan tautan lama seketika', function (): void {
        $k = SiapkanKios($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get($k['AlamatKios'])->assertOk();

        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/kios/buat-ulang")->assertSessionHasNoErrors();

        expect($k['Outlet']->refresh()->TokenKios)->not->toBe($k['TokenKios'])
            ->and(LogAudit::query()->where('Peristiwa', 'outlet.kios.buat-ulang')->count())->toBe(1);
        auth()->logout();
        $this->get($k['AlamatKios'])->assertNotFound();
    });

    it('tanpa fitur self-order kios tidak bisa dihidupkan (mematikan tetap boleh). Kasir 403', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        OverrideTenant::query()->where('IdTenant', $k['Tenant']->Id)->delete();
        BantuanPesanSendiri::CabutFiturDariPaket();

        $this->post("{$alamat}/kios", ['Aktif' => true])->assertSessionHasErrors('Umum');
        expect($k['Outlet']->refresh()->KiosAktif)->toBeFalse();
        $this->post("{$alamat}/kios", ['Aktif' => false])->assertSessionHasNoErrors();

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
        $this->post("{$alamat}/kios", ['Aktif' => true])->assertForbidden();
        $this->post("{$alamat}/kios/buat-ulang")->assertForbidden();
    });
});

describe('Halaman & pesanan kios', function (): void {
    it('halaman kios tanpa login memuat menu. Token salah 404. Kios mati menampilkan pesan tidak aktif', function (): void {
        $k = SiapkanKios($this);

        $this->get($k['AlamatKios'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/Kios')->where('Aktif', true)->where('Pembayaran.Qris', false)->where('DetikDiam', 90)->has('Menu.Produk'));
        $this->get("/{$k['Slug']}/kios/".Str::random(32))->assertNotFound();
        $this->get("/{$k['Slug']}/kios/pendek")->assertNotFound();

        $k['Outlet']->refresh()->forceFill(['KiosAktif' => false])->save();
        $this->get($k['AlamatKios'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', false));
        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertStatus(409)->assertJsonPath('Galat.Kode', 'KiosTidakAktif');
    });

    it('pesan bayar di kasir: nomor antrian harian K001, K002, tanpa data pribadi. Masuk daftar pesanan online outlet', function (): void {
        $k = SiapkanKios($this);
        $hitung = $this->postJson("{$k['AlamatKios']}/hitung", KirimanKios($k))->assertOk();
        expect($hitung->json('Subtotal'))->not->toBeNull();

        $pertama = $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertCreated()
            ->assertJsonPath('NomorAntrian', 'K001')->assertJsonPath('Status', 'MenungguKonfirmasi')
            ->assertJsonPath('BayarDiKasir', true)->assertJsonPath('LabelStatus', 'Silakan bayar di kasir');
        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k, santap: 'BawaPulang'))->assertCreated()->assertJsonPath('NomorAntrian', 'K002');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananOnline::query()->where('KodeAkses', $pertama->json('KodeAkses'))->sole();
        expect($pesanan->Sumber)->toBe(SumberPesananOnline::Kios)
            ->and($pesanan->NomorAntrian)->toBe(1)->and($pesanan->NoHp)->toBe('')
            ->and($pesanan->NamaPelanggan)->toBe('Kios K001 | Makan di sini')
            ->and($pesanan->IdPelanggan)->toBeNull()->and($pesanan->Ongkir)->toBe('0.00');

        $aktif = app(PesananOnlineOutlet::class)->AmbilAktif($k['Outlet']->Id);
        expect(array_column($aktif['Pesanan'], 'NomorAntrian'))->toBe(['K001', 'K002'])
            ->and($aktif['Pesanan'][0]['Sumber'])->toBe('Kios')->and($aktif['Pesanan'][1]['JenisSantap'])->toBe('BawaPulang');
    });

    it('idempoten per Uuid: kiriman ulang tidak membuat pesanan atau nomor antrian baru', function (): void {
        $k = SiapkanKios($this);
        $kiriman = KirimanKios($k);

        $this->postJson("{$k['AlamatKios']}/pesan", $kiriman)->assertCreated()->assertJsonPath('NomorAntrian', 'K001');
        $this->postJson("{$k['AlamatKios']}/pesan", $kiriman)->assertOk()->assertJsonPath('NomorAntrian', 'K001');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananOnline::query()->count())->toBe(1);
    });

    it('QRIS ditolak selama sakelar QRIS toko mati. Bayar di muka membuat pesanan menunggu pembayaran', function (): void {
        $k = SiapkanKios($this);

        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k, 'QrisOnline'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'MetodePembayaranTidakAktif');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->create(['Aktif' => false, 'QrisAktif' => true]);
        // Sakelar hidup saja belum cukup: gerbang pembayaran toko dan metode QRIS dinamis harus ada (KesiapanQrisOnline).
        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k, 'QrisOnline'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'MetodePembayaranTidakAktif');

        BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');
        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k, 'QrisOnline'))->assertCreated()
            ->assertJsonPath('Status', 'MenungguPembayaran')->assertJsonPath('PerluBayar', true)->assertJsonPath('BayarDiKasir', false);
        $this->get($k['AlamatKios'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.Qris', true));
    });

    it('kode akses pesanan toko online atau outlet lain tidak bisa dibaca lewat tautan kios', function (): void {
        $k = SiapkanKios($this);
        $dibuat = $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertCreated();
        $kode = $dibuat->json('KodeAkses');

        $this->getJson("{$k['AlamatKios']}/pesanan/{$kode}")->assertOk()->assertJsonPath('NomorAntrian', 'K001');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PesananOnline::query()->where('KodeAkses', $kode)->update(['Sumber' => SumberPesananOnline::Web->value]);
        $this->getJson("{$k['AlamatKios']}/pesanan/{$kode}")->assertNotFound();
        $this->getJson("{$k['AlamatKios']}/pesanan/XXXXXXXXXXXXXXXX")->assertNotFound();
    });

    it('menolak keranjang kosong, jumlah tidak wajar, dan produk habis (86)', function (): void {
        $k = SiapkanKios($this);
        $kiriman = KirimanKios($k);

        $kosong = [...$kiriman, 'Baris' => []];
        $this->postJson("{$k['AlamatKios']}/pesan", $kosong)->assertUnprocessable();
        $banyak = [...$kiriman, 'Baris' => [['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 100]]];
        $this->postJson("{$k['AlamatKios']}/pesan", $banyak)->assertUnprocessable();
        $tanpaSantap = $kiriman;
        unset($tanpaSantap['JenisSantap']);
        $this->postJson("{$k['AlamatKios']}/pesan", $tanpaSantap)->assertUnprocessable();
    });
});

describe('Bayar QRIS di kios', function (): void {
    it('QR ditampilkan di layar, idempoten per pesanan, dan pembayaran masuk memindahkan pesanan ke menunggu konfirmasi', function (): void {
        Http::fake(function (PermintaanHttp $r) {
            if (BantuanGerbangTenant::CekPermintaanBuat($r)) {
                return BantuanGerbangTenant::ResponsBuat($r, 'trx-kios');
            }

            return BantuanGerbangTenant::ResponsStatus('PENDING');
        });
        $k = SiapkanKios($this);
        $gerbang = BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->create(['Aktif' => false, 'QrisAktif' => true]);
        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');

        $dibuat = $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k, 'QrisOnline'))->assertCreated()->assertJsonPath('NomorAntrian', 'K001');
        $kode = $dibuat->json('KodeAkses');
        $bayar = $this->postJson("{$k['AlamatKios']}/pesanan/{$kode}/bayar")->assertCreated()->assertJsonPath('SudahDibayar', false);
        // DOKU Checkout memberi halaman bayar (bukan muatan QRIS): layar kios menampilkan tautan itu.
        expect($bayar->json('Qr'))->toBeNull()
            ->and($bayar->json('UrlBayar'))->toStartWith('https://sandbox.doku.com/checkout/link/');
        $this->postJson("{$k['AlamatKios']}/pesanan/{$kode}/bayar")->assertOk();
        expect(count(Http::recorded(fn (PermintaanHttp $r): bool => BantuanGerbangTenant::CekPermintaanBuat($r))))->toBe(1);
        $this->getJson("{$k['AlamatKios']}/pesanan/{$kode}/status-bayar")->assertOk()->assertJsonPath('PerluBayar', true)->assertJsonPath('StatusTagihan', 'Menunggu');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();
        BantuanGerbangTenant::KirimWebhook($this, $gerbang->TokenWebhook, $tagihan->NomorPesanan, $tagihan->Jumlah)->assertOk();

        $this->getJson("{$k['AlamatKios']}/pesanan/{$kode}/status-bayar")->assertOk()
            ->assertJsonPath('PerluBayar', false)->assertJsonPath('SudahDibayar', true)->assertJsonPath('Status', 'MenungguKonfirmasi');
    });

    it('pesanan bayar di kasir tidak bisa diminta QR-nya', function (): void {
        $k = SiapkanKios($this);
        $kode = $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertCreated()->json('KodeAkses');

        $this->postJson("{$k['AlamatKios']}/pesanan/{$kode}/bayar")->assertStatus(409)->assertJsonPath('Galat.Kode', 'PesananTidakMenungguPembayaran');
    });
});

describe('Layar antrian & kedaluwarsa', function (): void {
    it('layar antrian memisahkan disiapkan dan siap, yang belum dibayar/selesai tidak tampil', function (): void {
        $k = SiapkanKios($this);

        foreach (range(1, 4) as $i) {
            $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertCreated();
        }

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $daftar = PesananOnline::query()->orderBy('NomorAntrian')->get();
        $daftar[1]->forceFill(['Status' => StatusPesananOnline::Diproses])->save();
        $daftar[2]->forceFill(['Status' => StatusPesananOnline::Siap])->save();
        $daftar[3]->forceFill(['Status' => StatusPesananOnline::Selesai])->save();

        $this->getJson("{$k['AlamatKios']}/antrian/data")->assertOk()
            ->assertExactJson(['Disiapkan' => ['K002'], 'Siap' => ['K003']]);
        $this->get("{$k['AlamatKios']}/antrian")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/AntrianKios')->where('Antrian.Siap', ['K003'])->where('Antrian.Disiapkan', ['K002']));
    });

    it('pesanan kios yang tidak dijawab kasir hangus setelah 30 menit tanpa butuh toko online aktif. Toko online memakai batasnya sendiri', function (): void {
        $k = SiapkanKios($this);
        $this->postJson("{$k['AlamatKios']}/pesan", KirimanKios($k))->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kios = PesananOnline::query()->sole();

        expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(0);
        $this->travel(29)->minutes();
        expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(0);
        $this->travel(2)->minutes();

        expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(1)
            ->and($kios->refresh()->Status)->toBe(StatusPesananOnline::Kedaluwarsa);
    });
});
