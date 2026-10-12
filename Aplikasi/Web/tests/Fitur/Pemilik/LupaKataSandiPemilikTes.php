<?php

declare(strict_types=1);

use App\Domain\Organisasi\Surel\TautanAturUlangKataSandi;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Http\Kontroler\Autentikasi\LupaKataSandiKontroler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * OWN-01 lupa kata sandi di Aplikasi Owner: tautan atur ulang dikirim ke email seperti di web (BR-00.9). Jawabannya
 * selalu sama, terdaftar atau tidak (§25 no. 18), dan batas per IP/email dipakai bersama dengan halaman web.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
});

describe('POST /api/pemilik/v1/lupa-kata-sandi', function (): void {
    it('email terdaftar dan tidak terdaftar mendapat jawaban yang sama; tautan hanya dikirim ke akun yang ada', function (): void {
        $terdaftar = $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => 'RINA@kopinusantara.id']);
        $tidakAda = $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => 'tidak-ada@contoh.id']);

        foreach ([$terdaftar, $tidakAda] as $respons) {
            $respons->assertOk()->assertExactJson(['Pesan' => LupaKataSandiKontroler::PESAN_TERKIRIM]);
        }

        Mail::assertQueued(TautanAturUlangKataSandi::class, 1);
        Mail::assertQueued(TautanAturUlangKataSandi::class, fn (TautanAturUlangKataSandi $surel) => $surel->hasTo('rina@kopinusantara.id'));
    });

    it('email wajib diisi dan berbentuk email', function (): void {
        $this->postJson('/api/pemilik/v1/lupa-kata-sandi', [])->assertUnprocessable();
        $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => 'bukan-email'])->assertUnprocessable();
        Mail::assertNothingQueued();
    });

    it('permintaan ke-4 untuk email yang sama dalam sejam tidak mengirim tautan lagi tetapi jawabannya tetap sama; IP yang terlalu sering ditolak 429', function (): void {
        foreach (range(1, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM + 1) as $_) {
            $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => 'rina@kopinusantara.id'])->assertOk();
        }
        // Broker sandi juga menahan permintaan beruntun, jadi yang terkirim paling banyak sebatas kuota email per jam.
        expect(Mail::queued(TautanAturUlangKataSandi::class)->count())->toBeLessThanOrEqual(LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_EMAIL_PER_JAM);

        foreach (range(1, LupaKataSandiKontroler::BATAS_PERMINTAAN_PER_IP_PER_JAM) as $i) {
            $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => "orang{$i}@contoh.id"]);
        }
        $this->postJson('/api/pemilik/v1/lupa-kata-sandi', ['Email' => 'lain@contoh.id'])
            ->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPercobaan');
    });
});
