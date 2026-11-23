<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanQris;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;

/*
 * Adaptor gerbang pembayaran QRIS dinamis: bentuk permintaan & tanda tangan sesuai dokumentasi penyedia, notifikasi
 * sebagai pemicu (status ditanyakan balik ke DOKU, D-314), dan pemetaan status. Sejak seluruh tenant memakai DOKU,
 * DOKU satu-satunya penyedia gerbang toko, lewat DOKU SNAP (D-314); kode penyedia lama (Midtrans, Xendit, Tripay,
 * Duitku, iPaymu) tidak dikenal.
 */

function PermintaanQrisUji(): PermintaanQris
{
    return new PermintaanQris('QR-SLB-0001', Uang::Dari('25000'), 'Penjualan Kopi Senja', CarbonImmutable::now()->addMinutes(15), 'https://payoung.id/webhook/uji');
}

/** Gerbang DOKU SNAP uji: Client ID, Merchant ID, Terminal ID, kode pos, secret key, dan kunci RSA yang sungguhan. */
function Gerbang(array $ubahPengaturan = [], array $ubahKredensial = [])
{
    return app(PembuatGerbangPembayaran::class)->Buat(
        'Doku',
        ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001', 'IdPedagangQris' => '111642', 'IdTerminalQris' => 'A01', 'KodePosQris' => '57214', ...$ubahPengaturan],
        ['KunciRahasia' => 'SK-doku', 'KunciPribadiSnap' => BantuanGerbangTenant::AmbilPasanganKunci()['Pribadi'], ...$ubahKredensial],
    );
}

/** `Http::fake` berulang menumpuk (stub pertama menang), jadi pabrik HTTP diganti baru sebelum tiap skenario. */
function SegarkanHttpUji(): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
}

const NOMOR_QRIS_UJI = 'PY1-01M84D56XTCTT66D3DNC7YQJ63';

/** DOKU SNAP palsu: token B2B, `qr-mpm-generate`, dan `qr-mpm-query` dengan `latestTransactionStatus` tertentu. */
function PalsukanDokuSnap(string $kodeStatus = '03', ?string $jumlah = null): void
{
    SegarkanHttpUji();
    Http::fake([
        'api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['accessToken' => 'token-b2b-uji', 'tokenType' => 'Bearer', 'expiresIn' => '900']),
        'api-sandbox.doku.com/snap-adapter/b2b/v1.0/qr/qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'responseMessage' => 'Successful', 'referenceNo' => 'REF-DOKU-1', 'qrContent' => '00020101021226UJI']),
        'api-sandbox.doku.com/snap-adapter/b2b/v1.0/qr/qr-mpm-query' => Http::response([
            'responseCode' => '2005100',
            'latestTransactionStatus' => $kodeStatus,
            ...($jumlah === null ? [] : ['amount' => ['value' => $jumlah, 'currency' => 'IDR']]),
        ]),
    ]);
}

function WebhookJson(array $isi, array $header = []): Request
{
    $permintaan = Request::create('/webhook/uji', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($isi));
    foreach ($header as $nama => $nilai) {
        $permintaan->headers->set($nama, $nilai);
    }

    return $permintaan;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('DOKU SNAP: QRIS dibuat dengan tanda tangan HMAC-SHA512 atas token, hasil berupa muatan QRIS (bukan halaman bayar)', function (): void {
    PalsukanDokuSnap();
    $hasil = Gerbang()->BuatQris(PermintaanQrisUji());

    expect($hasil->halamanBayar)->toBeFalse()
        ->and($hasil->isiQr)->toBe('00020101021226UJI')
        ->and($hasil->idReferensi)->toBe('REF-DOKU-1');
    Http::assertSent(function (PermintaanHttp $r): bool {
        if (! str_ends_with($r->url(), '/qr/qr-mpm-generate')) {
            return false;
        }

        $waktu = $r->header('X-TIMESTAMP')[0];
        $teks = 'POST:/snap-adapter/b2b/v1.0/qr/qr-mpm-generate:token-b2b-uji:'.strtolower(hash('sha256', $r->body())).':'.$waktu;

        return $r['amount'] === ['value' => '25000.00', 'currency' => 'IDR']
            && $r['partnerReferenceNo'] === 'QR-SLB-0001'
            && $r->hasHeader('X-SIGNATURE', base64_encode(hash_hmac('sha512', $teks, 'SK-doku', true)))
            && $r->hasHeader('Authorization', 'Bearer token-b2b-uji');
    });
});

it('DOKU SNAP: notifikasi hanya pemicu. Status & jumlah dari jawaban DOKU, notifikasi palsu yang mengaku SUCCESS tidak melunasi', function (): void {
    $isi = ['order' => ['invoice_number' => NOMOR_QRIS_UJI, 'amount' => 25000], 'transaction' => ['status' => 'SUCCESS']];

    PalsukanDokuSnap('00', '25000.00');
    $sah = Gerbang()->UraiWebhook(WebhookJson($isi));
    expect($sah?->status)->toBe(StatusPembayaranGerbang::Lunas)
        ->and($sah?->nomorPesanan)->toBe(NOMOR_QRIS_UJI)
        ->and($sah?->jumlah)->toBe('25000.00');

    // DOKU menjawab belum dibayar: pengiriman palsu yang mengaku SUCCESS tidak mengubah apa pun.
    PalsukanDokuSnap('03');
    expect(Gerbang()->UraiWebhook(WebhookJson($isi))?->status)->toBe(StatusPembayaranGerbang::Menunggu);

    // DOKU tidak bisa ditanya (5xx): tetap Menunggu, bukan Lunas dan bukan galat.
    SegarkanHttpUji();
    Http::fake([
        'api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['accessToken' => 'token-b2b-uji', 'expiresIn' => '900']),
        '*' => Http::response('gangguan', 503),
    ]);
    expect(Gerbang()->UraiWebhook(WebhookJson($isi))?->status)->toBe(StatusPembayaranGerbang::Menunggu);

    // Badan tanpa nomor pesanan Payoung tidak dikenali.
    expect(Gerbang()->UraiWebhook(WebhookJson(['order' => ['invoice_number' => 'ORDER-LAIN-1']])))->toBeNull();
});

it('DOKU SNAP: status dipetakan (00 lunas, 05/06 gagal, lainnya menunggu) lewat nomor pesanan', function (): void {
    $gerbang = Gerbang();
    expect($gerbang->CekDapatCekDariNomorPesanan())->toBeTrue();

    foreach (['00' => StatusPembayaranGerbang::Lunas, '05' => StatusPembayaranGerbang::Gagal, '06' => StatusPembayaranGerbang::Gagal, '03' => StatusPembayaranGerbang::Menunggu, '99' => StatusPembayaranGerbang::Menunggu] as $kode => $status) {
        PalsukanDokuSnap((string) $kode);
        expect(Gerbang()->CekStatus(NOMOR_QRIS_UJI, ''))->toBe($status);
    }

    Http::assertSent(fn (PermintaanHttp $r): bool => str_ends_with($r->url(), '/qr/qr-mpm-query')
        && $r['originalPartnerReferenceNo'] === NOMOR_QRIS_UJI
        && $r['merchantId'] === '111642'
        && ! isset($r['originalReferenceNo']));
});

it('DOKU SNAP: masalah gerbang dilempar sebagai GalatGerbang tanpa membocorkan secret key. Uji koneksi menolak token', function (): void {
    SegarkanHttpUji();
    Http::fake(['*' => Http::response(['responseCode' => '4017300', 'responseMessage' => 'Unauthorized SK-doku'], 401)]);
    $gerbang = Gerbang();

    expect(fn () => $gerbang->BuatQris(PermintaanQrisUji()))->toThrow(GalatGerbang::class)
        ->and($gerbang->UjiKoneksi()->berhasil)->toBeFalse()
        ->and($gerbang->UjiKoneksi()->pesan)->not->toContain('SK-doku');
});

it('DOKU SNAP: jawaban sukses tanpa isi QRIS = hasil tidak pasti. Pengaturan tidak lengkap = galat pasti tanpa panggilan jaringan', function (): void {
    SegarkanHttpUji();
    Http::fake([
        'api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(['accessToken' => 'token-b2b-uji', 'expiresIn' => '900']),
        '*' => Http::response(['responseCode' => '2004700', 'responseMessage' => 'Successful']),
    ]);

    try {
        Gerbang()->BuatQris(PermintaanQrisUji());
        $galat = null;
    } catch (GalatGerbang $e) {
        $galat = $e;
    }

    expect($galat)->toBeInstanceOf(GalatGerbang::class)->and($galat->tidakPasti)->toBeTrue();

    SegarkanHttpUji();
    Http::fake();
    $kurang = Gerbang(['IdPedagangQris' => '']);

    try {
        $kurang->BuatQris(PermintaanQrisUji());
        $galat = null;
    } catch (GalatGerbang $e) {
        $galat = $e;
    }

    expect($galat?->tidakPasti)->toBeFalse()->and($galat?->getMessage())->toContain('Merchant ID QRIS');
    Http::assertNothingSent();
});

// v2.06: gerbang aktif dibaca per tenant (`AmbilAktifTenant`, diuji di GerbangPembayaranTenantTes); di sini hanya pabrik.
it('penyedia tidak dikenal atau penyedia lama yang sudah dihapus → null', function (string $penyedia): void {
    expect(app(PembuatGerbangPembayaran::class)->Buat($penyedia, [], []))->toBeNull();
})->with(['Tidakada', 'Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu']);
