<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Layanan\PenyaringLogTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganBaru;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanAutentikasi;

/*
 * P-09: tombol Bantuan di Aplikasi Kasir dan Aplikasi Owner. Tiket otomatis membawa konteks (outlet, perangkat, versi
 * aplikasi, outbox tertunda, layar asal, log singkat yang disaring), SLA mengikuti paket, kasir hanya melihat tiketnya
 * sendiri, dan tenant lain tidak terlihat.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    Mail::fake();
    Storage::fake('local');
});

/** @return array<string, mixed> */
function IsianTiketAplikasi(array $ubah = []): array
{
    return [
        'Kategori' => 'Perangkat',
        'Prioritas' => 'Tinggi',
        'Judul' => 'Printer struk tidak mencetak',
        'Isi' => 'Sejak pagi printer Bluetooth tidak mencetak struk walau sudah dinyalakan ulang.',
        'LayarAsal' => 'Pengaturan > Printer',
        'LogSingkat' => [
            '09:01 cetak struk gagal: printer tidak tersambung',
            '09:02 coba lagi, token=abc123rahasia, hubungi 081234567890',
            'email pelanggan budi@contoh.id dan Bearer eyJhbGciOi.payload.tanda',
        ],
        ...$ubah,
    ];
}

describe('Filter log tiket', function (): void {
    it('membuang karakter kendali, menyamarkan rahasia/email/angka panjang, membatasi baris dan panjang', function (): void {
        $hasil = PenyaringLogTiket::Saring([
            "baris\x00 dengan\x07 kendali",
            'password: kunci123 dan pin=1234',
            'Authorization: Bearer abc.def-ghi',
            'kirim ke rina.w@contoh.co.id nomor +62 812-3456-7890 kartu 4111 1111 1111 1111',
            'stok 120 pcs harga 15000 tetap utuh',
            str_repeat('x', 500),
            '',
            42,
        ]);

        expect($hasil[0])->toBe('baris  dengan  kendali')
            ->and($hasil[1])->toBe('password: [rahasia] dan pin=[rahasia]')
            ->and($hasil[2])->toBe('Authorization: [rahasia]')
            ->and($hasil[3])->toBe('kirim ke [email] nomor [angka] kartu [angka]')
            ->and($hasil[4])->toBe('stok 120 pcs harga 15000 tetap utuh')
            ->and(mb_strlen($hasil[5]))->toBe(200)
            ->and($hasil)->toHaveCount(6)
            ->and(PenyaringLogTiket::Saring(array_fill(0, 40, 'baris')))->toHaveCount(20);
    });
});

describe('Aplikasi Kasir', function (): void {
    it('staf outlet membuat tiket: konteks perangkat otomatis, log difilter, SLA paket, tim Dukungan diberi tahu, staf hanya melihat tiketnya', function (): void {
        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Bantuan Kasir', 'pemilik@bantuankasir.id');
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $kasirLain = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id, nama: 'Kasir Depan Sragen');
        $perangkat->forceFill(['JumlahOutboxTertunda' => 3, 'OutboxTertundaSejak' => now()->subHours(3)])->save();

        $respons = $this->withToken($token)->withHeader('X-Versi-Aplikasi', '1.0.0+7')
            ->postJson('/api/pos/v1/bantuan/tiket', IsianTiketAplikasi(['UuidPengguna' => $kasir->Uuid]))->assertCreated();

        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = TiketDukungan::query()->sole();
        expect($respons->json('Tiket.Uuid'))->toBe($tiket->Uuid)
            ->and($respons->json('Tiket.Status'))->toBe('Baru')
            ->and($tiket->Kanal)->toBe(KanalTiketDukungan::AplikasiKasir)
            ->and($tiket->IdPelapor)->toBe($kasir->Id)
            ->and($tiket->JamSla)->toBe(8)
            ->and($tiket->Konteks)->toMatchArray([
                'Aplikasi' => 'Aplikasi Kasir',
                'Versi aplikasi' => '1.0.0+7',
                'Staf pelapor' => $kasir->Nama,
                'Layar asal' => 'Pengaturan > Printer',
                'Outbox tertunda' => '3 transaksi sejak 24-09-2026 06:00 WIB',
            ])
            ->and($tiket->Konteks['Perangkat'])->toContain('Kasir Depan Sragen')
            ->and($tiket->Konteks['Outlet'])->toBeString()->not->toBe('')
            ->and($tiket->Konteks['Log singkat'])->toContain('[rahasia]')->toContain('[email]')->toContain('[angka]')
            ->not->toContain('abc123rahasia')->not->toContain('081234567890')->not->toContain('budi@contoh.id');
        Mail::assertSent(TiketDukunganBaru::class, fn (TiketDukunganBaru $surel) => $surel->hasTo($dukungan->Email));

        // Staf lain tidak melihat tiket ini; staf pelapor melihatnya, lengkap dengan percakapan.
        $this->withToken($token)->getJson("/api/pos/v1/bantuan/tiket?UuidPengguna={$kasirLain->Uuid}")->assertOk()->assertJsonPath('Tiket', []);
        $this->withToken($token)->getJson("/api/pos/v1/bantuan/tiket/{$tiket->Uuid}?UuidPengguna={$kasirLain->Uuid}")->assertNotFound();
        $daftar = $this->withToken($token)->getJson("/api/pos/v1/bantuan/tiket?UuidPengguna={$kasir->Uuid}")->assertOk()->json('Tiket');
        expect($daftar)->toHaveCount(1)->and($daftar[0])->toMatchArray(['Nomor' => $tiket->Nomor, 'Judul' => 'Printer struk tidak mencetak', 'LewatSla' => false]);
        $detail = $this->withToken($token)->getJson("/api/pos/v1/bantuan/tiket/{$tiket->Uuid}?UuidPengguna={$kasir->Uuid}")->assertOk()->json('Tiket');
        expect($detail['Pesan'])->toHaveCount(1)->and($detail['Pesan'][0]['Isi'])->toContain('printer Bluetooth')->and($detail['BisaDibalas'])->toBeTrue();

        // Balasan hanya dari staf pelapor.
        $this->withToken($token)->postJson("/api/pos/v1/bantuan/tiket/{$tiket->Uuid}/balas", ['UuidPengguna' => $kasirLain->Uuid, 'Isi' => 'Saya juga mengalaminya.'])->assertNotFound();
        $this->withToken($token)->postJson("/api/pos/v1/bantuan/tiket/{$tiket->Uuid}/balas", ['UuidPengguna' => $kasir->Uuid, 'Isi' => 'Printer sudah dicoba di tablet lain.'])
            ->assertOk()->assertJsonCount(2, 'Tiket.Pesan');
    });

    it('menolak staf yang bukan anggota outlet, UuidPengguna kosong, dan isian tidak sah; perangkat tanpa token ditolak', function (): void {
        ['Tenant' => $tenant] = BantuanDukungan::BuatTenant('Toko Bantuan Ditolak', 'pemilik@bantuanditolak.id');
        ['Tenant' => $lain, 'Pengguna' => $pemilikLain] = BantuanDukungan::BuatTenant('Toko Lain Bantuan', 'pemilik@lainbantuan.id');
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        $this->postJson('/api/pos/v1/bantuan/tiket', IsianTiketAplikasi(['UuidPengguna' => $pemilikLain->Uuid]))->assertUnauthorized();
        $this->withToken($token)->postJson('/api/pos/v1/bantuan/tiket', IsianTiketAplikasi(['UuidPengguna' => $pemilikLain->Uuid]))->assertForbidden();
        $this->withToken($token)->postJson('/api/pos/v1/bantuan/tiket', IsianTiketAplikasi())->assertUnprocessable();
        $this->withToken($token)->getJson('/api/pos/v1/bantuan/tiket')->assertUnprocessable();
        $this->withToken($token)->postJson('/api/pos/v1/bantuan/tiket', IsianTiketAplikasi(['UuidPengguna' => '01K5PRD000000000AMER1CAN01', 'Judul' => 'x', 'Kategori' => 'Salah']))->assertUnprocessable();

        app(KonteksTenant::class)->Atur($lain->Id);
        expect(TiketDukungan::query()->count())->toBe(0);
    });

    it('tiket tenant lain tidak terlihat (isolasi tenant)', function (): void {
        ['Tenant' => $tenantA, 'Pengguna' => $pemilikA] = BantuanDukungan::BuatTenant('Toko A Bantuan', 'pemilik@abantuan.id');
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanDukungan::BuatTenant('Toko B Bantuan', 'pemilik@bbantuan.id');
        ['Token' => $tokenA] = BantuanPerangkat::BuatDanAktifkan($this, $tenantA->Id);
        $tiketB = BantuanDukungan::BuatTiket($tenantB, $pemilikB);

        $this->withToken($tokenA)->getJson("/api/pos/v1/bantuan/tiket/{$tiketB->Uuid}?UuidPengguna={$pemilikA->Uuid}")->assertNotFound();
        $this->withToken($tokenA)->postJson("/api/pos/v1/bantuan/tiket/{$tiketB->Uuid}/balas", ['UuidPengguna' => $pemilikA->Uuid, 'Isi' => 'Coba balas tiket orang lain.'])->assertNotFound();
        $this->withToken($tokenA)->getJson("/api/pos/v1/bantuan/tiket?UuidPengguna={$pemilikA->Uuid}")->assertOk()->assertJsonPath('Tiket', []);
    });
});

describe('Aplikasi Owner', function (): void {
    it('Owner membuat tiket dengan konteks aplikasi, melihat semua tiket tenantnya, dan membalas', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Bantuan Owner', 'pemilik@bantuanowner.id');
        $pemilik->forceFill(['EmailDiverifikasiPada' => now()])->save();
        $tiketWeb = BantuanDukungan::BuatTiket($tenant, $pemilik, judul: 'Tagihan perpanjangan belum terbit');
        $token = (string) $this->withHeader('X-Versi-Aplikasi', '1.0.0')->postJson('/api/pemilik/v1/masuk', [
            'Email' => $pemilik->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'Pixel Owner',
        ])->assertOk()->json('Token');
        $api = fn () => $this->withToken($token)->withHeaders(['X-Tenant' => $tenant->Uuid, 'X-Versi-Aplikasi' => '1.2.0', 'X-Platform-Aplikasi' => 'Ios']);

        $respons = $api()->postJson('/api/pemilik/v1/bantuan/tiket', IsianTiketAplikasi(['Prioritas' => null, 'Kategori' => 'Laporan', 'Judul' => 'Laporan omzet tidak sesuai']))->assertCreated();

        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = TiketDukungan::query()->where('Uuid', $respons->json('Tiket.Uuid'))->sole();
        expect($tiket->Kanal)->toBe(KanalTiketDukungan::AplikasiPemilik)
            ->and($tiket->Prioritas->value)->toBe('Normal')
            ->and($tiket->IdPelapor)->toBe($pemilik->Id)
            ->and($tiket->Konteks)->toMatchArray(['Aplikasi' => 'Aplikasi Owner', 'Platform' => 'Ios', 'Versi aplikasi' => '1.2.0', 'Layar asal' => 'Pengaturan > Printer'])
            ->and($tiket->Konteks['Log singkat'])->not->toContain('abc123rahasia');

        $daftar = $api()->getJson('/api/pemilik/v1/bantuan/tiket')->assertOk()->json('Tiket');
        expect(array_column($daftar, 'Judul'))->toEqualCanonicalizing(['Tagihan perpanjangan belum terbit', 'Laporan omzet tidak sesuai']);

        $api()->getJson("/api/pemilik/v1/bantuan/tiket/{$tiketWeb->Uuid}")->assertOk()->assertJsonPath('Tiket.Nomor', $tiketWeb->Nomor);
        $api()->postJson("/api/pemilik/v1/bantuan/tiket/{$tiket->Uuid}/balas", ['Isi' => 'Berikut contoh tanggal yang berbeda.'])->assertOk()->assertJsonCount(2, 'Tiket.Pesan');
        $api()->postJson("/api/pemilik/v1/bantuan/tiket/{$tiket->Uuid}/balas", ['Isi' => 'x'])->assertUnprocessable();
        $this->flushHeaders();
        $this->getJson('/api/pemilik/v1/bantuan/tiket')->assertUnauthorized();
    });

    it('tiket tenant lain tidak bisa dibuka Owner tenant ini', function (): void {
        ['Tenant' => $tenantA, 'Pengguna' => $pemilikA] = BantuanDukungan::BuatTenant('Toko Owner A', 'pemilik@ownera.id');
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanDukungan::BuatTenant('Toko Owner B', 'pemilik@ownerb.id');
        $pemilikA->forceFill(['EmailDiverifikasiPada' => now()])->save();
        $tiketB = BantuanDukungan::BuatTiket($tenantB, $pemilikB);
        $token = (string) $this->withHeader('X-Versi-Aplikasi', '1.0.0')->postJson('/api/pemilik/v1/masuk', [
            'Email' => $pemilikA->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'Pixel Owner',
        ])->assertOk()->json('Token');

        $this->withToken($token)->withHeader('X-Tenant', $tenantA->Uuid)->getJson("/api/pemilik/v1/bantuan/tiket/{$tiketB->Uuid}")->assertNotFound();
        $this->withToken($token)->withHeader('X-Tenant', $tenantA->Uuid)->getJson('/api/pemilik/v1/bantuan/tiket')->assertOk()->assertJsonPath('Tiket', []);
    });
});
