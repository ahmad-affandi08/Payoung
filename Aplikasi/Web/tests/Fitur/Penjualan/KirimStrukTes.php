<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Enum\JenisPesanKeluar;
use App\Domain\Penjualan\Enum\KanalPesanKeluar;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesanKeluar;
use App\Domain\Penjualan\Surel\StrukBelanjaDigital;
use App\Domain\Penjualan\Tugas\KirimStrukDigitalTugas;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * K3: kirim struk digital (`/s/{kodeStruk}`) ke WhatsApp pelanggan dari POS (email ditutup D-33). Tabel `PesanKeluar`, tugas antrean
 * `KirimStrukDigitalTugas`, kontrak `POST /api/pos/v1/penjualan/{uuidPenjualan}/kirim-struk` &
 * `GET /api/pos/v1/pesan-keluar/{uuid}`.
 */

const NOMOR_PELANGGAN = '0812-3456-7890';
const NOMOR_RAPI = '6281234567890';
const TOKEN_FONNTE = 'rahasia-fonnte-9f8e7d';
const TOKEN_META = 'EAAGrahasiaMeta123';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Http::preventStrayRequests();
});

function AktifkanWhatsappStruk(Tenant $tenant): void
{
    OverrideTenant::query()->create([
        'IdTenant' => $tenant->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji kirim struk digital lewat WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($tenant->Id);
}

function PasangWhatsapp(string $penyedia = 'Fonnte', ?string $templat = null): void
{
    config(['integrasi.Whatsapp' => $penyedia === 'MetaCloud'
        ? ['Penyedia' => 'MetaCloud', 'Pengaturan' => ['IdNomorTelepon' => '1099', 'VersiApi' => 'v21.0', 'BahasaTemplat' => 'id', 'NamaTemplatStruk' => $templat ?? ''], 'Kredensial' => ['TokenAkses' => TOKEN_META]]
        : ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => TOKEN_FONNTE]]]);
}

/**
 * @return array{0: array<string, mixed>, 1: Penjualan}
 */
function SiapkanJualStruk(TestCase $tes, string $namaUsaha = 'Toko Kelontong Berkah Solo', bool $whatsapp = true): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $p = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);

    if ($whatsapp) {
        AktifkanWhatsappStruk($k['Tenant']);
    }

    return [$k, $p];
}

function KirimStruk(TestCase $tes, array $k, string $uuidPenjualan, string $kanal = 'Whatsapp', string $tujuan = NOMOR_PELANGGAN, ?string $uuid = null)
{
    return $tes->withToken($k['Token'])->postJson("/api/pos/v1/penjualan/{$uuidPenjualan}/kirim-struk", [
        'Uuid' => $uuid ?? (string) Str::ulid(),
        'Kanal' => $kanal,
        'Tujuan' => $tujuan,
    ]);
}

function JalankanTugasStruk(PesanKeluar $pesan, int $percobaan = 1): KirimStrukDigitalTugas
{
    $tugas = (new KirimStrukDigitalTugas($pesan->IdTenant, $pesan->Id))->withFakeQueueInteractions();
    $tugas->job->attempts = $percobaan;
    app()->call([$tugas, 'handle']);

    return $tugas;
}

/**
 * Kiriman struk lewat email yang sudah diantrekan sebelum D-33 (PRD v4.05); sejak itu jalur email ditutup untuk kiriman
 * baru, tetapi baris lama di antrean tetap diproses tugas yang sama.
 *
 * @param  array<string, mixed>  $k
 */
function BuatPesanEmailLama(array $k, Penjualan $p, string $email): PesanKeluar
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return PesanKeluar::query()->create([
        'Uuid' => (string) Str::ulid(),
        'IdOutlet' => $k['Outlet']->Id,
        'IdPerangkat' => $k['Perangkat']->Id,
        'Kanal' => KanalPesanKeluar::Email,
        'Jenis' => JenisPesanKeluar::StrukDigital,
        'IdReferensi' => $p->Id,
        'Tujuan' => $email,
        'Status' => StatusPesanKeluar::Diantrekan,
        'Percobaan' => 0,
    ]);
}

describe('K3 kirim struk digital dari POS', function (): void {
    it('mengantrekan kiriman WhatsApp: 202 + baris PesanKeluar (tujuan terenkripsi, dirapikan 62…); Uuid sama = 200 tanpa antre ulang', function (): void {
        Queue::fake();
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);
        $uuid = (string) Str::ulid();

        KirimStruk($this, $k, $p->Uuid, uuid: $uuid)->assertStatus(202)->assertExactJson(['Uuid' => $uuid, 'Status' => 'Diantrekan']);
        $pesan = PesanKeluar::query()->where('Uuid', $uuid)->firstOrFail();
        expect($pesan->IdReferensi)->toBe($p->Id)
            ->and($pesan->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($pesan->IdOutlet)->toBe($k['Outlet']->Id)
            ->and($pesan->Tujuan)->toBe(NOMOR_RAPI)
            ->and($pesan->Status)->toBe(StatusPesanKeluar::Diantrekan)
            ->and(DB::table('PesanKeluar')->where('Id', $pesan->Id)->value('Tujuan'))->not->toContain('812345');
        Queue::assertPushed(KirimStrukDigitalTugas::class, fn (KirimStrukDigitalTugas $t) => $t->idPesanKeluar === $pesan->Id
            && $t->idTenant === $k['Tenant']->Id && $t->afterCommit === true && ! str_contains(serialize($t), '812345'));

        // Idempoten: Uuid sama (walau isi berbeda) mengembalikan status terkini tanpa baris & tugas baru.
        KirimStruk($this, $k, $p->Uuid, 'Email', 'lain@contoh.id', $uuid)->assertOk()->assertExactJson(['Uuid' => $uuid, 'Status' => 'Diantrekan']);
        expect(PesanKeluar::query()->count())->toBe(1);
        Queue::assertPushedTimes(KirimStrukDigitalTugas::class, 1);

        $this->withToken($k['Token'])->getJson("/api/pos/v1/pesan-keluar/{$uuid}")
            ->assertOk()->assertExactJson(['Uuid' => $uuid, 'Status' => 'Diantrekan', 'PesanGalat' => null]);
    });

    it('tugas: Fonnte (tidak resmi) mengirim teks struk; status Terkirim + IdPesanPenyedia', function (): void {
        Queue::fake();
        PasangWhatsapp();
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['80123']])]);
        [$k, $p] = SiapkanJualStruk($this);
        KirimStruk($this, $k, $p->Uuid)->assertStatus(202);
        $pesan = PesanKeluar::query()->firstOrFail();

        JalankanTugasStruk($pesan)->assertNotReleased();

        $url = url('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid));
        Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'fonnte') && $r['target'] === NOMOR_RAPI
            && $r['message'] === "Terima kasih telah berbelanja di Toko Kelontong Berkah Solo.\nTotal: Rp 77.000\nStruk digital: {$url}");
        $pesan->refresh();
        expect($pesan->Status)->toBe(StatusPesanKeluar::Terkirim)
            ->and($pesan->IdPesanPenyedia)->toBe('80123')
            ->and($pesan->Penyedia)->toBe('Fonnte')
            ->and($pesan->Percobaan)->toBe(1)
            ->and($pesan->TerkirimPada)->not->toBeNull();
        $this->withToken($k['Token'])->getJson("/api/pos/v1/pesan-keluar/{$pesan->Uuid}")
            ->assertOk()->assertExactJson(['Uuid' => $pesan->Uuid, 'Status' => 'Terkirim', 'PesanGalat' => null]);
    });

    it('tugas: WhatsApp Cloud API resmi memakai templat [toko, total, URL] bila nama templat diisi; tanpa templat = teks', function (): void {
        Queue::fake();
        PasangWhatsapp('MetaCloud', 'struk_digital');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBg1']]])]);
        [$k, $p] = SiapkanJualStruk($this);
        $url = url('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid));

        KirimStruk($this, $k, $p->Uuid)->assertStatus(202);
        JalankanTugasStruk(PesanKeluar::query()->latest('Id')->firstOrFail());
        Http::assertSent(fn (PermintaanHttp $r) => $r['type'] === 'template' && $r['to'] === NOMOR_RAPI
            && $r['template']['name'] === 'struk_digital'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['Toko Kelontong Berkah Solo', 'Rp 77.000', $url]);
        expect(PesanKeluar::query()->latest('Id')->firstOrFail()->IdPesanPenyedia)->toBe('wamid.HBg1');

        PasangWhatsapp('MetaCloud');
        KirimStruk($this, $k, $p->Uuid)->assertStatus(202);
        JalankanTugasStruk(PesanKeluar::query()->latest('Id')->firstOrFail());
        Http::assertSent(fn (PermintaanHttp $r) => $r['type'] === 'text' && str_contains($r['text']['body'], "Struk digital: {$url}"));
        expect(PesanKeluar::query()->where('Status', StatusPesanKeluar::Terkirim->value)->count())->toBe(2);
    });

    it('tugas email (kiriman lama yang sudah antre sebelum D-33): Mailable berisi ringkasan & tautan struk, pengirim bernama usaha', function (): void {
        Queue::fake();
        Mail::fake();
        config(['mail.from.address' => 'struk@payoung.id']);
        [$k, $p] = SiapkanJualStruk($this, whatsapp: false);

        $pesan = BuatPesanEmailLama($k, $p, 'bu.ratna@contoh.co.id');
        JalankanTugasStruk($pesan);

        $url = url('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid));
        Mail::assertSent(StrukBelanjaDigital::class, function (StrukBelanjaDigital $surel) use ($url, $p): bool {
            // Sejak D-26 email dikirim dua bagian, dan `render()` mengembalikan badan **HTML**. Asersi lama di sini
            // mencari format baris badan teks ("Nama x 2: Rp 77.000") di hasil `render()`, jadi tidak pernah cocok.
            // Sekarang kedua badan diperiksa pada formatnya masing-masing.
            $html = $surel->render();
            $teks = (string) view($surel->textView, $surel->buildViewData())->render();

            return $surel->hasTo('bu.ratna@contoh.co.id') && $surel->hasFrom('struk@payoung.id', 'Toko Kelontong Berkah Solo')
                && $surel->nomor === $p->Nomor && $surel->total === 'Rp 77.000'
                && str_contains($html, $url) && str_contains($teks, $url)
                && str_contains($html, 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter')
                && str_contains($html, 'Rp 77.000')
                && str_contains($teks, 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter x 2: Rp 77.000')
                // D-74: satu-satunya gambar adalah logo merek milik sendiri; tidak ada piksel pelacak atau gambar lain.
                && preg_match_all('/<img\b[^>]*>/i', $html, $gambar) === 1 && str_contains($gambar[0][0], 'surel/logo-payoung.png')
                && ! str_contains($teks, '<img');
        });
        expect($pesan->refresh()->Status)->toBe(StatusPesanKeluar::Terkirim);
    });

    it('D-33: kirim struk lewat email selalu 409 EmailBelumAktif (juga bila integrasi email platform aktif); tidak ada baris PesanKeluar', function (): void {
        Queue::fake();
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);
        config(['integrasi.EmailAktif' => true]);

        KirimStruk($this, $k, $p->Uuid, 'Email', 'pelanggan@contoh.id')->assertStatus(409)
            ->assertJsonPath('Galat.Kode', 'EmailBelumAktif')
            ->assertJsonPath('Galat.Pesan', 'Kirim struk lewat email tidak tersedia. Kirim lewat WhatsApp atau tunjukkan QR struk digital.');
        expect(PesanKeluar::query()->count())->toBe(0);
        Queue::assertNotPushed(KirimStrukDigitalTugas::class);
    });

    it('penjualan belum tersinkron = 404 PenjualanBelumTersinkron', function (): void {
        PasangWhatsapp();
        [$k] = SiapkanJualStruk($this);

        KirimStruk($this, $k, (string) Str::ulid())->assertNotFound()->assertJsonPath('Galat.Kode', 'PenjualanBelumTersinkron');
        expect(PesanKeluar::query()->count())->toBe(0);
    });

    it('WhatsApp tanpa penyedia aktif atau tanpa fitur integrasi.whatsapp = 409 WhatsappBelumAktif', function (): void {
        Queue::fake();
        [$k, $p] = SiapkanJualStruk($this, whatsapp: false);
        // Paket Starter tidak memuat fitur integrasi.whatsapp (X11 mulai Pro).
        Langganan::query()->where('IdTenant', $k['Tenant']->Id)->sole()->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->sole()->Id]);
        expect(app(PemeriksaFiturTenant::class)->CekAktif($k['Tenant']->Id, PemeriksaFiturTenant::KUNCI_WHATSAPP))->toBeFalse();

        // Fitur tidak dimiliki paket tenant.
        PasangWhatsapp();
        KirimStruk($this, $k, $p->Uuid)->assertStatus(409)->assertJsonPath('Galat.Kode', 'WhatsappBelumAktif');

        // Fitur dimiliki, tetapi penyedia WhatsApp P-05 tidak aktif.
        AktifkanWhatsappStruk($k['Tenant']);
        config(['integrasi.Whatsapp' => null]);
        KirimStruk($this, $k, $p->Uuid)->assertStatus(409)->assertJsonPath('Galat.Kode', 'WhatsappBelumAktif');
        expect(PesanKeluar::query()->count())->toBe(0);
        Queue::assertNotPushed(KirimStrukDigitalTugas::class);
    });

    it('struk digital dimatikan tenant = 409 StrukDigitalNonaktif', function (): void {
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->put('/kelola/kasir/struk', [
            'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
            'TampilkanKasir' => true, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true, 'TampilkanStrukDigital' => false,
            'NamaDicetak' => null, 'TeksKepala' => [], 'CatatanKaki' => null, 'TeksPenutup' => null,
        ])->assertRedirect('/kelola/kasir/struk');

        KirimStruk($this, $k, $p->Uuid)->assertStatus(409)->assertJsonPath('Galat.Kode', 'StrukDigitalNonaktif');
    });

    it('nomor/email tidak sah = 422 TujuanTidakValid; isian kosong/kanal asing = 422 ValidasiGagal', function (string $kanal, string $tujuan): void {
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);

        KirimStruk($this, $k, $p->Uuid, $kanal, $tujuan)->assertStatus(422)->assertJsonPath('Galat.Kode', 'TujuanTidakValid');
        expect(PesanKeluar::query()->count())->toBe(0);
    })->with([
        'telepon rumah' => ['Whatsapp', '0271-123456'],
        'terlalu pendek' => ['Whatsapp', '0812345'],
        'nomor luar negeri' => ['Whatsapp', '+65 8123 4567'],
        'huruf' => ['Whatsapp', 'bukan nomor'],
        'email tanpa domain' => ['Email', 'ratna@'],
        'email dengan spasi' => ['Email', 'bu ratna@contoh.id'],
    ]);

    it('isian tidak lengkap = 422 ValidasiGagal', function (): void {
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);

        $this->withToken($k['Token'])->postJson("/api/pos/v1/penjualan/{$p->Uuid}/kirim-struk", ['Uuid' => 'bukan-ulid', 'Kanal' => 'Sms', 'Tujuan' => ''])
            ->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
    });

    it('paling banyak 5 kiriman per penjualan = 429 BatasKirimStrukTercapai', function (): void {
        Queue::fake();
        PasangWhatsapp();
        [$k, $p] = SiapkanJualStruk($this);

        foreach (range(1, 5) as $_) {
            KirimStruk($this, $k, $p->Uuid)->assertStatus(202);
        }

        KirimStruk($this, $k, $p->Uuid)->assertStatus(429)->assertJsonPath('Galat.Kode', 'BatasKirimStrukTercapai');
        expect(PesanKeluar::query()->count())->toBe(5);
    });

    it('isolasi tenant: perangkat tenant B tidak bisa mengirim struk penjualan A atau membaca kiriman A', function (): void {
        Queue::fake();
        PasangWhatsapp();
        [$a, $p] = SiapkanJualStruk($this, 'Kopi Senja Solo');
        $uuid = (string) Str::ulid();
        KirimStruk($this, $a, $p->Uuid, uuid: $uuid)->assertStatus(202);
        [$b] = SiapkanJualStruk($this, 'Warung Bakso Pak Kumis');

        KirimStruk($this, $b, $p->Uuid)->assertNotFound()->assertJsonPath('Galat.Kode', 'PenjualanBelumTersinkron');
        $this->withToken($b['Token'])->getJson("/api/pos/v1/pesan-keluar/{$uuid}")->assertNotFound();
        // Uuid kiriman A dipakai ulang perangkat B: tidak membuka kiriman A (unik per tenant), tetap ditolak 404.
        KirimStruk($this, $b, $p->Uuid, uuid: $uuid)->assertNotFound();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(PesanKeluar::query()->count())->toBe(1);
    });

    it('penyedia menolak: dicoba ulang lalu Gagal; PesanGalat & log tanpa nomor pelanggan dan tanpa token', function (): void {
        Queue::fake();
        Log::spy();
        PasangWhatsapp();
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'target '.NOMOR_RAPI.' invalid, token '.TOKEN_FONNTE])]);
        [$k, $p] = SiapkanJualStruk($this);
        KirimStruk($this, $k, $p->Uuid)->assertStatus(202);
        $pesan = PesanKeluar::query()->firstOrFail();

        JalankanTugasStruk($pesan)->assertReleased(30);
        expect($pesan->refresh()->Status)->toBe(StatusPesanKeluar::Diantrekan)->and($pesan->Percobaan)->toBe(1);
        JalankanTugasStruk($pesan, 2)->assertReleased(120);
        JalankanTugasStruk($pesan, 3)->assertNotReleased();

        $pesan->refresh();
        expect($pesan->Status)->toBe(StatusPesanKeluar::Gagal)
            ->and($pesan->Percobaan)->toBe(3)
            ->and($pesan->PesanGalat)->toContain('invalid')
            ->and($pesan->PesanGalat)->not->toContain('81234567890')
            ->and($pesan->PesanGalat)->not->toContain(TOKEN_FONNTE);
        $this->withToken($k['Token'])->getJson("/api/pos/v1/pesan-keluar/{$pesan->Uuid}")
            ->assertOk()->assertJsonPath('Status', 'Gagal')->assertJsonPath('PesanGalat', $pesan->PesanGalat);

        Log::shouldHaveReceived('warning')->with('Struk digital gagal dikirim.', Mockery::on(
            fn (array $konteks): bool => ! str_contains(json_encode($konteks, JSON_THROW_ON_ERROR), '81234567890')
                && ! str_contains(json_encode($konteks, JSON_THROW_ON_ERROR), TOKEN_FONNTE)
                && $konteks['IdPesanKeluar'] === $pesan->Id,
        ))->times(3);
    });

    it('galat email (kiriman lama sebelum D-33): pesan difilter dari alamat pelanggan; kegagalan sistem (failed) menandai Gagal', function (): void {
        Queue::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('550 mailbox pelanggan@contoh.id unavailable'));
        [$k, $p] = SiapkanJualStruk($this, whatsapp: false);
        $pesan = BuatPesanEmailLama($k, $p, 'pelanggan@contoh.id');

        JalankanTugasStruk($pesan, 3);
        expect($pesan->refresh()->Status)->toBe(StatusPesanKeluar::Gagal)
            ->and($pesan->PesanGalat)->toContain('550 mailbox')
            ->and($pesan->PesanGalat)->not->toContain('pelanggan@contoh.id');

        $kedua = BuatPesanEmailLama($k, $p, 'pelanggan@contoh.id');
        (new KirimStrukDigitalTugas($kedua->IdTenant, $kedua->Id))->failed(new RuntimeException('Deadlock'));
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($kedua->refresh()->Status)->toBe(StatusPesanKeluar::Gagal)
            ->and($kedua->PesanGalat)->toBe('Struk gagal dikirim karena galat sistem.');
    });
});
