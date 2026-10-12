<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\RekonsiliasiPembayaranGerbangLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * P-08 langkah 3 | BR-P08.11 (PRD v4.06): pembayaran langganan lewat gerbang yang notifikasi webhook-nya tidak pernah
 * tiba direkonsiliasi tiap 15 menit lewat API status DOKU, dan hasilnya diproses jalur yang sama dengan webhook.
 */

/** @param  array<string, mixed>|null  $jawaban  null = koneksi ke DOKU gagal */
function AturStatusGerbangRekonsiliasi(?array $jawaban, int $kodeHttp = 200): void
{
    BantuanTagihan::AturJawabanStatusDoku($jawaban, $kodeHttp);
}

function SiapkanGerbangRekonsiliasi(): void
{
    config()->set('integrasi.GerbangBilling', [
        'Penyedia' => 'DokuBilling',
        'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => BantuanTagihan::ID_KLIEN_DOKU],
        'Kredensial' => ['KunciRahasia' => BantuanTagihan::KUNCI_RAHASIA_DOKU],
    ]);
    BantuanTagihan::FakeDoku();
}

/** @return array{Tenant: Tenant, Tagihan: TagihanLangganan, Pembayaran: PembayaranLangganan} */
function SiapkanPembayaranTersangkut(TestCase $tes): array
{
    /** @var array{Tenant: Tenant, Pengguna: Pengguna} $data */
    $data = BantuanTagihan::DaftarTenant();
    BantuanTagihan::Masuk($tes, $data['Pengguna'], $data['Tenant'])
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
        ->assertSessionHasNoErrors();
    $tes->flushSession();
    $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $data['Tenant']->Id)->latest('Id')->firstOrFail();

    BantuanTagihan::Masuk($tes, $data['Pengguna'], $data['Tenant'])
        ->postJson(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bayar-online"))
        ->assertOk();
    $tes->flushSession();

    $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->latest('Id')->firstOrFail();

    return ['Tenant' => $data['Tenant'], 'Tagihan' => $tagihan, 'Pembayaran' => $pembayaran];
}

/** @return array<string, mixed> */
function JawabanStatusDoku(PembayaranLangganan $pembayaran, string $status, string $jumlah): array
{
    return BantuanTagihan::JawabanStatusDoku((string) $pembayaran->RefGateway, $status, $jumlah);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanTagihan::SiapkanPrasyarat();
    SiapkanGerbangRekonsiliasi();
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('BR-P08.11 rekonsiliasi pembayaran gerbang tersangkut', function (): void {
    it('SUCCESS yang webhook-nya hilang melunasi tagihan lewat jalur webhook, dan putaran ulang tidak memperpanjang dua kali', function (): void {
        ['Tenant' => $tenant, 'Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', $tagihan->Total));
        Carbon::setTestNow('2026-09-27 03:16:00');

        $this->artisan('tagihan:rekonsiliasi-gerbang')
            ->expectsOutput('1 pembayaran diperiksa, 1 selesai, 0 masih menunggu, 0 gagal diperiksa.')
            ->assertSuccessful();

        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif);
        $selesai = $langganan->PeriodeSelesai?->toDateTimeString();

        // Pembayaran sudah tidak Menunggu: tidak ditanyakan lagi dan periode tidak bergeser.
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Diperiksa'])->toBe(0)
            ->and(Langganan::query()->where('IdTenant', $tenant->Id)->sole()->PeriodeSelesai?->toDateTimeString())->toBe($selesai);
    });

    it('pembayaran yang belum 15 menit tidak ditanyakan (webhook diberi kesempatan dulu)', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', $tagihan->Total));
        Carbon::setTestNow('2026-09-27 03:10:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Diperiksa'])->toBe(0)
            ->and(BantuanTagihan::JumlahPanggilanStatusDoku())->toBe(0)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);
    });

    it('transaksi tidak dikenal DOKU (termasuk transaksi lama milik penyedia sebelumnya): masih menunggu sebelum masa bayar habis, ditolak sesudahnya sehingga tagihan bisa dibayar ulang', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(['error' => ['message' => 'Invoice not found']], 404);

        Carbon::setTestNow('2026-09-27 03:30:00');
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan())
            ->toBe(['Diperiksa' => 1, 'Selesai' => 0, 'Menunggu' => 1, 'Gagal' => 0])
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        Carbon::setTestNow('2026-09-27 04:20:00');
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Selesai'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('status PENDING di gerbang dibiarkan menunggu', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'PENDING', $tagihan->Total));
        Carbon::setTestNow('2026-09-27 03:20:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Menunggu'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);
    });

    it('gerbang tidak terjangkau atau jawaban untuk nomor pesanan lain: tidak mengubah apa pun, dicoba lagi nanti', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        Carbon::setTestNow('2026-09-27 03:20:00');

        AturStatusGerbangRekonsiliasi(null);
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Gagal'])->toBe(1);

        AturStatusGerbangRekonsiliasi([...JawabanStatusDoku($pembayaran, 'SUCCESS', $tagihan->Total), 'order' => ['invoice_number' => 'lain-01', 'amount' => 222000]]);
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Gagal'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('jumlah dibayar berbeda tetap menunggu verifikasi manual dan berhenti ditanyakan setelah 7 hari', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', '1000.00'));

        Carbon::setTestNow('2026-09-27 03:20:00');
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Gagal'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        Carbon::setTestNow('2026-10-04 03:01:00');
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Diperiksa'])->toBe(0);
    });

    it('putaran harian pembayaran lama: yang tidak dibayar menurut gerbang ditutup supaya tagihan bebas, yang dibayar tapi jumlahnya beda tetap menunggu verifikasi manual', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        Carbon::setTestNow('2026-10-20 03:10:00');
        $rekonsiliasi = app(RekonsiliasiPembayaranGerbangLangganan::class);

        // Lewat jendela 15 menit: putaran biasa tidak lagi menanyakannya.
        expect($rekonsiliasi->Jalankan()['Diperiksa'])->toBe(0);

        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', '1000.00'));
        expect($rekonsiliasi->JalankanArsip())->toBe(['Diperiksa' => 1, 'Selesai' => 0, 'Menunggu' => 1, 'Gagal' => 0])
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        AturStatusGerbangRekonsiliasi(null);
        expect($rekonsiliasi->JalankanArsip()['Gagal'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'EXPIRED', $tagihan->Total));
        $this->artisan('tagihan:rekonsiliasi-gerbang --arsip')
            ->expectsOutput('1 pembayaran diperiksa, 1 selesai, 0 masih menunggu, 0 gagal diperiksa.')
            ->assertSuccessful();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('pembayaran lebih tua dari 90 hari tidak ditanyakan lagi', function (): void {
        ['Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        Carbon::setTestNow('2027-01-20 03:10:00');
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'EXPIRED', '1000.00'));

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->JalankanArsip()['Diperiksa'])->toBe(0);
    });

    it('FAILED & EXPIRED di gerbang menolak pembayaran sehingga tagihan bisa dibayar ulang', function (string $status): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, $status, $tagihan->Total));
        Carbon::setTestNow('2026-09-27 03:20:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Selesai'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    })->with(['FAILED', 'EXPIRED']);

    it('jawaban status SUCCESS tanpa jumlah memakai jumlah transaksi yang dibuat Payoung (jumlah di DOKU tetap)', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(['order' => ['invoice_number' => $pembayaran->RefGateway], 'transaction' => ['status' => 'SUCCESS']]);
        Carbon::setTestNow('2026-09-27 03:20:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Selesai'])->toBe(1)
            ->and($pembayaran->refresh()->JumlahDiterima)->toBe($tagihan->Total)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);
    });

    it('permintaan status ke DOKU bertanda tangan dan memakai Client-Id akun platform', function (): void {
        ['Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'PENDING', '1000.00'));
        Carbon::setTestNow('2026-09-27 03:20:00');

        app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan();

        Http::assertSent(function ($permintaan) use ($pembayaran): bool {
            $target = '/orders/v1/status/'.$pembayaran->RefGateway;
            // GET tanpa badan: komponen tanda tangan tidak memuat Digest.
            $komponen = 'Client-Id:'.BantuanTagihan::ID_KLIEN_DOKU
                ."\nRequest-Id:".$permintaan->header('Request-Id')[0]
                ."\nRequest-Timestamp:".$permintaan->header('Request-Timestamp')[0]
                ."\nRequest-Target:{$target}";

            return $permintaan->method() === 'GET'
                && $permintaan->url() === BantuanTagihan::ALAMAT_DOKU.$target
                && $permintaan->header('Signature') === ['HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, BantuanTagihan::KUNCI_RAHASIA_DOKU, true))];
        });
    });

    it('v5.34: membuka halaman tagihan sesudah bayar langsung melunasi lewat API status DOKU tanpa menunggu webhook, dan dibatasi satu pertanyaan per jeda', function (): void {
        ['Tenant' => $tenant, 'Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = SiapkanPembayaranTersangkut($this);
        $pengguna = Pengguna::query()->withoutGlobalScopes()->where('Email', '!=', '')->latest('Id')->firstOrFail();
        Cache::flush();

        // Masih PENDING: halaman terbuka, tagihan belum lunas.
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'PENDING', $tagihan->Total));
        BantuanTagihan::Masuk($this, $pengguna, $tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))->assertOk();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        // Muat ulang segera: masih dalam jeda, DOKU tidak ditanya lagi.
        $panggilan = BantuanTagihan::JumlahPanggilanStatusDoku();
        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', $tagihan->Total));
        BantuanTagihan::Masuk($this, $pengguna, $tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))->assertOk();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        // Lewat jeda dan DOKU sudah SUCCESS: lunas pada pemuatan halaman itu juga.
        Cache::flush();
        BantuanTagihan::Masuk($this, $pengguna, $tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))->assertOk();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($panggilan)->toBeInt();
    });

    it('D-98: naik paket lunas lewat DOKU mengganti paket langganan tanpa menggeser periode; putaran ulang tidak mengubah apa pun', function (): void {
        /** @var array{Tenant: Tenant, Pengguna: Pengguna} $data */
        $data = BantuanTagihan::DaftarTenant();
        $langganan = Langganan::query()->where('IdTenant', $data['Tenant']->Id)->sole();
        $periodeSelesai = now()->addDays(10)->startOfSecond();
        $langganan->update(['Status' => StatusLangganan::Aktif, 'SiklusTagihan' => 'Bulanan', 'PeriodeMulai' => now()->subDays(20), 'PeriodeSelesai' => $periodeSelesai]);

        BantuanTagihan::Masuk($this, $data['Pengguna'], $data['Tenant'])
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'BISNIS', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        $this->flushSession();
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $data['Tenant']->Id)->latest('Id')->firstOrFail();
        expect($tagihan->Jenis->value)->toBe('NaikPaket');

        BantuanTagihan::Masuk($this, $data['Pengguna'], $data['Tenant'])
            ->postJson(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bayar-online"))
            ->assertOk();
        $this->flushSession();
        $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->latest('Id')->firstOrFail();

        AturStatusGerbangRekonsiliasi(JawabanStatusDoku($pembayaran, 'SUCCESS', $tagihan->Total));
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('tagihan:rekonsiliasi-gerbang')->assertSuccessful();

        $sesudah = Langganan::query()->where('IdTenant', $data['Tenant']->Id)->sole();
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($sesudah->Paket->Kode)->toBe('BISNIS')
            ->and($sesudah->Status)->toBe(StatusLangganan::Aktif)
            ->and($sesudah->SiklusTagihan->value)->toBe('Bulanan')
            ->and($sesudah->PeriodeSelesai?->toDateTimeString())->toBe($periodeSelesai->toDateTimeString())
            ->and($tagihan->PeriodeSelesai?->toDateTimeString())->toBe($periodeSelesai->toDateTimeString())
            ->and($tagihan->MulaiLanggananPaket?->toDateString())->toBe(now()->toDateString());

        // Putaran ulang: pembayaran sudah selesai, tidak ditanyakan lagi dan paket tidak berubah.
        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Diperiksa'])->toBe(0)
            ->and(Langganan::query()->where('IdTenant', $data['Tenant']->Id)->sole()->Paket->Kode)->toBe('BISNIS');
    });

    it('gerbang billing dinonaktifkan: tidak ada yang ditanyakan', function (): void {
        SiapkanPembayaranTersangkut($this);
        config()->set('integrasi.GerbangBilling', null);
        Carbon::setTestNow('2026-09-27 03:20:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Diperiksa'])->toBe(0);
    });
});
