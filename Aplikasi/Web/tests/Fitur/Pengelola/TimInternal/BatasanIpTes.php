<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Layanan\AllowlistIpPengelola;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * PGL-01 (BR-P01.2, D-146): pembatasan alamat IP konsol. Satu daftar untuk seluruh konsol; kosong = tanpa pembatasan;
 * IP pelaku wajib termasuk saat menyimpan; halaman masuk ikut dibatasi; pemulihan lewat perintah server.
 */

function SimpanDaftarIp(string $nilai): void
{
    PengaturanPlatform::query()->updateOrCreate(['Kunci' => AllowlistIpPengelola::KUNCI], ['Nilai' => $nilai]);
}

function UbahBatasanIp(string $daftar, string $alasan = 'Hanya dari jaringan kantor'): TestResponse
{
    return test()->put(BantuanPengelola::Url('/tim-internal/batasan-ip'), ['Daftar' => $daftar, 'Alasan' => $alasan]);
}

describe('Filter alamat', function (): void {
    it('mengurai alamat tunggal, CIDR IPv4/IPv6, memisah entri tidak sah, dan membuang duplikat', function (): void {
        $hasil = AllowlistIpPengelola::Urai("203.0.113.5, 10.0.0.0/8\n2001:db8::/32 203.0.113.5 \n999.1.1.1 10.0.0.0/33 abc");

        expect($hasil['Sah'])->toBe(['203.0.113.5', '10.0.0.0/8', '2001:db8::/32'])
            ->and($hasil['TidakSah'])->toBe(['999.1.1.1', '10.0.0.0/33', 'abc']);
    });

    it('mencocokkan alamat tunggal dan rentang; daftar kosong mengizinkan semua', function (): void {
        $allowlist = app(AllowlistIpPengelola::class);
        expect($allowlist->CekBoleh('8.8.8.8'))->toBeTrue()->and($allowlist->CekAktif())->toBeFalse();

        SimpanDaftarIp("203.0.113.5\n10.1.0.0/16");

        expect($allowlist->CekAktif())->toBeTrue()
            ->and($allowlist->CekBoleh('203.0.113.5'))->toBeTrue()
            ->and($allowlist->CekBoleh('10.1.200.9'))->toBeTrue()
            ->and($allowlist->CekBoleh('10.2.0.1'))->toBeFalse()
            ->and($allowlist->CekBoleh('203.0.113.6'))->toBeFalse();
    });
});

describe('Penegakan di konsol', function (): void {
    it('menolak 403 dari IP lain, termasuk halaman masuk, dan mengizinkan IP terdaftar', function (): void {
        SimpanDaftarIp('203.0.113.5');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get(BantuanPengelola::Url('/masuk'))->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get(BantuanPengelola::Url('/masuk'))->assertOk();
    });

    it('tanpa daftar, konsol terbuka dari IP mana pun', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get(BantuanPengelola::Url('/masuk'))->assertOk();
    });
});

describe('Pengaturan oleh Super Admin', function (): void {
    beforeEach(function (): void {
        $this->superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($this->superAdmin, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
    });

    it('menyimpan daftar yang memuat IP sendiri, tercatat di audit, dan halaman menampilkannya', function (): void {
        UbahBatasanIp("203.0.113.0/24\n10.1.0.0/16")->assertSessionHasNoErrors();

        expect(app(AllowlistIpPengelola::class)->Ambil())->toBe(['203.0.113.0/24', '10.1.0.0/16']);
        $log = LogAuditPengelola::query()->where('Aksi', 'tim.batasan-ip.ubah')->sole();
        expect($log->NilaiLama)->toBe(['Daftar' => []])
            ->and($log->NilaiBaru)->toBe(['Daftar' => ['203.0.113.0/24', '10.1.0.0/16']])
            ->and($log->Alasan)->toBe('Hanya dari jaringan kantor');

        $this->get(BantuanPengelola::Url('/tim-internal'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/TimInternal/Daftar')
            ->where('BatasanIp.IpAnda', '203.0.113.5')
            ->where('BatasanIp.Daftar', ['203.0.113.0/24', '10.1.0.0/16']));
    });

    it('menolak daftar yang tidak memuat IP sendiri (anti kunci diri), entri tidak sah, dan alasan kosong', function (): void {
        UbahBatasanIp('198.51.100.0/24')->assertSessionHasErrors('Daftar');
        UbahBatasanIp("203.0.113.5\nbukan-ip")->assertSessionHasErrors('Daftar');
        UbahBatasanIp('203.0.113.5', '')->assertSessionHasErrors('Alasan');
        expect(app(AllowlistIpPengelola::class)->Ambil())->toBe([])
            ->and(LogAuditPengelola::query()->where('Aksi', 'tim.batasan-ip.ubah')->count())->toBe(0);
    });

    it('daftar kosong mematikan pembatasan', function (): void {
        SimpanDaftarIp('203.0.113.5');
        UbahBatasanIp('')->assertSessionHasNoErrors();

        expect(app(AllowlistIpPengelola::class)->CekAktif())->toBeFalse();
    });

    it('perintah server mengosongkan daftar dan mencatat audit tanpa pelaku', function (): void {
        SimpanDaftarIp("198.51.100.0/24\n10.0.0.1");

        $this->artisan('pengelola:kosongkan-allowlist-ip')->assertSuccessful();

        expect(app(AllowlistIpPengelola::class)->CekAktif())->toBeFalse();
        $log = LogAuditPengelola::query()->where('Aksi', 'tim.batasan-ip.kosongkan-server')->sole();
        expect($log->IdPenggunaPengelola)->toBeNull()->and($log->NilaiLama)->toBe(['Daftar' => ['198.51.100.0/24', '10.0.0.1']]);
    });
});

it('anggota selain Super Admin tidak bisa mengubah pembatasan', function (): void {
    $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
    $this->actingAs($dukungan, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

    UbahBatasanIp('127.0.0.1')->assertForbidden();
    expect(app(AllowlistIpPengelola::class)->CekAktif())->toBeFalse();
});
