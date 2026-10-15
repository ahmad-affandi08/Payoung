<?php

declare(strict_types=1);

use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\RekonsiliasiPembayaranGerbangLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * D-159 | P-08 langkah 3 lewat DOKU SNAP: tenant membayar tagihan langganan di halaman bayar Payoung (QRIS, virtual
 * account, DANA, ShopeePay). Yang dijaga: tanda tangan permintaan sesuai rumus DOKU, kode bayar tidak dibuat dua kali,
 * dan tagihan hanya lunas dari jawaban API status DOKU — notifikasi (palsu atau asli) tidak pernah melunasi sendiri.
 */

const ALAMAT_SNAP = 'https://api-sandbox.doku.com';

/** @var array<string, mixed> Jawaban status per jenis; diisi tiap test. */
$GLOBALS['SnapJawabanStatus'] = [];

function AturSnapUji(array $pengaturan = []): array
{
    $pasangan = (new PembuatKunciSnap)->Buat();
    config()->set('integrasi.GerbangBilling', [
        'Penyedia' => 'DokuBilling',
        'Pengaturan' => [
            'Mode' => 'Sandbox',
            'IdKlien' => BantuanTagihan::ID_KLIEN_DOKU,
            'IdPedagangQris' => 'MID-UJI-1',
            'IdTerminalQris' => 'TERM01',
            'KodePosQris' => '12345',
            'KanalVa' => 'BNI|VIRTUAL_ACCOUNT_BNI|98829172|3; Mandiri|VIRTUAL_ACCOUNT_BANK_MANDIRI|86188|0',
            'DompetDana' => 'Aktif',
            'DompetShopeePay' => 'Aktif',
            ...$pengaturan,
        ],
        'Kredensial' => ['KunciRahasia' => BantuanTagihan::KUNCI_RAHASIA_DOKU, 'KunciPribadiSnap' => $pasangan['Pribadi'], 'KunciPublikSnap' => $pasangan['Publik']],
    ]);
    Cache::flush();
    $GLOBALS['SnapJawabanStatus'] = [];

    Http::fake(function (Request $permintaan) {
        $url = $permintaan->url();
        $jalur = substr($url, strlen(ALAMAT_SNAP));

        if ($jalur === '/authorization/v1/access-token/b2b') {
            return Http::response(['responseCode' => '2007300', 'responseMessage' => 'Successful', 'accessToken' => 'TOKEN-B2B-UJI', 'tokenType' => 'Bearer', 'expiresIn' => 900]);
        }

        if (isset($GLOBALS['SnapJawabanStatus'][$jalur])) {
            $jawaban = $GLOBALS['SnapJawabanStatus'][$jalur];

            if ($jawaban === false) {
                throw new ConnectionException('DOKU tidak terjangkau.');
            }

            return Http::response($jawaban['Badan'], $jawaban['Kode']);
        }

        return match ($jalur) {
            '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'responseMessage' => 'Successful', 'referenceNo' => 'REF-QR-1', 'partnerReferenceNo' => $permintaan['partnerReferenceNo'], 'qrContent' => '00020101021226670016COM.NOBUBANK.WWW']),
            '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va' => Http::response(['responseCode' => '2002700', 'responseMessage' => 'Successful', 'virtualAccountData' => ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001']]),
            '/checkout/v1/payment' => Http::response(['message' => ['SUCCESS'], 'response' => ['order' => ['invoice_number' => $permintaan['order']['invoice_number'], 'amount' => $permintaan['order']['amount']], 'payment' => ['url' => BantuanTagihan::URL_BAYAR_DOKU, 'token_id' => 'tok-uji', 'expired_date' => '20260927040000']]]),
            '/direct-debit/core/v1/debit/payment-host-to-host' => Http::response(['responseCode' => '2005400', 'responseMessage' => 'Successful', 'webRedirectUrl' => 'https://link.dana.id/pay/uji', 'referenceNo' => 'REF-DANA-1']),
            default => Http::response([], 404),
        };
    });

    return $pasangan;
}

/** Mengganti jawaban DOKU palsu untuk satu jalur (status, atau galat pembuatan); `false` = koneksi gagal. */
function AturStatusSnap(string $jalur, array|false $jawaban, int $kode = 200): void
{
    $GLOBALS['SnapJawabanStatus'][$jalur] = $jawaban === false ? false : ['Badan' => $jawaban, 'Kode' => $kode];
}

function BuatTagihanSnapUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
        ->assertSessionHasNoErrors();
    $tes->flushSession();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

/** @param  array<string, mixed>  $data */
function BayarSnapUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, TagihanLangganan $tagihan, array $data): TestResponse
{
    $respons = BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->postJson(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bayar-online"), $data);
    $tes->flushSession();

    return $respons;
}

function PembayaranSnapUji(TagihanLangganan $tagihan): PembayaranLangganan
{
    return PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->latest('Id')->firstOrFail();
}

/** @return list<Request> */
function PermintaanSnapKe(string $jalur): array
{
    return Http::recorded(fn (Request $permintaan): bool => $permintaan->url() === ALAMAT_SNAP.$jalur)->map(fn (array $pasang): Request => $pasang[0])->values()->all();
}

function KirimNotifikasiSnap(TestCase $tes, string $jalur, array $badan): TestResponse
{
    return $tes->postJson('/webhook/billing/snap/'.$jalur, $badan, ['Authorization' => 'Bearer apa-saja']);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanTagihan::SiapkanPrasyarat();
    AturSnapUji();
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('D-159 membuat pembayaran SNAP', function (): void {
    it('token B2B ditandatangani RSA (SHA256withRSA atas "clientId|timestamp") yang bisa diverifikasi kunci publik', function (): void {
        $pasangan = AturSnapUji();
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();

        $token = PermintaanSnapKe('/authorization/v1/access-token/b2b');
        expect($token)->toHaveCount(1);
        $waktu = $token[0]->header('X-TIMESTAMP')[0];
        $tandaTangan = base64_decode($token[0]->header('X-SIGNATURE')[0], true);

        expect($token[0]->header('X-CLIENT-KEY'))->toBe([BantuanTagihan::ID_KLIEN_DOKU])
            ->and($token[0]['grantType'])->toBe('client_credentials')
            ->and($waktu)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(openssl_verify(BantuanTagihan::ID_KLIEN_DOKU.'|'.$waktu, (string) $tandaTangan, $pasangan['Publik'], OPENSSL_ALGO_SHA256))->toBe(1);
    });

    it('QRIS: QR dibuat dengan tanda tangan HMAC-SHA512 sesuai rumus DOKU dan tampil di halaman bayar Payoung', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        $respons = BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();

        $pembayaran = PembayaranSnapUji($tagihan);
        expect($pembayaran->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($pembayaran->MetodeBayar)->toBe('Qris')
            ->and($pembayaran->RefGateway)->toBe(NomorPesananBilling::Buat($tenant->Id, $pembayaran->Uuid))
            ->and($pembayaran->RincianBayar['IsiQr'])->toStartWith('0002010102')
            ->and($pembayaran->RincianBayar['ReferensiGerbang'])->toBe('REF-QR-1')
            ->and($pembayaran->KedaluwarsaPada?->toDateTimeString())->toBe('2026-09-27 04:00:00');

        $respons->assertJsonPath('Pembayaran.TampilanBayar.Tampilan', 'Qr')
            ->assertJsonPath('Pembayaran.TampilanBayar.Metode', 'Qris');
        expect($respons->json('Pembayaran.TampilanBayar.QrSvg'))->toContain('<svg');

        $kirim = PermintaanSnapKe('/snap-adapter/b2b/v1.0/qr/qr-mpm-generate');
        expect($kirim)->toHaveCount(1);
        $badan = $kirim[0]->body();
        $harapan = base64_encode(hash_hmac('sha512', 'POST:/snap-adapter/b2b/v1.0/qr/qr-mpm-generate:TOKEN-B2B-UJI:'.hash('sha256', $badan).':'.$kirim[0]->header('X-TIMESTAMP')[0], BantuanTagihan::KUNCI_RAHASIA_DOKU, true));

        expect($kirim[0]->header('X-SIGNATURE'))->toBe([$harapan])
            ->and($kirim[0]->header('Authorization'))->toBe(['Bearer TOKEN-B2B-UJI'])
            ->and($kirim[0]->header('X-PARTNER-ID'))->toBe([BantuanTagihan::ID_KLIEN_DOKU])
            ->and($kirim[0]->header('CHANNEL-ID'))->toBe(['H2H'])
            ->and($kirim[0]->header('X-EXTERNAL-ID')[0])->toMatch('/^\d+$/')
            ->and($kirim[0]->header('X-TIMESTAMP')[0])->toMatch('/\+07:00$/')
            ->and($kirim[0]['partnerReferenceNo'])->toBe($pembayaran->RefGateway)
            ->and($kirim[0]['amount'])->toBe(['value' => number_format((int) $tagihan->Total, 2, '.', ''), 'currency' => 'IDR'])
            ->and($kirim[0]['merchantId'])->toBe('MID-UJI-1')
            ->and($kirim[0]['terminalId'])->toBe('TERM01')
            ->and($kirim[0]['additionalInfo'])->toBe(['postalCode' => '12345', 'feeType' => '1']);
    });

    it('menekan lagi saat pembayaran SNAP masih berlaku menampilkan kode yang sama, tidak membuat kode bayar kedua', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();
        $ulang = BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BNI'])->assertOk();

        expect(PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->count())->toBe(1)
            ->and(PermintaanSnapKe('/snap-adapter/b2b/v1.0/qr/qr-mpm-generate'))->toHaveCount(1)
            ->and(PermintaanSnapKe('/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va'))->toBe([])
            ->and($ulang->json('Pembayaran.TampilanBayar.Metode'))->toBe('Qris');
    });

    it('virtual account: nomor VA tampil, badan sesuai skema DGPC, bank yang tidak dikenal ditolak', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BCA'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'GerbangMenolak');
        // Ditolak sebelum menyentuh DOKU: tidak ada permintaan pembuatan VA, dan tagihan masih bisa dibayar.
        expect(PermintaanSnapKe('/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va'))->toBe([]);

        $respons = BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BNI'])->assertOk();

        $respons->assertJsonPath('Pembayaran.TampilanBayar.Tampilan', 'NomorVa')
            ->assertJsonPath('Pembayaran.TampilanBayar.NomorVa', '9882917230000000000000000001')
            ->assertJsonPath('Pembayaran.TampilanBayar.NamaBank', 'BNI')
            ->assertJsonPath('Pembayaran.LabelMetode', 'Virtual account BNI');

        $pembayaran = PembayaranSnapUji($tagihan);
        $kirim = PermintaanSnapKe('/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va')[0];

        expect($pembayaran->KanalBayar)->toBe('VIRTUAL_ACCOUNT_BNI')
            ->and($pembayaran->RincianBayar['CustomerNo'])->toBe('30000000000000000001')
            ->and($kirim['partnerServiceId'])->toBe('98829172')
            ->and($kirim['customerNo'])->toBe('3')
            ->and($kirim['virtualAccountNo'])->toBe('988291723')
            ->and($kirim['trxId'])->toBe($pembayaran->RefGateway)
            ->and($kirim['virtualAccountTrxType'])->toBe('C')
            ->and($kirim['totalAmount']['currency'])->toBe('IDR')
            ->and($kirim['additionalInfo']['channel'])->toBe('VIRTUAL_ACCOUNT_BNI')
            ->and($kirim['expiredDate'])->toBe('2026-09-27T11:00:00+07:00');
    });

    it('virtual account Mandiri: BIN 5 digit dikirim berspasi 8 karakter dan nomor VA memakai awalan yang sama', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI'])->assertOk();

        $kirim = PermintaanSnapKe('/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va')[0];

        expect($kirim['partnerServiceId'])->toBe('   86188')
            ->and($kirim['customerNo'])->toBe('0')
            ->and($kirim['virtualAccountNo'])->toBe('   861880')
            ->and($kirim['additionalInfo']['channel'])->toBe('VIRTUAL_ACCOUNT_BANK_MANDIRI');
    });

    it('DANA dan ShopeePay: tautan aplikasi dompet tampil; DANA memakai urlParam, ShopeePay urlParams', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Dana'])
            ->assertOk()
            ->assertJsonPath('Pembayaran.TampilanBayar.Tampilan', 'Alihkan')
            ->assertJsonPath('Pembayaran.TampilanBayar.UrlAlih', 'https://link.dana.id/pay/uji');

        $dana = PermintaanSnapKe('/direct-debit/core/v1/debit/payment-host-to-host')[0];
        expect($dana['additionalInfo']['channel'])->toBe('EMONEY_DANA_SNAP')
            ->and($dana['urlParam']['type'])->toBe('PAY_RETURN')
            ->and($dana['urlParam']['url'])->toEndWith("/kelola/langganan/tagihan/{$tagihan->Uuid}")
            ->and(isset($dana['urlParams']))->toBeFalse();

        // Pembayaran DANA ditutup (kedaluwarsa) supaya ShopeePay bisa dicoba pada tagihan yang sama.
        PembayaranSnapUji($tagihan)->update(['Status' => StatusPembayaranLangganan::Ditolak, 'AlasanTolak' => 'uji']);
        $this->flushSession();
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'ShopeePay'])->assertOk();

        $shopee = PermintaanSnapKe('/direct-debit/core/v1/debit/payment-host-to-host')[1];
        expect($shopee['additionalInfo']['channel'])->toBe('EMONEY_SHOPEE_PAY_SNAP')
            ->and($shopee['urlParams'][0]['isDeepLink'])->toBe('N')
            ->and(isset($shopee['urlParam']))->toBeFalse();
    });

    it('cara bayar yang pengaturannya belum lengkap atau dimatikan tidak bisa dipakai', function (): void {
        AturSnapUji(['IdPedagangQris' => '', 'DompetDana' => 'Mati', 'KanalVa' => '']);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);

        foreach ([['Metode' => 'Qris'], ['Metode' => 'Dana'], ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BNI']] as $data) {
            BayarSnapUji($this, $pemilik, $tenant, $tagihan, $data)->assertStatus(422)->assertJsonPath('Galat.Kode', 'GerbangMenolak');
        }

        // ShopeePay tetap aktif, jadi halaman tagihan hanya menawarkan itu.
        $halaman = BantuanTagihan::Masuk($this, $pemilik, $tenant)->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"));
        expect(collect($halaman->viewData('page')['props']['PilihanBayar'])->pluck('Metode')->all())->toBe(['ShopeePay']);
    });

    it('DOKU menolak dengan pasti: pembayaran ditutup Ditolak; hasil tak pasti dibiarkan Menunggu', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-generate', ['responseCode' => '4004700', 'responseMessage' => 'Invalid merchantId '.BantuanTagihan::KUNCI_RAHASIA_DOKU], 400);

        $respons = BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertStatus(422);

        expect($respons->json('Galat.Pesan'))->not->toContain(BantuanTagihan::KUNCI_RAHASIA_DOKU)
            ->and(PembayaranSnapUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Ditolak);

        $this->flushSession();
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-generate', ['responseCode' => '5004700', 'responseMessage' => 'error'], 500);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertStatus(422);

        expect(PembayaranSnapUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and(PembayaranSnapUji($tagihan)->RincianBayar)->toBeNull();
    });
});

describe('D-159 pelunasan hanya dari status DOKU', function (): void {
    function SiapkanQrisMenunggu(TestCase $tes): array
    {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($tes, $pemilik, $tenant);
        BayarSnapUji($tes, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();

        return [$tenant, $pemilik, $tagihan, PembayaranSnapUji($tagihan)];
    }

    it('notifikasi QRIS memicu pertanyaan status; status 00 melunasi tagihan dan jawabannya berbentuk SNAP', function (): void {
        [$tenant, , $tagihan, $pembayaran] = SiapkanQrisMenunggu($this);
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '00', 'amount' => ['value' => number_format((int) $tagihan->Total, 2, '.', ''), 'currency' => 'IDR']]);

        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', ['originalPartnerReferenceNo' => $pembayaran->RefGateway, 'originalReferenceNo' => 'REF-QR-1', 'latestTransactionStatus' => '00'])
            ->assertOk()
            ->assertJson(['responseCode' => '2005200', 'responseMessage' => 'Successful']);

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);

        $tanya = PermintaanSnapKe('/snap-adapter/b2b/v1.0/qr/qr-mpm-query')[0];
        expect($tanya['originalReferenceNo'])->toBe('REF-QR-1')
            ->and($tanya['originalPartnerReferenceNo'])->toBe($pembayaran->RefGateway)
            ->and($tanya['serviceCode'])->toBe('47')
            ->and($tanya['merchantId'])->toBe('MID-UJI-1');
    });

    it('notifikasi yang mengaku lunas padahal DOKU menjawab pending TIDAK melunasi apa pun', function (): void {
        [, , $tagihan, $pembayaran] = SiapkanQrisMenunggu($this);
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '03']);

        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', ['originalPartnerReferenceNo' => $pembayaran->RefGateway, 'latestTransactionStatus' => '00'])->assertOk();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('DOKU tidak terjangkau saat notifikasi: tetap dijawab 200 dan pembayaran dibiarkan Menunggu (rekonsiliasi jaring pengaman)', function (): void {
        [, , $tagihan, $pembayaran] = SiapkanQrisMenunggu($this);
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', false);

        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', ['originalPartnerReferenceNo' => $pembayaran->RefGateway])->assertOk();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('nomor pesanan tidak dikenal atau bukan format Payoung dijawab 200 tanpa menghubungi DOKU', function (): void {
        SiapkanQrisMenunggu($this);
        $sebelum = count(PermintaanSnapKe('/snap-adapter/b2b/v1.0/qr/qr-mpm-query'));

        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', ['originalPartnerReferenceNo' => 'INV-ASAL-ASALAN'])->assertOk();
        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', ['originalPartnerReferenceNo' => NomorPesananBilling::Buat(999999, '01K5AAAAAAAAAAAAAAAAAAAAAA')])->assertOk();
        KirimNotifikasiSnap($this, 'v1.0/qr/qr-mpm-notify', [])->assertOk();

        expect(count(PermintaanSnapKe('/snap-adapter/b2b/v1.0/qr/qr-mpm-query')))->toBe($sebelum);
    });

    it('notifikasi virtual account dijawab dengan virtualAccountData dan melunasi hanya bila status VA lunas', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BNI'])->assertOk();
        $pembayaran = PembayaranSnapUji($tagihan);
        $badan = ['partnerServiceId' => '98829172', 'customerNo' => '30000000000000000001', 'virtualAccountNo' => '9882917230000000000000000001', 'virtualAccountName' => 'Rina', 'trxId' => $pembayaran->RefGateway, 'paymentRequestId' => 'PAY-1'];
        $total = number_format((int) $tagihan->Total, 2, '.', '');

        // Pending dengan paidAmount terisi (contoh di dokumen DOKU): belum lunas.
        AturStatusSnap('/orders/v1.0/transfer-va/status', ['responseCode' => '2002600', 'virtualAccountData' => ['paymentFlagReason' => ['english' => 'Pending'], 'paidAmount' => ['value' => $total, 'currency' => 'IDR']]]);
        KirimNotifikasiSnap($this, 'v1.0/transfer-va/payment', $badan)
            ->assertOk()
            ->assertJsonPath('responseCode', '2002500')
            ->assertJsonPath('virtualAccountData.virtualAccountNo', '9882917230000000000000000001')
            ->assertJsonPath('virtualAccountData.paymentRequestId', 'PAY-1');
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        Cache::flush();
        AturStatusSnap('/orders/v1.0/transfer-va/status', ['responseCode' => '2002600', 'virtualAccountData' => ['paymentFlagStatus' => '00', 'paidAmount' => ['value' => $total, 'currency' => 'IDR']]]);
        KirimNotifikasiSnap($this, 'v1.0/transfer-va/payment', $badan)->assertOk();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);

        $tanya = PermintaanSnapKe('/orders/v1.0/transfer-va/status')[0];
        expect($tanya['virtualAccountNo'])->toBe('9882917230000000000000000001')
            ->and($tanya['customerNo'])->toBe('30000000000000000001');
    });

    it('jumlah VA yang dibayar berbeda dari total tidak dilunasi otomatis', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'VirtualAccount', 'Kanal' => 'VIRTUAL_ACCOUNT_BNI'])->assertOk();
        $pembayaran = PembayaranSnapUji($tagihan);
        AturStatusSnap('/orders/v1.0/transfer-va/status', ['virtualAccountData' => ['paymentFlagStatus' => '00', 'paidAmount' => ['value' => '1000.00', 'currency' => 'IDR']]]);

        KirimNotifikasiSnap($this, 'v1.0/transfer-va/payment', ['trxId' => $pembayaran->RefGateway, 'virtualAccountNo' => 'x'])->assertOk();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('e-wallet: status debit 00 melunasi, 06 menolak pembayaran sehingga tagihan bisa dibayar ulang', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Dana'])->assertOk();
        $pembayaran = PembayaranSnapUji($tagihan);
        AturStatusSnap('/orders/v1.0/debit/status', ['responseCode' => '2005500', 'latestTransactionStatus' => '06']);

        KirimNotifikasiSnap($this, 'v1.0/debit/notify', ['originalPartnerReferenceNo' => $pembayaran->RefGateway])
            ->assertOk()
            ->assertJson(['responseCode' => '2005600']);

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        $tanya = PermintaanSnapKe('/orders/v1.0/debit/status')[0];
        expect($tanya['serviceCode'])->toBe('55')
            ->and($tanya['originalPartnerReferenceNo'])->toBe($pembayaran->RefGateway);
    });

    it('endpoint token B2B untuk DOKU menerbitkan token berlaku 15 menit', function (): void {
        $this->postJson('/webhook/billing/snap/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('responseCode', '2007300')
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('expiresIn', '900')
            ->assertJsonStructure(['accessToken']);
    });

    it('polling halaman bayar: status-bayar menanyakan DOKU dan melaporkan Lunas', function (): void {
        [$tenant, $pemilik, $tagihan] = SiapkanQrisMenunggu($this);
        $url = BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/status-bayar");

        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '03']);
        BantuanTagihan::Masuk($this, $pemilik, $tenant)->getJson($url)->assertOk()->assertJson(['Lunas' => false, 'MasihMenunggu' => true]);
        $this->flushSession();

        Cache::flush();
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '00']);
        BantuanTagihan::Masuk($this, $pemilik, $tenant)->getJson($url)->assertOk()->assertJson(['Lunas' => true, 'MasihMenunggu' => false, 'StatusTagihan' => 'Lunas']);
    });

    it('halaman tagihan membawa pilihan bayar dan menampilkan ulang QR yang masih berlaku', function (): void {
        [$tenant, $pemilik, $tagihan] = SiapkanQrisMenunggu($this);

        $halaman = BantuanTagihan::Masuk($this, $pemilik, $tenant)->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))->assertOk();
        $props = $halaman->viewData('page')['props'];

        expect(collect($props['PilihanBayar'])->pluck('Label')->all())->toBe(['QRIS', 'Virtual account BNI', 'Virtual account Mandiri', 'DANA', 'ShopeePay'])
            ->and($props['BolehBayarOnline'])->toBeTrue()
            ->and($props['Pembayaran'][0]['TampilanBayar']['Tampilan'])->toBe('Qr')
            ->and($props['Pembayaran'][0]['LabelMetode'])->toBe('QRIS');
    });
});

describe('D-159 rekonsiliasi & kedaluwarsa', function (): void {
    it('rekonsiliasi melunasi pembayaran SNAP yang notifikasinya tidak pernah tiba', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '00']);
        Carbon::setTestNow('2026-09-27 03:20:00');

        $hasil = app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan();

        expect($hasil)->toBe(['Diperiksa' => 1, 'Selesai' => 1, 'Menunggu' => 0, 'Gagal' => 0])
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);
    });

    it('belum dibayar dan lewat batas (+ jeda 15 menit) = kedaluwarsa: pembayaran ditolak dan tagihan bisa dibayar ulang', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();
        AturStatusSnap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', ['responseCode' => '2005100', 'latestTransactionStatus' => '01']);

        Carbon::setTestNow('2026-09-27 03:50:00');
        app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan();
        expect(PembayaranSnapUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        Carbon::setTestNow('2026-09-27 04:20:00');
        app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan();

        expect(PembayaranSnapUji($tagihan)->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        // Kode bayar baru bisa dibuat setelah yang lama kedaluwarsa.
        $this->flushSession();
        BayarSnapUji($this, $pemilik, $tenant, $tagihan, ['Metode' => 'Qris'])->assertOk();
        expect(PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->count())->toBe(2);
    });

    it('DOKU Checkout lama tetap jalan berdampingan: tanpa Metode, transaksi Checkout dibuat seperti sebelumnya', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanTagihan::DaftarTenant();
        $tagihan = BuatTagihanSnapUji($this, $pemilik, $tenant);
        BantuanTagihan::FakeDoku();

        BayarSnapUji($this, $pemilik, $tenant, $tagihan, [])
            ->assertOk()
            ->assertJson(['UrlBayar' => BantuanTagihan::URL_BAYAR_DOKU]);

        expect(PembayaranSnapUji($tagihan)->MetodeBayar)->toBeNull();
    });
});
