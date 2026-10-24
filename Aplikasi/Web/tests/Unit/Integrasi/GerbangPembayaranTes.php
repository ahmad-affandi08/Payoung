<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanQris;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * Adaptor gerbang pembayaran QRIS dinamis (v2.04): bentuk permintaan & tanda tangan sesuai dokumentasi penyedia,
 * verifikasi notifikasi (tanda tangan palsu ditolak), dan pemetaan status. Sejak seluruh tenant memakai DOKU, DOKU
 * satu-satunya penyedia gerbang toko; kode penyedia lama (Midtrans, Xendit, Tripay, Duitku, iPaymu) tidak dikenal.
 */

function PermintaanQrisUji(): PermintaanQris
{
    return new PermintaanQris('QR-SLB-0001', Uang::Dari('25000'), 'Penjualan Kopi Senja', CarbonImmutable::now()->addMinutes(15), 'https://payoung.id/webhook/uji');
}

function Gerbang(string $penyedia, array $pengaturan, array $kredensial)
{
    return app(PembuatGerbangPembayaran::class)->Buat($penyedia, $pengaturan, $kredensial);
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

it('DOKU: header tanda tangan HMACSHA256 & notifikasi palsu ditolak; hasil berupa halaman bayar', function (): void {
    Http::fake(['api-sandbox.doku.com/checkout/v1/payment' => Http::response(['response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout/link/abc', 'token_id' => 'tok']]])]);
    $gerbang = Gerbang('Doku', ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], ['KunciRahasia' => 'SK-doku']);
    $hasil = $gerbang->BuatQris(PermintaanQrisUji());
    expect($hasil->halamanBayar)->toBeTrue()->and($hasil->isiQr)->toBe('https://sandbox.doku.com/checkout/link/abc');
    Http::assertSent(function (PermintaanHttp $r): bool {
        $komponen = 'Client-Id:BRN-001'."\nRequest-Id:".$r->header('Request-Id')[0]."\nRequest-Timestamp:".$r->header('Request-Timestamp')[0]
            ."\nRequest-Target:/checkout/v1/payment\nDigest:".base64_encode(hash('sha256', $r->body(), true));

        return $r->hasHeader('Signature', 'HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, 'SK-doku', true)));
    });

    $notif = WebhookJson(['order' => ['invoice_number' => 'QR-SLB-0001', 'amount' => 25000], 'transaction' => ['status' => 'SUCCESS']], [
        'Client-Id' => 'BRN-001', 'Request-Id' => 'r1', 'Request-Timestamp' => '2026-09-26T03:00:00Z', 'Signature' => 'HMACSHA256=palsu',
    ]);
    expect($gerbang->UraiWebhook($notif))->toBeNull();
});

it('DOKU: notifikasi bertanda tangan sah = lunas dengan jumlah; Client-Id lain atau isi diubah ditolak', function (): void {
    $gerbang = Gerbang('Doku', ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], ['KunciRahasia' => 'SK-doku']);
    $isi = (string) json_encode(['order' => ['invoice_number' => 'QR-SLB-0001', 'amount' => 25000], 'transaction' => ['status' => 'SUCCESS']]);
    $buat = function (string $isiKirim, string $idKlien = 'BRN-001', string $kunci = 'SK-doku') use ($isi): Request {
        $komponen = "Client-Id:BRN-001\nRequest-Id:r1\nRequest-Timestamp:2026-09-26T03:00:00Z\nRequest-Target:/webhook/doku/token\nDigest:".base64_encode(hash('sha256', $isi, true));
        $permintaan = Request::create('/webhook/doku/token', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $isiKirim);
        $permintaan->headers->add([
            'Client-Id' => $idKlien, 'Request-Id' => 'r1', 'Request-Timestamp' => '2026-09-26T03:00:00Z',
            'Signature' => 'HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, $kunci, true)),
        ]);

        return $permintaan;
    };

    $sah = $gerbang->UraiWebhook($buat($isi));
    expect($sah?->status)->toBe(StatusPembayaranGerbang::Lunas)
        ->and($sah?->nomorPesanan)->toBe('QR-SLB-0001')
        ->and($sah?->jumlah)->toBe('25000')
        ->and($gerbang->UraiWebhook($buat($isi, kunci: 'kunci-lain')))->toBeNull()
        ->and($gerbang->UraiWebhook($buat($isi, idKlien: 'BRN-LAIN')))->toBeNull()
        ->and($gerbang->UraiWebhook($buat(str_replace('25000', '1', $isi))))->toBeNull();
});

it('DOKU: status order dipetakan (SUCCESS lunas, EXPIRED kedaluwarsa, FAILED gagal, lainnya menunggu) lewat nomor pesanan', function (): void {
    Http::fake([
        'api-sandbox.doku.com/orders/v1/status/QR-LUNAS' => Http::response(['transaction' => ['status' => 'SUCCESS']]),
        'api-sandbox.doku.com/orders/v1/status/QR-HABIS' => Http::response(['transaction' => ['status' => 'EXPIRED']]),
        'api-sandbox.doku.com/orders/v1/status/QR-GAGAL' => Http::response(['transaction' => ['status' => 'FAILED']]),
        'api-sandbox.doku.com/orders/v1/status/QR-TUNGGU' => Http::response(['transaction' => ['status' => 'PENDING']]),
    ]);
    $gerbang = Gerbang('Doku', ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], ['KunciRahasia' => 'SK-doku']);

    expect($gerbang->CekDapatCekDariNomorPesanan())->toBeTrue()
        ->and($gerbang->CekStatus('QR-LUNAS', ''))->toBe(StatusPembayaranGerbang::Lunas)
        ->and($gerbang->CekStatus('QR-HABIS', ''))->toBe(StatusPembayaranGerbang::Kedaluwarsa)
        ->and($gerbang->CekStatus('QR-GAGAL', ''))->toBe(StatusPembayaranGerbang::Gagal)
        ->and($gerbang->CekStatus('QR-TUNGGU', ''))->toBe(StatusPembayaranGerbang::Menunggu);
});

it('DOKU: masalah gerbang dilempar sebagai GalatGerbang tanpa membocorkan secret key; uji koneksi menolak tanda tangan tidak sah', function (): void {
    Http::fake(['*' => Http::response(['message' => ['Invalid signature for SK-doku']], 401)]);
    $gerbang = Gerbang('Doku', ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], ['KunciRahasia' => 'SK-doku']);

    expect(fn () => $gerbang->BuatQris(PermintaanQrisUji()))->toThrow(GalatGerbang::class, 'Invalid signature for ••••')
        ->and($gerbang->UjiKoneksi()->berhasil)->toBeFalse();
});

it('DOKU: pembuatan tagihan tanpa halaman bayar di jawaban = hasil tidak pasti', function (): void {
    Http::fake(['*' => Http::response(['response' => ['payment' => []]])]);
    $gerbang = Gerbang('Doku', ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], ['KunciRahasia' => 'SK-doku']);

    try {
        $gerbang->BuatQris(PermintaanQrisUji());
        $galat = null;
    } catch (GalatGerbang $e) {
        $galat = $e;
    }

    expect($galat)->toBeInstanceOf(GalatGerbang::class)->and($galat->tidakPasti)->toBeTrue();
});

// v2.06: gerbang aktif dibaca per tenant (`AmbilAktifTenant`, diuji di GerbangPembayaranTenantTes); di sini hanya pabrik.
it('penyedia tidak dikenal atau penyedia lama yang sudah dihapus → null', function (string $penyedia): void {
    expect(app(PembuatGerbangPembayaran::class)->Buat($penyedia, [], []))->toBeNull();
})->with(['Tidakada', 'Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu']);
