<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLangganan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * BR-P08.11 | P-08 langkah 3 jalur gerbang: tenant membayar tagihan langganan lewat DOKU Checkout (akun platform),
 * dan tagihan menjadi Lunas **hanya** dari notifikasi webhook bertanda tangan yang statusnya terkonfirmasi di API status.
 *
 * Yang dijaga berkas ini: pelunasannya memakai layanan yang sama dengan verifikasi transfer manual
 * (`PelunasTagihanLangganan`), notifikasinya idempoten, dan notifikasi palsu tidak pernah melunasi apa pun.
 */

const URL_WEBHOOK_BILLING = '/webhook/billing/doku';

function AturGerbangBilling(): void
{
    config()->set('integrasi.GerbangBilling', [
        'Penyedia' => 'DokuBilling',
        'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => BantuanTagihan::ID_KLIEN_DOKU],
        'Kredensial' => ['KunciRahasia' => BantuanTagihan::KUNCI_RAHASIA_DOKU],
    ]);
    BantuanTagihan::FakeDoku();
}

/** Tagihan terbuka untuk tenant ini (paket & siklus dari pilihan Owner, BR-P08.4). */
function BuatTagihanGerbangUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, string $paket = 'PRO', string $siklus = 'Bulanan'): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => $paket, 'Siklus' => $siklus])
        ->assertSessionHasNoErrors();
    $tes->flushSession();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

/** Owner menekan "Bayar online": transaksi DOKU Checkout dibuat dan baris pembayaran `Gateway` menunggu notifikasi. */
function MulaiBayarOnlineUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, TagihanLangganan $tagihan): TestResponse
{
    $respons = BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->postJson(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bayar-online"));
    $tes->flushSession();

    return $respons;
}

/** Bukti transfer manual untuk tagihan yang sama, dipakai menguji jalur manual & aturan saling-blokir. */
function UnggahBuktiGerbangUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, TagihanLangganan $tagihan): void
{
    BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    $tes->flushSession();
}

/**
 * Notifikasi HTTP DOKU: badan JSON ditandatangani dengan `Request-Target` path webhook. Secara bawaan API status DOKU
 * juga menjawab sama dengan yang diklaim notifikasi (transaksi benar-benar berstatus itu di DOKU).
 *
 * Opsi: `IdKlien` (Client-Id header), `Kunci` (kunci penanda tangan), `Target` (Request-Target yang ditandatangani),
 * `BadanKirim` (badan yang sungguh dikirim, beda dari yang ditandatangani), `TanpaTandaTangan`, `Signature` (nilai
 * mentah), `JawabanStatus` (jawaban API status; `false` = koneksi gagal), `KodeStatus` (HTTP API status).
 *
 * @param  array<string, mixed>  $opsi
 */
function KirimNotifikasiBilling(TestCase $tes, string $nomorPesanan, string $status, string $jumlah, array $opsi = []): TestResponse
{
    $badan = (string) json_encode([
        'order' => ['invoice_number' => $nomorPesanan, 'amount' => (int) $jumlah],
        'transaction' => ['status' => $status, 'original_request_id' => 'req-doku-uji-1'],
    ], JSON_UNESCAPED_SLASHES);

    $jawaban = array_key_exists('JawabanStatus', $opsi)
        ? ($opsi['JawabanStatus'] === false ? null : $opsi['JawabanStatus'])
        : BantuanTagihan::JawabanStatusDoku($nomorPesanan, $status, $jumlah);
    BantuanTagihan::AturJawabanStatusDoku($jawaban, (int) ($opsi['KodeStatus'] ?? 200));

    $idKlien = (string) ($opsi['IdKlien'] ?? BantuanTagihan::ID_KLIEN_DOKU);
    $idPermintaan = 'req-'.Str::uuid();
    $waktu = '2026-09-27T03:05:00Z';
    $tandaTangan = (string) ($opsi['Signature'] ?? ProtokolDoku::Tandatangani(
        $idKlien,
        $idPermintaan,
        $waktu,
        (string) ($opsi['Target'] ?? URL_WEBHOOK_BILLING),
        $badan,
        (string) ($opsi['Kunci'] ?? BantuanTagihan::KUNCI_RAHASIA_DOKU),
    ));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if (! ($opsi['TanpaTandaTangan'] ?? false)) {
        $server += [
            'HTTP_CLIENT_ID' => $idKlien,
            'HTTP_REQUEST_ID' => $idPermintaan,
            'HTTP_REQUEST_TIMESTAMP' => $waktu,
            'HTTP_SIGNATURE' => $tandaTangan,
        ];
    }

    return $tes->call('POST', URL_WEBHOOK_BILLING, [], [], [], $server, (string) ($opsi['BadanKirim'] ?? $badan));
}

function LanggananGerbangUji(Tenant $tenant): Langganan
{
    return Langganan::query()->where('IdTenant', $tenant->Id)->sole();
}

function PembayaranGerbangUji(TagihanLangganan $tagihan): PembayaranLangganan
{
    return PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->latest('Id')->firstOrFail();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanTagihan::SiapkanPrasyarat();
    AturGerbangBilling();
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('BR-P08.11 membuat transaksi di gerbang', function (): void {
    it('Owner menekan Bayar online: transaksi DOKU Checkout dibuat dengan tanda tangan sah dan pembayaran Gateway menunggu notifikasi', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);

        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan)
            ->assertOk()
            ->assertJson(['UrlBayar' => BantuanTagihan::URL_BAYAR_DOKU]);

        $pembayaran = PembayaranGerbangUji($tagihan);
        expect($pembayaran->Metode)->toBe(MetodePembayaranLangganan::Gateway)
            ->and($pembayaran->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($pembayaran->Jumlah)->toBe($tagihan->Total)
            // Nomor pesanan memuat IdTenant + ULID pembayaran, sehingga webhook bisa menetapkan tenant tanpa
            // query lintas tenant dan tagihan tetap terbuka sampai notifikasi datang.
            ->and($pembayaran->RefGateway)->toBe(NomorPesananBilling::Buat($tenant->Id, $pembayaran->Uuid))
            // Batas DOKU untuk invoice_number adalah 64 karakter.
            ->and(strlen((string) $pembayaran->RefGateway))->toBeLessThanOrEqual(64)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        Http::assertSent(function ($permintaan) use ($pembayaran, $tagihan, $tenant, $pemilik): bool {
            if ($permintaan->url() !== BantuanTagihan::ALAMAT_DOKU.'/checkout/v1/payment') {
                return false;
            }

            $badan = $permintaan->body();
            // Tanda tangan dihitung ulang di sini dengan rumus DOKU yang ditulis lepas dari kode produksi.
            $komponen = 'Client-Id:'.BantuanTagihan::ID_KLIEN_DOKU
                ."\nRequest-Id:".$permintaan->header('Request-Id')[0]
                ."\nRequest-Timestamp:".$permintaan->header('Request-Timestamp')[0]
                ."\nRequest-Target:/checkout/v1/payment"
                ."\nDigest:".base64_encode(hash('sha256', $badan, true));
            $harapan = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, BantuanTagihan::KUNCI_RAHASIA_DOKU, true));

            return $permintaan->method() === 'POST'
                && $permintaan->header('Client-Id') === [BantuanTagihan::ID_KLIEN_DOKU]
                && $permintaan->header('Signature') === [$harapan]
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $permintaan->header('Request-Timestamp')[0]) === 1
                && $permintaan->header('Request-Id')[0] !== ''
                && $permintaan['order']['invoice_number'] === $pembayaran->RefGateway
                && $permintaan['order']['amount'] === (int) $tagihan->Total
                && str_ends_with((string) $permintaan['order']['callback_url'], "/kelola/langganan/tagihan/{$tagihan->Uuid}")
                && $permintaan['order']['auto_redirect'] === true
                && $permintaan['payment']['payment_due_date'] === 60
                && $permintaan['customer']['id'] === 'T'.$tenant->Id
                && $permintaan['customer']['email'] === $pemilik->Email;
        });
    });

    it('DOKU menolak permintaan dengan pasti (4xx): pembayaran ditutup Ditolak dan pesan tidak membocorkan secret key', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        BantuanTagihan::PaksaJawabanCheckoutDoku(400, ['message' => ['Invalid '.BantuanTagihan::KUNCI_RAHASIA_DOKU]]);

        $respons = MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan)
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'GerbangMenolak');

        expect($respons->getContent())->not->toContain(BantuanTagihan::KUNCI_RAHASIA_DOKU);
        $pembayaran = PembayaranGerbangUji($tagihan);
        expect($pembayaran->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and((string) $pembayaran->AlasanTolak)->not->toContain(BantuanTagihan::KUNCI_RAHASIA_DOKU);
    });

    it('hasil tidak pasti (5xx atau tautan bayar tak terbaca): pembayaran dibiarkan Menunggu supaya webhook susulan masih cocok', function (int $kode, array $badan): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        BantuanTagihan::PaksaJawabanCheckoutDoku($kode, $badan);

        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan)->assertStatus(422)->assertJsonPath('Galat.Kode', 'GerbangMenolak');

        expect(PembayaranGerbangUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Menunggu);
    })->with([
        '503' => [503, []],
        'sukses tanpa tautan' => [200, ['response' => ['payment' => []]]],
        'tautan bukan https' => [200, ['response' => ['payment' => ['url' => 'http://sandbox.doku.com/checkout']]]],
    ]);

    it('gerbang billing belum dikonfigurasi: pembayaran online ditolak, transfer manual tetap jalan', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        config()->set('integrasi.GerbangBilling', null);

        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan)
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'GerbangBillingTidakAktif');

        expect(PembayaranLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('bukti transfer yang sedang diverifikasi memblokir bayar online (risiko bayar dua kali)', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        UnggahBuktiGerbangUji($this, $pemilik, $tenant, $tagihan);

        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan)
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'PembayaranMasihDiverifikasi');
        Http::assertNothingSent();
    });

    it('halaman tagihan tidak lagi membawa kunci atau skrip gerbang ke peramban', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);

        $respons = BantuanTagihan::Masuk($this, $pemilik, $tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('BolehBayarOnline', true)->missing('Gerbang'));

        expect($respons->getContent())->not->toContain(BantuanTagihan::KUNCI_RAHASIA_DOKU);
    });
});

describe('BR-P08.11 notifikasi webhook', function (): void {
    it('route notifikasi Midtrans lama sudah dihapus', function (): void {
        $this->postJson('/webhook/billing/midtrans', [])->assertNotFound();
    });

    it('SUCCESS melunasi tagihan, mengaktifkan langganan, dan tercatat di audit tanpa pelaku orang', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $pembayaran = PembayaranGerbangUji($tagihan);

        KirimNotifikasiBilling($this, (string) $pembayaran->RefGateway, 'SUCCESS', $tagihan->Total)
            ->assertOk()
            ->assertJson(['Diterima' => true]);

        $langganan = LanggananGerbangUji($tenant);
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($pembayaran->JumlahDiterima)->toBe($tagihan->Total)
            // Pelunasan gerbang tidak punya verifikator manusia (bedanya dengan transfer manual).
            ->and($pembayaran->IdPenggunaPengelolaVerifikator)->toBeNull()
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->PeriodeMulai?->toDateTimeString())->toBe('2026-09-27 03:00:00')
            ->and($langganan->PeriodeSelesai?->toDateTimeString())->toBe('2026-10-27 03:00:00');

        // Pelakunya sistem, jadi audit masuk LogAudit tenant tanpa pengguna — bukan LogAuditPengelola, yang dipakai
        // jalur manual karena di sana ada verifikator yang bertanggung jawab.
        $log = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'langganan.pembayaran-gerbang-lunas')->sole();
        expect($log->IdPengguna)->toBeNull()
            ->and($log->IdTenant)->toBe($tenant->Id)
            ->and($log->NilaiBaru['IdTransaksiGerbang'])->toBe('req-doku-uji-1');
    });

    it('notifikasi diulang berkali-kali tidak memperpanjang periode dua kali (idempoten)', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $nomor = (string) PembayaranGerbangUji($tagihan)->RefGateway;

        KirimNotifikasiBilling($this, $nomor, 'SUCCESS', $tagihan->Total)->assertOk();
        $selesaiPertama = LanggananGerbangUji($tenant)->PeriodeSelesai?->toDateTimeString();

        // DOKU mengulang notifikasi sampai dijawab 200; ulangan harus jadi tanpa efek, bukan perpanjangan baru.
        KirimNotifikasiBilling($this, $nomor, 'SUCCESS', $tagihan->Total)->assertOk()->assertJson(['Diterima' => true]);
        KirimNotifikasiBilling($this, $nomor, 'SUCCESS', $tagihan->Total)->assertOk();

        expect(LanggananGerbangUji($tenant)->PeriodeSelesai?->toDateTimeString())->toBe($selesaiPertama)
            ->and(PembayaranLangganan::query()->withoutGlobalScopes()->where('Status', StatusPembayaranLangganan::Menunggu->value)->count())->toBe(0)
            ->and(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'langganan.pembayaran-gerbang-lunas')->count())->toBe(1);
    });

    it('notifikasi tidak sah dijawab 401 dan tidak menyentuh tagihan', function (string $kasus, array $opsi): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $nomor = (string) PembayaranGerbangUji($tagihan)->RefGateway;

        if ($kasus === 'badan diubah') {
            // Penyerang mengganti nomor pesanan/jumlah setelah DOKU menandatangani badan aslinya.
            $opsi['BadanKirim'] = (string) json_encode(['order' => ['invoice_number' => $nomor, 'amount' => 1], 'transaction' => ['status' => 'SUCCESS']]);
        }

        KirimNotifikasiBilling($this, $nomor, 'SUCCESS', $tagihan->Total, $opsi)
            ->assertStatus(401)
            ->assertJsonPath('Galat.Kode', 'TandaTanganTidakSah');

        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and(PembayaranGerbangUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and(LanggananGerbangUji($tenant)->Status)->not->toBe(StatusLangganan::Aktif)
            // Ditolak sebelum menyentuh DOKU: endpoint publik ini tidak boleh dipakai memancing panggilan keluar.
            ->and(BantuanTagihan::JumlahPanggilanStatusDoku())->toBe(0);
    })->with([
        'signature palsu' => ['signature palsu', ['Signature' => 'HMACSHA256=tanda-tangan-palsu']],
        'secret key lain' => ['secret key lain', ['Kunci' => 'secret-milik-pihak-lain']],
        'Client-Id akun lain' => ['Client-Id akun lain', ['IdKlien' => 'BRN-9999-0000000000000']],
        'badan diubah' => ['badan diubah', []],
        'Request-Target lain' => ['Request-Target lain', ['Target' => '/webhook/doku/token-lain']],
        'tanpa header tanda tangan' => ['tanpa header tanda tangan', ['TanpaTandaTangan' => true]],
    ]);

    it('gerbang billing belum dikonfigurasi: semua notifikasi ditolak 401', function (): void {
        ['Tenant' => $tenant] = BantuanTagihan::DaftarTenant();
        config()->set('integrasi.GerbangBilling', null);

        KirimNotifikasiBilling($this, NomorPesananBilling::Buat($tenant->Id, (string) Str::ulid()), 'SUCCESS', '222000.00')
            ->assertStatus(401);
    });

    it('notifikasi SUCCESS yang tidak terkonfirmasi di API status DOKU tidak melunasi apa pun dan dijawab 503 agar diulang', function (string $kasus, array $opsi): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $pembayaran = PembayaranGerbangUji($tagihan);
        $nomor = (string) $pembayaran->RefGateway;

        if ($kasus === 'status masih PENDING') {
            $opsi['JawabanStatus'] = BantuanTagihan::JawabanStatusDoku($nomor, 'PENDING', $tagihan->Total);
        }

        KirimNotifikasiBilling($this, $nomor, 'SUCCESS', $tagihan->Total, $opsi)
            ->assertStatus(503)
            ->assertJsonPath('Galat.Kode', 'StatusBelumTerkonfirmasi');

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    })->with([
        'status masih PENDING' => ['status masih PENDING', []],
        'DOKU tidak terjangkau' => ['DOKU tidak terjangkau', ['JawabanStatus' => false]],
        'DOKU tidak mengenal invoice' => ['DOKU tidak mengenal invoice', ['JawabanStatus' => ['error' => ['message' => 'not found']], 'KodeStatus' => 404]],
        'invoice di jawaban beda' => ['invoice di jawaban beda', ['JawabanStatus' => ['order' => ['invoice_number' => 'lain-01'], 'transaction' => ['status' => 'SUCCESS']]]],
    ]);

    it('nomor pesanan sah tetapi tidak dikenal dijawab 200 tanpa efek, agar gerbang berhenti mengulang', function (): void {
        ['Tenant' => $tenant] = BantuanTagihan::DaftarTenant();
        $asing = NomorPesananBilling::Buat($tenant->Id, (string) Str::ulid());

        KirimNotifikasiBilling($this, $asing, 'SUCCESS', '222000.00')
            ->assertOk()
            ->assertJson(['Diterima' => false]);

        expect(PembayaranLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('invoice_number yang bukan format Payoung (misal notifikasi uji dari dasbor DOKU) dijawab 200 tanpa menghubungi DOKU', function (): void {
        KirimNotifikasiBilling($this, 'INV-UJI-0001', 'SUCCESS', '10000.00')
            ->assertOk()
            ->assertJson(['Diterima' => false]);

        expect(BantuanTagihan::JumlahPanggilanStatusDoku())->toBe(0);
    });

    it('FAILED & EXPIRED menolak pembayaran beralasan, tagihan tetap terbuka supaya bisa dibayar ulang', function (string $status): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $pembayaran = PembayaranGerbangUji($tagihan);

        KirimNotifikasiBilling($this, (string) $pembayaran->RefGateway, $status, $tagihan->Total)
            ->assertOk()
            ->assertJson(['Diterima' => true]);

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($pembayaran->AlasanTolak)->toContain($status)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and(LanggananGerbangUji($tenant)->Status)->not->toBe(StatusLangganan::Aktif);
    })->with(['FAILED', 'EXPIRED']);

    it('status yang belum final (PENDING) belum melunasi apa pun', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $pembayaran = PembayaranGerbangUji($tagihan);

        KirimNotifikasiBilling($this, (string) $pembayaran->RefGateway, 'PENDING', $tagihan->Total)
            ->assertOk();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('jumlah yang dibayar berbeda dari total tidak dilunasi otomatis. Pembayaran tetap menunggu verifikasi manual', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $tagihan);
        $pembayaran = PembayaranGerbangUji($tagihan);

        KirimNotifikasiBilling($this, (string) $pembayaran->RefGateway, 'SUCCESS', '1000.00')
            ->assertOk()
            ->assertJson(['Diterima' => false]);

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('nomor pesanan milik tenant lain tidak bisa melunasi tagihan tenant ini (isolasi tenant)', function (): void {
        ['Tenant' => $tenantA, 'Pengguna' => $pemilikA] = BantuanTagihan::DaftarTenant();
        ['Tenant' => $tenantB] = BantuanTagihan::DaftarTenant('bayu@sinarjaya.id', '081298765432', 'Sinar Jaya');
        $tagihanA = BuatTagihanGerbangUji($this, $pemilikA, $tenantA);
        MulaiBayarOnlineUji($this, $pemilikA, $tenantA, $tagihanA);
        $pembayaranA = PembayaranGerbangUji($tagihanA);

        // ULID pembayaran tenant A, tetapi bagian IdTenant diganti tenant B. Karena tenant ditetapkan dari nomor
        // pesanan lalu pencarian lewat scope MilikTenant, pembayaran tenant A tidak terlihat sama sekali dari
        // lingkup tenant B — isolasinya struktural, bukan hasil perbandingan.
        $nomorPalsu = NomorPesananBilling::Buat($tenantB->Id, $pembayaranA->Uuid);
        KirimNotifikasiBilling($this, $nomorPalsu, 'SUCCESS', $tagihanA->Total)
            ->assertOk()
            ->assertJson(['Diterima' => false]);

        expect($pembayaranA->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihanA->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });
});

describe('BR-P08.11 jalur gerbang = jalur manual', function (): void {
    it('perpanjangan lewat gerbang menyambung periode & mempertahankan jangkar grandfathering, sama seperti verifikasi manual', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();

        // 1. Aktivasi dibayar transfer manual dan diverifikasi Keuangan.
        $aktivasi = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        UnggahBuktiGerbangUji($this, $pemilik, $tenant, $aktivasi);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        app(TerimaPembayaranLangganan::class)->Jalankan($keuangan, PembayaranGerbangUji($aktivasi)->Uuid, $aktivasi->Total);

        $aktivasi->refresh();
        expect(LanggananGerbangUji($tenant)->PeriodeSelesai?->toDateTimeString())->toBe('2026-10-27 03:00:00');

        // 2. Perpanjangan paket yang sama dibayar lewat gerbang, beberapa hari sebelum periode berjalan habis.
        Carbon::setTestNow('2026-10-20 04:00:00');
        $perpanjangan = BuatTagihanGerbangUji($this, $pemilik, $tenant);
        MulaiBayarOnlineUji($this, $pemilik, $tenant, $perpanjangan);
        KirimNotifikasiBilling($this, (string) PembayaranGerbangUji($perpanjangan)->RefGateway, 'SUCCESS', $perpanjangan->Total)->assertOk();

        $langganan = LanggananGerbangUji($tenant);
        expect($perpanjangan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            // Menyambung dari akhir periode berjalan, bukan dari tanggal bayar: 7 hari sisa tidak hilang.
            ->and($langganan->PeriodeMulai?->toDateTimeString())->toBe('2026-10-27 03:00:00')
            ->and($langganan->PeriodeSelesai?->toDateTimeString())->toBe('2026-11-27 03:00:00')
            // Jangkar grandfathering harga (BR-P04.1) diteruskan dari tagihan lunas sebelumnya, paket sama.
            ->and($perpanjangan->MulaiLanggananPaket?->toDateString())->toBe($aktivasi->MulaiLanggananPaket?->toDateString());
    });
});
