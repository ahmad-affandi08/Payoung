<?php

declare(strict_types=1);

use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;

/*
 * Pengirim WhatsApp (v2.04): resmi (WhatsApp Cloud API) dan tidak resmi (Fonnte, Wablas, StarSender, Watzap).
 */

function Pengirim(string $penyedia, array $pengaturan, array $kredensial)
{
    return app(PembuatPengirimWhatsapp::class)->Buat($penyedia, $pengaturan, $kredensial);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('merapikan nomor Indonesia ke format 62', function (string $masuk, string $keluar): void {
    expect(PesanWhatsapp::RapikanNomor($masuk))->toBe($keluar);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['6281234567890', '6281234567890'],
    ['81234567890', '6281234567890'],
]);

it('WhatsApp Cloud API: teks biasa dan templat dengan parameter', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    $pengirim = Pengirim('MetaCloud', ['IdNomorTelepon' => '1099', 'VersiApi' => 'v21.0', 'BahasaTemplat' => 'id'], ['TokenAkses' => 'EAAG']);
    expect($pengirim->CekResmi())->toBeTrue()
        ->and($pengirim->Kirim(new PesanWhatsapp('0812345', 'Halo'))->idPesan)->toBe('wamid.1');
    Http::assertSent(fn (PermintaanHttp $r) => $r->url() === 'https://graph.facebook.com/v21.0/1099/messages' && $r['type'] === 'text' && $r['to'] === '62812345' && $r->hasHeader('Authorization', 'Bearer EAAG'));

    $pengirim->Kirim(new PesanWhatsapp('0812345', 'Halo', 'struk_digital', ['Kopi Senja', 'Rp 25.000', 'https://payoung.id/s/abc']));
    Http::assertSent(fn (PermintaanHttp $r) => $r['type'] === 'template' && $r['template']['name'] === 'struk_digital'
        && $r['template']['language'] === ['code' => 'id'] && $r['template']['components'][0]['parameters'][2]['text'] === 'https://payoung.id/s/abc');
});

it('Fonnte, Wablas, StarSender, Watzap: kirim teks sesuai format tiap penyedia', function (): void {
    Http::fake([
        'api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['80']]),
        'jkt.wablas.com/api/send-message' => Http::response(['status' => true, 'data' => ['messages' => [['id' => 'wb1']]]]),
        'api.starsender.online/api/send' => Http::response(['success' => true, 'data' => ['id' => 's1']]),
        'api.watzap.id/v1/send_message' => Http::response(['status' => '200']),
    ]);
    $pesan = new PesanWhatsapp('0812345', 'Struk Anda');

    expect(Pengirim('Fonnte', [], ['Token' => 'tf'])->Kirim($pesan)->idPesan)->toBe('80');
    Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'fonnte') && $r['target'] === '62812345' && $r->hasHeader('Authorization', 'tf'));

    expect(Pengirim('Wablas', ['Domain' => 'https://jkt.wablas.com'], ['Token' => 'tw', 'KunciRahasia' => 'sk'])->Kirim($pesan)->berhasil)->toBeTrue();
    Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'wablas') && $r->hasHeader('Authorization', 'tw.sk') && $r['phone'] === '62812345');

    expect(Pengirim('StarSender', [], ['KunciApi' => 'ks'])->Kirim($pesan)->berhasil)->toBeTrue();
    Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'starsender') && $r['messageType'] === 'text' && $r['to'] === '62812345');

    expect(Pengirim('Watzap', ['KunciNomor' => 'nk'], ['KunciApi' => 'kw'])->Kirim($pesan)->berhasil)->toBeTrue();
    Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'watzap') && $r['number_key'] === 'nk' && $r['phone_no'] === '62812345');
});

it('Wablas: domain buatan tenant yang menunjuk ke jaringan dalam ditolak tanpa permintaan keluar (SSRF)', function (string $domain): void {
    Http::fake();
    $pengirim = Pengirim('Wablas', ['Domain' => $domain], ['Token' => 'tw']);

    $kirim = $pengirim->Kirim(new PesanWhatsapp('0812345', 'x'));
    $uji = $pengirim->UjiKoneksi();

    expect($kirim->berhasil)->toBeFalse()->and($uji->berhasil)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'metadata awan' => ['http://169.254.169.254'],
    'localhost' => ['https://localhost'],
    'jaringan privat' => ['https://10.0.0.5'],
    'tanpa https' => ['http://jkt.wablas.com'],
    'bukan domain wablas, alamat IP privat' => ['https://192.168.1.10'],
    'kredensial di URL' => ['https://user:kunci@jkt.wablas.com'],
]);

it('Wablas: domain resmi wablas.com tidak mengikuti pengalihan', function (): void {
    Http::fake(['jkt.wablas.com/api/send-message' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    $hasil = Pengirim('Wablas', ['Domain' => 'https://jkt.wablas.com'], ['Token' => 'tw'])->Kirim(new PesanWhatsapp('0812345', 'x'));

    expect($hasil->berhasil)->toBeFalse();
    Http::assertSentCount(1);
});

it('kegagalan dikembalikan sebagai hasil, kredensial tidak ikut dalam pesan', function (): void {
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'invalid token tf-rahasia'])]);
    $hasil = Pengirim('Fonnte', [], ['Token' => 'tf-rahasia'])->Kirim(new PesanWhatsapp('0812', 'x'));
    expect($hasil->berhasil)->toBeFalse()->and($hasil->pesan)->toBe('invalid token ••••');
});
