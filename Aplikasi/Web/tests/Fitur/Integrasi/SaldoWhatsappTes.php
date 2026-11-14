<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Enum\JenisMutasiSaldoWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Integrasi\Model\KodeAlokasiKredit;
use App\Domain\Integrasi\Model\KreditBroadcastOutlet;
use App\Domain\Integrasi\Model\MutasiSaldoWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use App\Domain\Integrasi\Whatsapp\Saldo\BukuSaldoWhatsapp;
use App\Domain\Pelanggan\Enum\StatusPenerimaKampanye;
use App\Domain\Pelanggan\Model\KampanyePesan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PenerimaKampanye;
use App\Domain\Pengelola\Tagihan\Aksi\UbahPengaturanSaldoWhatsapp;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PengaturanSaldoWhatsapp;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * D-300 saldo WhatsApp: isi saldo lewat gerbang billing (saldo bertambah hanya dari status gerbang, sekali), alokasi
 * kredit per outlet dengan OTP lewat WhatsApp, pemakaian per pesan broadcast (potong, lewati bila kurang, kembalikan bila
 * gagal), pengaturan harga dari konsol, isolasi tenant, dan idempotensi buku.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    Mail::fake();
    Cache::flush();
    config([
        'integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji', 'TokenAkun' => 'akun-uji']],
        'integrasi.GerbangBilling' => [
            'Penyedia' => 'DokuBilling',
            'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => BantuanTagihan::ID_KLIEN_DOKU],
            'Kredensial' => ['KunciRahasia' => BantuanTagihan::KUNCI_RAHASIA_DOKU],
        ],
    ]);
    // Fonnte didaftarkan lebih dulu: stub pertama yang cocok yang menang, dan FakeDoku menjawab 404 untuk URL lain.
    JawabanFonnteUji(true);
    Http::fake(['api.fonnte.com/send' => fn () => JawabanFonnteUji()]);
    BantuanTagihan::FakeDoku();
});

/** Jawaban Fonnte yang bisa dibalik di tengah tes: true = berhasil, false = penyedia menolak. */
function JawabanFonnteUji(?bool $berhasil = null): mixed
{
    static $berhasilSekarang = true;

    if ($berhasil !== null) {
        $berhasilSekarang = $berhasil;

        return null;
    }

    return Http::response($berhasilSekarang ? ['status' => true, 'id' => ['5001']] : ['status' => false, 'reason' => 'device disconnect']);
}

/**
 * Toko dengan fitur WhatsApp aktif dan pemilik yang masuk, nomor HP pemilik terisi untuk OTP.
 *
 * @return array<string, mixed>
 */
function TokoSaldoWa(TestCase $tes, string $nama = 'Kopi Senja Saldo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    OverrideTenant::query()->create([
        'IdTenant' => $k['Tenant']->Id, 'Jenis' => JenisOverride::Fitur, 'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90), 'Alasan' => 'Uji saldo WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $k['Pemilik']->update(['NoHp' => '081234567890']);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);

    return $k;
}

function AturHargaBroadcastUji(string $harga, string $minimal = '10000'): void
{
    foreach ([PengaturanSaldoWhatsapp::KUNCI_HARGA => $harga, PengaturanSaldoWhatsapp::KUNCI_MINIMAL => $minimal] as $kunci => $nilai) {
        PengaturanPlatform::query()->updateOrCreate(['Kunci' => $kunci], ['Nilai' => $nilai]);
    }
}

describe('D-300 isi saldo lewat gerbang billing', function (): void {
    it('Owner mengisi saldo: diarahkan ke halaman bayar, saldo bertambah hanya dari status gerbang dan hanya sekali', function (): void {
        $k = TokoSaldoWa($this);

        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '50000'])->assertRedirect(BantuanTagihan::URL_BAYAR_DOKU);
        $topUp = TopUpSaldoWhatsapp::query()->sole();
        expect($topUp->Status)->toBe(StatusTopUpSaldoWhatsapp::Menunggu)
            ->and($topUp->Jumlah)->toBe('50000.00')
            ->and(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('0.00')
            ->and(LogAudit::query()->where('Peristiwa', 'saldo-whatsapp.top-up-mulai')->count())->toBe(1);

        // Pemilik kembali dari halaman bayar: status ditanyakan ke gerbang dan saldo langsung bertambah.
        BantuanTagihan::AturJawabanStatusDoku(BantuanTagihan::JawabanStatusDoku((string) $topUp->RefGateway, 'SUCCESS', '50000'));
        $this->get('/kelola/pelanggan/whatsapp')->assertOk()->assertInertia(fn ($h) => $h
            ->where('Saldo.SaldoUtama', '50000.00')
            ->where('Saldo.TopUp.0.Status', 'Lunas'));

        // Dibuka berulang atau notifikasi menyusul: tidak menambah lagi.
        $this->get('/kelola/pelanggan/whatsapp')->assertOk();
        $this->get('/kelola/pelanggan/whatsapp')->assertOk();
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('50000.00')
            ->and(MutasiSaldoWhatsapp::query()->where('Jenis', JenisMutasiSaldoWhatsapp::TopUp->value)->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'saldo-whatsapp.top-up-lunas')->count())->toBe(1);
        unset($k);
    });

    it('notifikasi webhook DOKU bertanda tangan melunasi top-up sekali, pengulangan tidak menambah saldo', function (): void {
        $k = TokoSaldoWa($this);
        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '50000']);
        $topUp = TopUpSaldoWhatsapp::query()->sole();
        $nomor = (string) $topUp->RefGateway;
        BantuanTagihan::AturJawabanStatusDoku(BantuanTagihan::JawabanStatusDoku($nomor, 'SUCCESS', '50000'));

        $kirim = function () use ($nomor): void {
            $badan = (string) json_encode([
                'order' => ['invoice_number' => $nomor, 'amount' => 50000],
                'transaction' => ['status' => 'SUCCESS', 'original_request_id' => 'req-doku-topup-1'],
            ], JSON_UNESCAPED_SLASHES);
            $waktu = '2026-10-07T05:05:00Z';
            $this->call('POST', '/webhook/billing/doku', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_CLIENT_ID' => BantuanTagihan::ID_KLIEN_DOKU, 'HTTP_REQUEST_ID' => 'req-uji-topup', 'HTTP_REQUEST_TIMESTAMP' => $waktu,
                'HTTP_SIGNATURE' => ProtokolDoku::Tandatangani(BantuanTagihan::ID_KLIEN_DOKU, 'req-uji-topup', $waktu, '/webhook/billing/doku', $badan, BantuanTagihan::KUNCI_RAHASIA_DOKU),
            ], $badan)->assertOk();
        };

        $kirim();
        $kirim();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($topUp->refresh()->Status)->toBe(StatusTopUpSaldoWhatsapp::Lunas)
            ->and(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('50000.00')
            ->and(MutasiSaldoWhatsapp::query()->where('Jenis', JenisMutasiSaldoWhatsapp::TopUp->value)->count())->toBe(1);
    });

    it('jumlah di bawah minimal konsol atau di atas batas ditolak dan tidak membuat top-up', function (): void {
        TokoSaldoWa($this);
        AturHargaBroadcastUji('150', '25000');

        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '20000'])->assertSessionHasErrors();
        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '99999999'])->assertSessionHasErrors();
        expect(TopUpSaldoWhatsapp::query()->count())->toBe(0);

        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '25000'])->assertRedirect(BantuanTagihan::URL_BAYAR_DOKU);
    });

    it('jumlah yang dibayar berbeda dari jumlah top-up tidak dikreditkan', function (): void {
        TokoSaldoWa($this);
        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '50000']);
        $topUp = TopUpSaldoWhatsapp::query()->sole();

        BantuanTagihan::AturJawabanStatusDoku(BantuanTagihan::JawabanStatusDoku((string) $topUp->RefGateway, 'SUCCESS', '10000'));
        $this->get('/kelola/pelanggan/whatsapp')->assertOk();

        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('0.00')
            ->and($topUp->refresh()->Status)->toBe(StatusTopUpSaldoWhatsapp::Menunggu);
    });

    it('perintah rekonsiliasi menanyakan top-up yang menunggu dan menutup yang lewat 24 jam', function (): void {
        $k = TokoSaldoWa($this);
        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '50000']);
        $topUp = TopUpSaldoWhatsapp::query()->sole();
        BantuanTagihan::AturJawabanStatusDoku(BantuanTagihan::JawabanStatusDoku((string) $topUp->RefGateway, 'SUCCESS', '50000'));

        expect(Artisan::call('whatsapp:rekonsiliasi-topup'))->toBe(0);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($topUp->refresh()->Status)->toBe(StatusTopUpSaldoWhatsapp::Lunas)
            ->and(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('50000.00');

        // Top-up yang tak pernah dibayar ditutup setelah 24 jam.
        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '20000']);
        $kedua = TopUpSaldoWhatsapp::query()->where('Status', StatusTopUpSaldoWhatsapp::Menunggu->value)->sole();
        BantuanTagihan::AturJawabanStatusDoku(BantuanTagihan::JawabanStatusDoku((string) $kedua->RefGateway, 'PENDING', '20000'));
        $this->travelTo(now()->addHours(25));
        Cache::flush();

        Artisan::call('whatsapp:rekonsiliasi-topup');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($kedua->refresh()->Status)->toBe(StatusTopUpSaldoWhatsapp::Kedaluwarsa);
    });
});

describe('D-300 alokasi kredit per outlet dengan OTP', function (): void {
    /** @return array{0: array<string, mixed>, 1: string} toko dengan saldo utama Rp 100.000 dan kode OTP yang terkirim */
    function SiapkanAlokasi(TestCase $tes, string $jumlah = '30000'): array
    {
        $k = TokoSaldoWa($tes);
        app(BukuSaldoWhatsapp::class)->TopUp(Uang::Dari('100000'), 'TU-AWAL', $k['Pemilik']->Id);
        $tes->postJson('/kelola/pelanggan/whatsapp/saldo/alokasi/kode', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => $jumlah])
            ->assertOk()->assertJson(['Tujuan' => '••••7890']);

        $pesan = collect(Http::recorded())->map(fn (array $p): PermintaanHttp => $p[0])
            ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send') && str_contains((string) $r['message'], 'Kode alokasi'))->last();
        preg_match('/: (\d{6})\./', (string) $pesan['message'], $cocok);

        return [$k, $cocok[1]];
    }

    it('kode benar mengalokasikan kredit ke outlet, saldo utama berkurang, lalu kode tidak bisa dipakai ulang', function (): void {
        [$k, $kode] = SiapkanAlokasi($this);

        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '30000', 'Kode' => $kode])->assertSessionHasNoErrors();

        $buku = app(BukuSaldoWhatsapp::class);
        expect($buku->AmbilSaldo()->KeString())->toBe('70000.00')
            ->and($buku->AmbilKreditOutlet($k['Outlet']->Id)?->KeString())->toBe('30000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'saldo-whatsapp.alokasi')->count())->toBe(1);

        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '30000', 'Kode' => $kode])->assertSessionHasErrors('Kode');
        expect($buku->AmbilSaldo()->KeString())->toBe('70000.00');
    });

    it('kode salah tiga kali mematikan kode, percobaan salah tersimpan walau ditolak', function (): void {
        [$k, $kode] = SiapkanAlokasi($this);
        $salah = $kode === '123456' ? '654321' : '123456';
        $isian = ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '30000'];

        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Kode' => $salah])->assertSessionHasErrors('Kode');
        expect(KodeAlokasiKredit::query()->sole()->Percobaan)->toBe(1);
        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Kode' => $salah])->assertSessionHasErrors('Kode');
        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Kode' => $salah])->assertSessionHasErrors('Kode');

        // Kode yang benar pun sudah mati.
        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Kode' => $kode])->assertSessionHasErrors('Kode');
        expect(app(BukuSaldoWhatsapp::class)->AmbilKreditOutlet($k['Outlet']->Id))->toBeNull()
            ->and(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('100000.00');
    });

    it('kode terikat jumlah dan outlet yang diminta, kedaluwarsa setelah 10 menit, dan permintaan kedua dalam semenit ditolak', function (): void {
        [$k, $kode] = SiapkanAlokasi($this);
        $isian = ['UuidOutlet' => $k['Outlet']->Uuid, 'Kode' => $kode];

        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Jumlah' => '90000'])->assertSessionHasErrors('Kode');
        $this->postJson('/kelola/pelanggan/whatsapp/saldo/alokasi/kode', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '30000'])->assertStatus(429);

        $this->travelTo(now()->addMinutes(11));
        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', $isian + ['Jumlah' => '30000'])->assertSessionHasErrors('Kode');
        expect(app(BukuSaldoWhatsapp::class)->AmbilKreditOutlet($k['Outlet']->Id))->toBeNull();
    });

    it('alokasi di atas saldo utama ditolak sebelum kode dikirim, dan kode tidak mencetak uang', function (): void {
        $k = TokoSaldoWa($this);
        app(BukuSaldoWhatsapp::class)->TopUp(Uang::Dari('10000'), 'TU-KECIL', $k['Pemilik']->Id);

        $this->postJson('/kelola/pelanggan/whatsapp/saldo/alokasi/kode', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '50000'])->assertStatus(422);
        expect(KodeAlokasiKredit::query()->count())->toBe(0);
    });

    it('menarik kredit outlet mengembalikan sisanya ke saldo utama tanpa OTP', function (): void {
        [$k, $kode] = SiapkanAlokasi($this);
        $this->post('/kelola/pelanggan/whatsapp/saldo/alokasi', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '30000', 'Kode' => $kode]);

        $this->post('/kelola/pelanggan/whatsapp/saldo/tarik', ['UuidOutlet' => $k['Outlet']->Uuid])->assertSessionHasNoErrors();

        $buku = app(BukuSaldoWhatsapp::class);
        expect($buku->AmbilSaldo()->KeString())->toBe('100000.00')
            ->and($buku->AmbilKreditOutlet($k['Outlet']->Id))->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'saldo-whatsapp.tarik')->count())->toBe(1);
    });

    it('kasir tanpa izin integrasi tidak bisa mengisi, mengalokasikan, atau menarik saldo', function (): void {
        $k = TokoSaldoWa($this);
        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);

        $this->post('/kelola/pelanggan/whatsapp/saldo/top-up', ['Jumlah' => '50000'])->assertForbidden();
        $this->postJson('/kelola/pelanggan/whatsapp/saldo/alokasi/kode', ['UuidOutlet' => $k['Outlet']->Uuid, 'Jumlah' => '1000'])->assertForbidden();
        $this->post('/kelola/pelanggan/whatsapp/saldo/tarik', ['UuidOutlet' => $k['Outlet']->Uuid])->assertForbidden();
    });
});

describe('D-300 buku saldo', function (): void {
    it('pemakaian per pesan idempoten, menolak saldo minus, dan pengembalian hanya sekali', function (): void {
        $k = TokoSaldoWa($this);
        $buku = app(BukuSaldoWhatsapp::class);
        $buku->TopUp(Uang::Dari('1000'), 'TU-1', $k['Pemilik']->Id);
        $buku->TopUp(Uang::Dari('1000'), 'TU-1', $k['Pemilik']->Id);
        expect($buku->AmbilSaldo()->KeString())->toBe('1000.00');

        $pakai = $buku->Pakai(null, Uang::Dari('150'), 'K1-1', 'Broadcast uji');
        $buku->Pakai(null, Uang::Dari('150'), 'K1-1', 'Broadcast uji');
        expect($pakai)->not->toBeNull()->and($buku->AmbilSaldo()->KeString())->toBe('850.00');

        expect($buku->Pakai(null, Uang::Dari('900'), 'K1-2'))->toBeNull()
            ->and($buku->AmbilSaldo()->KeString())->toBe('850.00');

        $buku->Kembalikan($pakai, 'R1-1');
        $buku->Kembalikan($pakai, 'R1-1');
        expect($buku->AmbilSaldo()->KeString())->toBe('1000.00');
    });

    it('isolasi tenant: saldo dan riwayat satu usaha tidak terlihat dan tidak terpakai oleh usaha lain', function (): void {
        $a = TokoSaldoWa($this, 'Toko A Saldo');
        app(BukuSaldoWhatsapp::class)->TopUp(Uang::Dari('75000'), 'TU-A', $a['Pemilik']->Id);

        $b = BantuanPenjualan::Siapkan($this, 'Toko B Saldo');
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);

        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('0.00')
            ->and(MutasiSaldoWhatsapp::query()->count())->toBe(0)
            ->and(app(BukuSaldoWhatsapp::class)->Pakai(null, Uang::Dari('1000'), 'K-B'))->toBeNull();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('75000.00');
    });
});

describe('D-300 broadcast memotong saldo', function (): void {
    /** @return array<string, mixed> toko dengan dua pelanggan setuju promosi dan kampanye siap dijalankan */
    function SiapkanBroadcastBerbayar(TestCase $tes, string $saldo): array
    {
        $k = TokoSaldoWa($tes);
        AturHargaBroadcastUji('150');

        foreach ([['Ani Lestari', '6281200000001'], ['Budi Santoso', '6281200000002']] as [$nama, $hp]) {
            Pelanggan::query()->create(['Nama' => $nama, 'NoHp' => $hp, 'SetujuPemasaran' => true]);
        }

        if ($saldo !== '0') {
            app(BukuSaldoWhatsapp::class)->TopUp(Uang::Dari($saldo), 'TU-BC', $k['Pemilik']->Id);
        }

        $tes->post('/kelola/pelanggan/kampanye', ['Nama' => 'Promo saldo', 'Kanal' => 'Whatsapp', 'Isi' => 'Halo {nama}, ada promo di {toko}.', 'Segmen' => []])->assertSessionHasNoErrors();

        return $k;
    }

    function JalankanKampanyeUji(TestCase $tes): void
    {
        $kampanye = KampanyePesan::query()->latest('Id')->firstOrFail();
        $tes->post("/kelola/pelanggan/kampanye/{$kampanye->Uuid}/jalankan")->assertSessionHasNoErrors();
    }

    it('saldo cukup: tiap pesan terkirim memotong harga per pesan dan tercatat di buku', function (): void {
        $k = SiapkanBroadcastBerbayar($this, '1000');
        JalankanKampanyeUji($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('700.00')
            ->and(MutasiSaldoWhatsapp::query()->where('Jenis', JenisMutasiSaldoWhatsapp::Pemakaian->value)->count())->toBe(2)
            ->and(PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Terkirim->value)->count())->toBe(2);
    });

    it('saldo kurang: penerima dilewati dengan alasan jelas, tidak ada pesan terkirim, saldo tidak minus', function (): void {
        $k = SiapkanBroadcastBerbayar($this, '200');
        JalankanKampanyeUji($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $dilewati = PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Dilewati->value)->get();
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('50.00')
            ->and(PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Terkirim->value)->count())->toBe(1)
            ->and($dilewati)->toHaveCount(1)
            ->and($dilewati->first()->PesanGalat)->toContain('Saldo WhatsApp tidak cukup');
    });

    it('pesan gagal dikirim: potongan dikembalikan, saldo utuh', function (): void {
        $k = SiapkanBroadcastBerbayar($this, '1000');
        JawabanFonnteUji(false);
        JalankanKampanyeUji($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('1000.00')
            ->and(MutasiSaldoWhatsapp::query()->where('Jenis', JenisMutasiSaldoWhatsapp::Pengembalian->value)->count())->toBe(
                MutasiSaldoWhatsapp::query()->where('Jenis', JenisMutasiSaldoWhatsapp::Pemakaian->value)->count(),
            )
            ->and(PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Terkirim->value)->count())->toBe(0);
    });

    it('harga 0 (belum diatur konsol): broadcast gratis, saldo tidak tersentuh', function (): void {
        $k = SiapkanBroadcastBerbayar($this, '1000');
        AturHargaBroadcastUji('0');
        JalankanKampanyeUji($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(BukuSaldoWhatsapp::class)->AmbilSaldo()->KeString())->toBe('1000.00')
            ->and(PenerimaKampanye::query()->where('Status', StatusPenerimaKampanye::Terkirim->value)->count())->toBe(2);
    });

    it('outlet dengan kredit sendiri memakai kredit itu, bukan saldo utama', function (): void {
        $k = SiapkanBroadcastBerbayar($this, '1000');
        $buku = app(BukuSaldoWhatsapp::class);
        $buku->SiapkanKolamOutlet($k['Outlet']->Id);
        $buku->Alokasikan($k['Outlet']->Id, Uang::Dari('300'), 'AL-UJI', $k['Pemilik']->Id);
        $nomor = NomorPengirimWhatsapp::query()->create([
            'IdTenant' => $k['Tenant']->Id, 'IdOutlet' => $k['Outlet']->Id, 'Label' => 'Broadcast', 'NomorTelepon' => '6281200000099', 'Fungsi' => 'Broadcast',
            'Penyedia' => 'Fonnte', 'TokenPerangkat' => 'perangkat-bc', 'Status' => StatusNomorWhatsapp::Terhubung, 'Aktif' => true,
        ]);
        expect($nomor->Id)->toBeGreaterThan(0);

        JalankanKampanyeUji($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($buku->AmbilSaldo()->KeString())->toBe('700.00')
            ->and($buku->AmbilKreditOutlet($k['Outlet']->Id)?->KeString())->toBe('0.00')
            ->and(KreditBroadcastOutlet::query()->count())->toBe(1);
    });
});

describe('D-300 pengaturan harga dari konsol', function (): void {
    function MasukKonsolUji(TestCase $tes, PeranPengelolaBawaan $peran): TestCase
    {
        $tes->flushSession();

        return $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
    }

    it('bawaan harga 0 dan minimal Rp 10.000, Super Admin mengubahnya dan teraudit dengan nilai lama', function (): void {
        $pengaturan = app(PengaturanSaldoWhatsapp::class);
        expect($pengaturan->HargaPerPesan()->KeString())->toBe('0.00')
            ->and($pengaturan->TopUpMinimal()->KeString())->toBe('10000.00')
            ->and($pengaturan->BroadcastBerbayar())->toBeFalse();

        MasukKonsolUji($this, PeranPengelolaBawaan::SuperAdmin)
            ->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '175', 'TopUpMinimal' => '25000', 'Alasan' => 'Menutup biaya penyedia'])
            ->assertSessionHasNoErrors();

        expect($pengaturan->HargaPerPesan()->KeString())->toBe('175.00')
            ->and($pengaturan->TopUpMinimal()->KeString())->toBe('25000.00')
            ->and($pengaturan->BroadcastBerbayar())->toBeTrue();
        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.saldo-whatsapp.ubah')->sole();
        expect($log->NilaiLama)->toEqualCanonicalizing(['HargaPerPesan' => '0.00', 'TopUpMinimal' => '10000.00'])
            ->and($log->NilaiBaru)->toEqualCanonicalizing(['HargaPerPesan' => '175.00', 'TopUpMinimal' => '25000.00']);
    });

    it('Keuangan tidak boleh mengubah, nilai tidak sah ditolak, alasan wajib', function (): void {
        MasukKonsolUji($this, PeranPengelolaBawaan::Keuangan)
            ->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '100', 'TopUpMinimal' => '10000', 'Alasan' => 'Coba ubah sendiri'])
            ->assertForbidden();

        $masuk = fn () => MasukKonsolUji($this, PeranPengelolaBawaan::SuperAdmin);
        $masuk()->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '-5', 'TopUpMinimal' => '10000', 'Alasan' => 'Nilai negatif'])->assertSessionHasErrors('HargaPerPesan');
        $masuk()->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '200000', 'TopUpMinimal' => '10000', 'Alasan' => 'Terlalu mahal sekali'])->assertSessionHasErrors('HargaPerPesan');
        $masuk()->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '100', 'TopUpMinimal' => '0', 'Alasan' => 'Minimal nol tidak masuk akal'])->assertSessionHasErrors('TopUpMinimal');
        $masuk()->put(BantuanPengelola::Url('/tagihan/pengaturan/saldo-whatsapp'), ['HargaPerPesan' => '100', 'TopUpMinimal' => '10000', 'Alasan' => ''])->assertSessionHasErrors('Alasan');

        expect(PengaturanPlatform::query()->where('Kunci', PengaturanSaldoWhatsapp::KUNCI_HARGA)->exists())->toBeFalse()
            ->and(class_exists(UbahPengaturanSaldoWhatsapp::class))->toBeTrue();
    });

    it('halaman Tagihan konsol membawa pengaturan saldo WhatsApp', function (): void {
        MasukKonsolUji($this, PeranPengelolaBawaan::SuperAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn ($h) => $h
                ->where('PengaturanPenagihan.SaldoWhatsapp.HargaPerPesan', '0.00')
                ->where('PengaturanPenagihan.SaldoWhatsapp.TopUpMinimal', '10000.00'));
    });
});
