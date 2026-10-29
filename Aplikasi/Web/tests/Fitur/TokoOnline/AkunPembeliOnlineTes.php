<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\SesiPembeliOnline;
use App\Domain\Pelanggan\Model\KodeMasukPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\SesiPelangganOnline;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-17 toko online bagian 3 — akun pembeli opsional: masuk dengan kode WhatsApp (tanpa kata sandi), identitasnya
 * `Pelanggan` toko yang sama dengan pelanggan kasir, sesi cookie per toko, pesanan tertaut & harga tier di checkout.
 */

const NOMOR_PEMBELI = '0812-7777-1234';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']])]);
});

/** Kode 6 digit dari pesan WhatsApp terakhir yang dikirim (penyedia Fonnte palsu). */
function AmbilKodeTerkirim(): string
{
    $terakhir = collect(Http::recorded())->last();
    expect($terakhir)->not->toBeNull();
    /** @var PermintaanHttp $permintaan */
    $permintaan = $terakhir[0];
    preg_match('/^(\d{6}) adalah kode masuk/', (string) $permintaan['message'], $cocok);

    return $cocok[1] ?? '';
}

/**
 * Minta kode lalu masuk; nomor baru langsung didaftarkan dengan nama "Sinta". Mengembalikan token cookie sesi.
 *
 * @param  array<string, mixed>  $k
 */
function MasukSebagaiPembeli(TestCase $tes, array $k, string $nomor = NOMOR_PEMBELI): string
{
    $tes->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => $nomor])->assertStatus(202);
    $masuk = $tes->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => $nomor, 'Kode' => AmbilKodeTerkirim()])->assertOk();

    if ($masuk->json('PerluDaftar') === true) {
        $masuk = $tes->postJson($k['AlamatToko'].'/akun/daftar', [
            'TokenDaftar' => $masuk->json('TokenDaftar'), 'NoHp' => $nomor, 'Nama' => 'Sinta Maharani',
            'SetujuDataPribadi' => true, 'SetujuPemasaran' => false,
        ])->assertCreated();
    }

    return AmbilTokenCookie($masuk);
}

function AmbilTokenCookie(TestResponse $respons): string
{
    // Nilai yang didekripsi (middleware EncryptCookies mengenkripsi cookie respons; `withCookie` mengenkripsinya lagi).
    $cookie = $respons->getCookie(SesiPembeliOnline::NAMA_COOKIE);
    expect($cookie)->not->toBeNull()->and($cookie?->isHttpOnly())->toBeTrue()->and($cookie?->getSameSite())->toBe('lax');

    return (string) $cookie?->getValue();
}

it('tombol masuk hanya aktif bila WhatsApp platform & sakelar toko hidup, tanpa itu checkout tamu tetap jalan', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);

    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.Aktif', true)->where('Akun.Pelanggan', null));

    config(['integrasi.Whatsapp' => null]);
    $this->get($k['AlamatToko'])->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.Aktif', false));
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(409)->assertJsonPath('Galat.Kode', 'MasukBelumTersedia');
    $this->postJson($k['AlamatToko'].'/pesan', BantuanTokoOnline::Kiriman($k))->assertCreated();

    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->firstOrFail()->forceFill(['AkunPelangganAktif' => false])->save();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(409);
    Http::assertNothingSent();
});

it('nomor baru: kode WhatsApp → salah sekali → benar → lengkapi nama → menjadi Pelanggan terverifikasi dan masuk', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);

    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202)->assertJsonStructure(['KedaluwarsaPada', 'KirimUlangPada']);
    $kode = AmbilKodeTerkirim();
    Http::assertSent(fn (PermintaanHttp $p): bool => $p['target'] === '6281277771234' && str_contains((string) $p['message'], 'Jangan berikan kode ini'));

    // Nomor HP & kode tidak pernah tersimpan polos.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $baris = KodeMasukPelanggan::query()->sole();
    expect($baris->HashNoHp)->not->toContain('81277771234')->and($baris->HashKode)->not->toBe($kode);

    $salah = $kode === '000000' ? '111111' : '000000';
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $salah])
        ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'KodeSalah')->assertJsonPath('Galat.Pesan', 'Kode salah. Sisa 4 kali percobaan.');

    $masuk = $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $kode])->assertOk()
        ->assertJsonPath('PerluDaftar', true);
    expect(array_map(fn (Cookie $c): string => $c->getName(), $masuk->headers->getCookies()))
        ->not->toContain(SesiPembeliOnline::NAMA_COOKIE, 'Belum ada sesi sebelum nama dilengkapi.');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Pelanggan::query()->count())->toBe(0, 'Pelanggan baru tidak dibuat sebelum pembeli setuju & mengisi nama.');

    // Kode yang sudah dipakai tidak bisa dipakai lagi.
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $kode])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'KodeTidakBerlaku');

    $this->postJson($k['AlamatToko'].'/akun/daftar', [
        'TokenDaftar' => $masuk->json('TokenDaftar'), 'NoHp' => NOMOR_PEMBELI, 'Nama' => 'Sinta Maharani',
    ])->assertUnprocessable()->assertJsonValidationErrors('SetujuDataPribadi');

    // Token daftar terikat nomor yang diverifikasi.
    $this->postJson($k['AlamatToko'].'/akun/daftar', [
        'TokenDaftar' => $masuk->json('TokenDaftar'), 'NoHp' => '0813-0000-1111', 'Nama' => 'Orang Lain', 'SetujuDataPribadi' => true,
    ])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'TokenDaftarTidakBerlaku');

    $daftar = $this->postJson($k['AlamatToko'].'/akun/daftar', [
        'TokenDaftar' => $masuk->json('TokenDaftar'), 'NoHp' => NOMOR_PEMBELI, 'Nama' => 'Sinta Maharani',
        'Email' => 'sinta@contoh.id', 'TanggalLahir' => '1995-04-17', 'SetujuDataPribadi' => true, 'SetujuPemasaran' => true,
    ])->assertCreated()->assertJsonPath('Pelanggan.Nama', 'Sinta Maharani')->assertJsonPath('Pelanggan.NoHp', '0812-7777-1234');
    AmbilTokenCookie($daftar);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Pelanggan::query()->sole();
    expect($p->NoHp)->toBe('6281277771234')
        ->and($p->NoHpTerverifikasiPada)->not->toBeNull()
        ->and($p->Tag)->toBe(['Toko online'])
        ->and($p->SetujuPemasaran)->toBeTrue()
        ->and($p->TanggalLahir?->toDateString())->toBe('1995-04-17')
        ->and(SesiPelangganOnline::query()->where('IdPelanggan', $p->Id)->count())->toBe(1);
    $audit = LogAudit::query()->where('Peristiwa', 'pelanggan.daftar-online')->sole();
    expect(json_encode($audit->NilaiBaru))->not->toContain('81277771234');

    // Token daftar sekali pakai.
    $this->postJson($k['AlamatToko'].'/akun/daftar', [
        'TokenDaftar' => $masuk->json('TokenDaftar'), 'NoHp' => NOMOR_PEMBELI, 'Nama' => 'Sinta', 'SetujuDataPribadi' => true,
    ])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'TokenDaftarTidakBerlaku');
});

it('pelanggan kasir yang sudah ada langsung masuk tanpa daftar ulang. Datanya tidak ditimpa. Nomornya jadi terverifikasi', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $lama = Pelanggan::query()->create(['Nama' => 'Bu Ratna Kasir', 'NoHp' => '6281277771234', 'Alamat' => 'Jl. Lama 1']);

    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => '+62 812 7777 1234'])->assertStatus(202);
    $masuk = $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => '081277771234', 'Kode' => AmbilKodeTerkirim()])
        ->assertOk()->assertJsonPath('PerluDaftar', false)->assertJsonPath('Pelanggan.Nama', 'Bu Ratna Kasir')->assertJsonPath('Pelanggan.Uuid', $lama->Uuid);
    $token = AmbilTokenCookie($masuk);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Pelanggan::query()->count())->toBe(1)->and($lama->refresh()->NoHpTerverifikasiPada)->not->toBeNull()->and($lama->Alamat)->toBe('Jl. Lama 1');

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($k['AlamatToko'])
        ->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.Pelanggan.Nama', 'Bu Ratna Kasir'));
});

it('pelanggan yang diarsipkan toko tidak bisa masuk, dan sesinya yang lama tidak berlaku lagi', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    Pelanggan::query()->sole()->forceFill(['Status' => StatusPelanggan::Diarsipkan])->save();

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($k['AlamatToko'])
        ->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.Pelanggan', null));

    $this->travel(2)->minutes();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202);
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => AmbilKodeTerkirim()])
        ->assertForbidden()->assertJsonPath('Galat.Kode', 'AkunTidakAktif');
});

it('batas: kirim ulang ditahan 60 detik, 5 kode per nomor per jam, kode lama gugur oleh kode baru, 5 kali salah menghanguskan', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202);
    $pertama = AmbilKodeTerkirim();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(429)->assertJsonPath('Galat.Kode', 'TungguSebentar');

    $this->travel(61)->seconds();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202);
    $kedua = AmbilKodeTerkirim();

    // Kode pertama sudah gugur: memasukkannya dihitung sebagai percobaan salah atas kode terbaru.
    $sisaAwal = 4;
    if ($pertama !== $kedua) {
        $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $pertama])
            ->assertUnprocessable()->assertJsonPath('Galat.Pesan', 'Kode salah. Sisa 4 kali percobaan.');
        $sisaAwal = 3;
    }

    $salah = in_array('000000', [$pertama, $kedua], true) ? '111111' : '000000';
    foreach (range($sisaAwal, 1) as $sisa) {
        $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $salah])->assertJsonPath('Galat.Pesan', "Kode salah. Sisa {$sisa} kali percobaan.");
    }
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $salah])->assertJsonPath('Galat.Pesan', 'Kode salah terlalu sering. Minta kode baru.');
    // Bahkan kode yang benar sudah gugur setelah 5 kali salah.
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $kedua])->assertJsonPath('Galat.Kode', 'KodeTidakBerlaku');

    foreach ([1, 2, 3] as $_) {
        $this->travel(61)->seconds();
        $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202);
    }
    $this->travel(61)->seconds();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakKode');
});

it('kode yang gagal terkirim tidak sah dan pembeli mendapat pesan yang jelas', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    config(['integrasi.Whatsapp' => ['Penyedia' => 'MetaCloud', 'Pengaturan' => ['IdNomorTelepon' => '1099', 'VersiApi' => 'v21.0', 'BahasaTemplat' => 'id'], 'Kredensial' => ['TokenAkses' => 'token-meta-uji']]]);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Re-engagement message']], 400)]);

    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(503)->assertJsonPath('Galat.Kode', 'KodeGagalTerkirim');
    /** @var PermintaanHttp $kirim */
    $kirim = collect(Http::recorded())->last()[0];
    preg_match('/^(\d{6})/', (string) $kirim['text']['body'], $cocok);
    $kode = $cocok[1] ?? '';
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_PEMBELI, 'Kode' => $kode])->assertJsonPath('Galat.Kode', 'KodeTidakBerlaku');
});

it('WhatsApp Cloud API resmi: kode dikirim lewat templat autentikasi dengan tombol salin kode', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    config(['integrasi.Whatsapp' => ['Penyedia' => 'MetaCloud', 'Pengaturan' => ['IdNomorTelepon' => '1099', 'VersiApi' => 'v21.0', 'BahasaTemplat' => 'id', 'NamaTemplatKodeMasuk' => 'kode_masuk'], 'Kredensial' => ['TokenAkses' => 'token-meta-uji']]]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_PEMBELI])->assertStatus(202);

    Http::assertSent(function (PermintaanHttp $p): bool {
        $komponen = $p['template']['components'] ?? [];
        $kode = $komponen[0]['parameters'][0]['text'] ?? null;

        return $p['template']['name'] === 'kode_masuk' && preg_match('/^\d{6}$/', (string) $kode) === 1
            && $komponen[1]['type'] === 'button' && $komponen[1]['sub_type'] === 'url' && $komponen[1]['parameters'][0]['text'] === $kode;
    });
});

it('pesanan pembeli yang masuk tertaut ke Pelanggan dengan nomor terverifikasi. Tamu bernomor sama tidak tertaut', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);

    // Tamu dulu (tanpa cookie): klien uji menyimpan cookie untuk permintaan berikutnya.
    $tamu = BantuanTokoOnline::Kiriman($k);
    $tamu['NoHp'] = NOMOR_PEMBELI;
    $this->postJson($k['AlamatToko'].'/pesan', $tamu)->assertCreated();

    $kiriman = BantuanTokoOnline::Kiriman($k);
    $kiriman['NoHp'] = '0899-1111-2222';
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/pesan', $kiriman)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Pelanggan::query()->sole();
    $milik = PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole();
    expect($milik->IdPelanggan)->toBe($p->Id)
        ->and($milik->NoHp)->toBe('6281277771234', 'Nomor ketikan diabaikan, yang dipakai nomor terverifikasi.')
        ->and(PesananOnline::query()->where('Uuid', $tamu['Uuid'])->sole()->IdPelanggan)->toBeNull();
});

it('harga tier pelanggan berlaku di keranjang & checkout pembeli yang masuk, tidak untuk tamu', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold', 'MinimalBelanja' => '0']);
    Pelanggan::query()->sole()->forceFill(['IdTier' => $gold->Id])->save();
    $satuan = ProdukSatuan::query()->where('IdProduk', $k['Kopi']->Id)->where('DefaultJual', true)->firstOrFail();
    $daftar = BantuanHarga::BuatDaftarHarga('Harga member Gold', ['TierPelanggan' => 'GOLD', 'Prioritas' => 10, 'Aktif' => true]);
    BantuanHarga::TambahHargaDaftar($daftar, $satuan, '1.0000', '20000.00');

    $kiriman = BantuanTokoOnline::Kiriman($k);
    // Tamu: 2 × (Rp 25.000 + boba Rp 5.000).
    $this->postJson($k['AlamatToko'].'/keranjang/hitung', $kiriman)->assertOk()->assertJsonPath('Subtotal', '60000.00');
    // Member Gold: 2 × (Rp 20.000 + Rp 5.000).
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/keranjang/hitung', $kiriman)->assertOk()
        ->assertJsonPath('Subtotal', '50000.00')->assertJsonPath('Total', '50000.00');
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/pesan', $kiriman)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan = PesananOnline::query()->with('Detail')->where('Uuid', $kiriman['Uuid'])->sole();
    expect($pesanan->Total)->toBe('50000.00')->and($pesanan->Detail->first()?->HargaSatuan)->toBe('20000.00');
});

it('halaman Akun saya: profil, riwayat pesanan online. Ubah profil. Tanggal lahir hanya diisi sekali. Keluar mencabut sesi', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);
    // Tanpa cookie dulu: klien uji menyimpan cookie `withCookie` untuk permintaan sesudahnya.
    $this->get($k['AlamatToko'].'/akun')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/AkunTokoOnline')->where('AkunAktif', true)->where('Toko.UrlLogo', null)->where('Pelanggan', null)->where('Riwayat', null));
    $this->putJson($k['AlamatToko'].'/akun/profil', ['Nama' => 'Penyusup'])->assertUnauthorized()->assertJsonPath('Galat.Kode', 'BelumMasuk');

    $kiriman = BantuanTokoOnline::Kiriman($k, 'Kirim');
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/pesan', $kiriman)->assertCreated();
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($k['AlamatToko'].'/akun')->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pelanggan.Nama', 'Sinta Maharani')->where('Pelanggan.NoHp', '0812-7777-1234')
            ->has('Riwayat.Pesanan', 1)->where('Riwayat.Pesanan.0.JenisPemenuhan', 'Dikirim')->has('Riwayat.Belanja', 0));
    // Alamat kirim terakhir mengisi checkout berikutnya.
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($k['AlamatToko'])
        ->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.AlamatTerakhir.Alamat', 'Jl. Merdeka 10')->where('Akun.AlamatTerakhir.KodePos', '40123'));

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->putJson($k['AlamatToko'].'/akun/profil', [
        'Nama' => 'Sinta M.', 'Email' => 'sinta@contoh.id', 'TanggalLahir' => '1995-04-17', 'SetujuPemasaran' => true,
    ])->assertOk()->assertJsonPath('Pelanggan.Nama', 'Sinta M.')->assertJsonPath('Pelanggan.TanggalLahir', '1995-04-17');
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->putJson($k['AlamatToko'].'/akun/profil', [
        'Nama' => 'Sinta M.', 'TanggalLahir' => '2000-01-01',
    ])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'TanggalLahirTerkunci');

    $keluar = $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/akun/keluar')->assertOk();
    expect($keluar->getCookie(SesiPembeliOnline::NAMA_COOKIE, false)?->isCleared())->toBeTrue();
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($k['AlamatToko'].'/akun')
        ->assertInertia(fn (AssertableInertia $h) => $h->where('Pelanggan', null));
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(LogAudit::query()->where('Peristiwa', 'pelanggan.ubah-online')->count())->toBe(1);
});

it('keluar dari semua perangkat mencabut setiap sesi pelanggan itu', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $hp = MasukSebagaiPembeli($this, $k);
    $this->travel(61)->seconds();
    $laptop = MasukSebagaiPembeli($this, $k);

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $hp)->postJson($k['AlamatToko'].'/akun/keluar', ['Semua' => true])->assertOk();

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $laptop)->get($k['AlamatToko'].'/akun')
        ->assertInertia(fn (AssertableInertia $h) => $h->where('Pelanggan', null));
});

it('isolasi tenant: sesi pembeli toko A tidak berlaku di toko B walau cookie-nya dikirim', function (): void {
    $a = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $a);
    $b = BantuanTokoOnline::Siapkan($this);

    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->get($b['AlamatToko'].'/akun')
        ->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Pelanggan', null));
    $kiriman = BantuanTokoOnline::Kiriman($b);
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($b['AlamatToko'].'/pesan', $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole()->IdPelanggan)->toBeNull();
});

it('back-office: sakelar akun pembeli tersimpan & mematikan tombol masuk. Pesanan menampilkan pelanggan tertautnya', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);
    $kiriman = BantuanTokoOnline::Kiriman($k);
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/pesan', $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Pelanggan::query()->sole();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola/toko-online')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('AkunPembeliTersedia', true)->where('Pengaturan.AkunPelangganAktif', true)
        ->where('Pesanan.0.Pelanggan.Uuid', $p->Uuid)->where('Pesanan.0.Pelanggan.Nama', 'Sinta Maharani'));

    $this->put('/kelola/toko-online/pengaturan', [
        'Outlet' => $k['Outlet']->Uuid, 'Aktif' => true, 'TokoOnlineAktif' => true,
        'AmbilSendiriAktif' => true, 'KirimAktif' => true, 'BayarSaatAmbilAktif' => true, 'CodAktif' => true,
        'QrisAktif' => false, 'AkunPelangganAktif' => false, 'MinimalPesanan' => '10000.00', 'MenitKedaluwarsa' => 120, 'PesanTutup' => null,
    ])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PengaturanTokoOnline::query()->sole()->AkunPelangganAktif)->toBeFalse();
    // Sesi lama tidak lagi dipakai: harga & pesanan kembali sebagai tamu.
    $this->get($k['AlamatToko'])->assertInertia(fn (AssertableInertia $h) => $h->where('Akun.Aktif', false)->where('Akun.Pelanggan', null));
});

it('POS: pesanan pembeli yang masuk membawa Pelanggan (bentuk hasil cari POS, HP tersamar) supaya kasir memasangnya. Tamu null', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $token = MasukSebagaiPembeli($this, $k);
    $this->postJson($k['AlamatToko'].'/pesan', $tamu = BantuanTokoOnline::Kiriman($k))->assertCreated();
    $this->withCredentials()->withCookie(SesiPembeliOnline::NAMA_COOKIE, $token)->postJson($k['AlamatToko'].'/pesan', $milik = BantuanTokoOnline::Kiriman($k))->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PesananOnline::query()->update(['Status' => StatusPesananOnline::Siap->value]);
    $p = Pelanggan::query()->sole();

    $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-online')->assertOk()->assertJsonStructure(['TanggalBisnis']);
    $daftar = collect($respons->json('Pesanan'))->keyBy('Uuid');

    expect($daftar[$tamu['Uuid']]['Pelanggan'])->toBeNull()
        ->and($daftar[$milik['Uuid']]['Pelanggan'])->toMatchArray([
            'Uuid' => $p->Uuid, 'Nama' => 'Sinta Maharani', 'NoHp' => '0812****1234', 'KodeTier' => null, 'JumlahTransaksi' => 0,
        ]);
});
