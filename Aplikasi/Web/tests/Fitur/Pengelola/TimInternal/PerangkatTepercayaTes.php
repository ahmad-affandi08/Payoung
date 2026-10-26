<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Layanan\PenjagaPerangkatTepercaya;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Pengelola\TimInternal\Model\PerangkatTepercayaPengelola;
use App\Http\Perantara\Pengelola\SesiPengelola;
use Database\Pabrik\PenggunaPengelolaPabrik;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

function CookiePerangkat(): string
{
    return (string) config('pengelola.CookiePerangkatTepercaya');
}

function TerbitkanPerangkat(PenggunaPengelola $pengguna): string
{
    return app(PenjagaPerangkatTepercaya::class)->Terbitkan($pengguna, 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36', '10.0.0.1');
}

describe('Perangkat tepercaya konsol (BR-P01.2, D-42)', function (): void {
    it('mencentang "Percayai perangkat ini" saat verifikasi menerbitkan cookie 90 hari dan hanya hash token yang disimpan', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession([SesiPengelola::TERAKHIR_AKTIF => now()->getTimestamp()]);

        $respons = $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit Safari/605.1.15')
            ->post(BantuanPengelola::Url('/dua-faktor/verifikasi'), ['Kode' => 'AAAAA-BBBBB', 'PercayaiPerangkat' => true]);

        $respons->assertRedirect(route('pengelola.beranda'))->assertCookie(CookiePerangkat());
        $nilai = (string) $respons->getCookie(CookiePerangkat())?->getValue();
        $perangkat = PerangkatTepercayaPengelola::query()->sole();

        expect($perangkat->IdPenggunaPengelola)->toBe($pengguna->Id)
            ->and($perangkat->Keterangan)->toBe('Safari di macOS')
            ->and($perangkat->BerlakuSampai->diffInDays(now()->addDays(90)))->toBeLessThan(1.0)
            ->and($nilai)->not->toContain($perangkat->HashToken)
            ->and($respons->getCookie(CookiePerangkat())?->getExpiresTime())->toBeGreaterThan(now()->addDays(89)->getTimestamp());
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'keamanan.perangkat-tepercaya.tambah', 'IdPenggunaPengelola' => $pengguna->Id]);
    });

    it('tanpa centang tidak ada perangkat tepercaya yang dicatat', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession([SesiPengelola::TERAKHIR_AKTIF => now()->getTimestamp()]);

        $this->post(BantuanPengelola::Url('/dua-faktor/verifikasi'), ['Kode' => 'AAAAA-BBBBB'])
            ->assertRedirect(route('pengelola.beranda'))
            ->assertCookieMissing(CookiePerangkat());
        expect(PerangkatTepercayaPengelola::query()->count())->toBe(0);
    });

    it('login dari perangkat tepercaya cukup kata sandi, tanpa cookie tetap diminta kode', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $nilai = TerbitkanPerangkat($pengguna);
        $this->travel(3)->days();

        $this->withCookie(CookiePerangkat(), $nilai)
            ->post(BantuanPengelola::Url('/masuk'), ['Email' => $pengguna->Email, 'KataSandi' => PenggunaPengelolaPabrik::KATA_SANDI])
            ->assertRedirect(route('pengelola.beranda'));
        $this->get(BantuanPengelola::Url('/tim-internal'))->assertOk();

        $perangkat = PerangkatTepercayaPengelola::query()->sole();
        expect($perangkat->TerakhirDipakaiPada?->isToday())->toBeTrue();
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'sesi.masuk-perangkat-tepercaya', 'IdPenggunaPengelola' => $pengguna->Id]);

    });

    it('cookie yang dicabut, kedaluwarsa, salah token, atau milik akun lain tidak melewati 2FA dan dihapus', function (string $keadaan): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $lain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        $nilai = TerbitkanPerangkat($keadaan === 'akun lain' ? $lain : $pengguna);
        $perangkat = PerangkatTepercayaPengelola::query()->sole();

        match ($keadaan) {
            'dicabut' => $perangkat->forceFill(['DicabutPada' => now()])->save(),
            'kedaluwarsa' => $this->travel(91)->days(),
            'salah token' => $nilai = $perangkat->Uuid.'.'.str_repeat('x', 64),
            default => null,
        };

        $this->withCookie(CookiePerangkat(), $nilai)
            ->post(BantuanPengelola::Url('/masuk'), ['Email' => $pengguna->Email, 'KataSandi' => PenggunaPengelolaPabrik::KATA_SANDI])
            ->assertRedirect(route('pengelola.beranda'))
            ->assertCookieExpired(CookiePerangkat());
        $this->get(BantuanPengelola::Url('/tim-internal'))->assertRedirect(route('pengelola.dua-faktor.verifikasi'));
    })->with(['dicabut', 'kedaluwarsa', 'salah token', 'akun lain']);

    it('ganti kata sandi dan nonaktifkan akun mencabut semua perangkat tepercaya', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        TerbitkanPerangkat($pengguna);
        TerbitkanPerangkat($pengguna);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->post(BantuanPengelola::Url('/ganti-kata-sandi'), [
            'KataSandiLama' => PenggunaPengelolaPabrik::KATA_SANDI,
            'KataSandi' => 'SandiBaru67890',
            'KonfirmasiKataSandi' => 'SandiBaru67890',
        ]);
        expect(PerangkatTepercayaPengelola::query()->whereNull('DicabutPada')->count())->toBe(0);

        $penyelia = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $anggota = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        TerbitkanPerangkat($anggota);
        $this->actingAs($penyelia, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->post(BantuanPengelola::Url("/tim-internal/{$anggota->Uuid}/nonaktifkan"), ['Alasan' => 'Keluar dari perusahaan'])
            ->assertSessionHasNoErrors();
        expect(PerangkatTepercayaPengelola::query()->where('IdPenggunaPengelola', $anggota->Id)->whereNull('DicabutPada')->count())->toBe(0);
    });

    it('batas 10 perangkat aktif per akun: yang tertua dicabut', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        foreach (range(1, PenjagaPerangkatTepercaya::MAKS_PERANGKAT_AKTIF + 2) as $_) {
            TerbitkanPerangkat($pengguna);
        }

        $aktif = PerangkatTepercayaPengelola::query()->whereNull('DicabutPada')->orderBy('Id')->pluck('Id');
        expect($aktif)->toHaveCount(PenjagaPerangkatTepercaya::MAKS_PERANGKAT_AKTIF)
            ->and($aktif->first())->toBe(PerangkatTepercayaPengelola::query()->min('Id') + 2);
    });

    it('meringkas peramban dari User-Agent', function (?string $agen, string $harapan): void {
        expect(PenjagaPerangkatTepercaya::RingkasPeramban($agen))->toBe($harapan);
    })->with([
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge di Windows'],
        ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36', 'Chrome di Android'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit Version/17.0 Mobile Safari/604.1', 'Safari di iPhone'],
        ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox di Linux'],
        [null, 'Peramban'],
    ]);
});

describe('Konfirmasi kode untuk aksi berbahaya (D-42)', function (): void {
    it('masuk lewat perangkat tepercaya lalu mengundang anggota diminta kode dulu, lalu kembali ke halaman asal', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiPerangkatTepercaya());

        $this->from(BantuanPengelola::Url('/tim-internal'))
            ->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => 'baru@contoh.id', 'KodePeran' => ['Analis']])
            ->assertRedirect(route('pengelola.dua-faktor.konfirmasi'));
        $this->assertDatabaseCount('UndanganPengelola', 0);

        $this->get(BantuanPengelola::Url('/dua-faktor/konfirmasi'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/DuaFaktor/Verifikasi')
                ->where('Konfirmasi', true)
                ->where('MenitKonfirmasi', 15));

        $this->post(BantuanPengelola::Url('/dua-faktor/konfirmasi'), ['Kode' => '000000'])->assertSessionHasErrors('Kode');
        $this->post(BantuanPengelola::Url('/dua-faktor/konfirmasi'), ['Kode' => 'AAAAA-BBBBB'])
            ->assertRedirect(BantuanPengelola::Url('/tim-internal'))
            ->assertSessionHas('Kilat');
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'sesi.dua-faktor.konfirmasi-kode-pemulihan', 'IdPenggunaPengelola' => $pengguna->Id]);

        $this->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => 'baru@contoh.id', 'KodePeran' => ['Analis']])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('UndanganPengelola', 1);
    });

    it('kode yang dimasukkan lebih dari 15 menit lalu diminta lagi. Menu biasa tidak terpengaruh', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->travel(16)->minutes();
        $this->withSession([SesiPengelola::TERAKHIR_AKTIF => now()->getTimestamp()]);

        $this->get(BantuanPengelola::Url('/tim-internal'))->assertOk();
        $this->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => 'baru@contoh.id', 'KodePeran' => ['Analis']])
            ->assertRedirect(route('pengelola.dua-faktor.konfirmasi'));
        $this->post(BantuanPengelola::Url('/integrasi'), [])
            ->assertRedirect(route('pengelola.dua-faktor.konfirmasi'));
    });

    it('simpan dari halaman Inertia atau JSON dijawab 423 tanpa mengalihkan halaman, lalu lolos setelah kode dikonfirmasi lewat JSON', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiPerangkatTepercaya());
        $isi = ['Email' => 'baru@contoh.id', 'KodePeran' => ['Analis']];

        // Permintaan Inertia (form halaman): dialog kode muncul di tempat, isian tidak hilang.
        $this->post(BantuanPengelola::Url('/tim-internal/undangan'), $isi, ['X-Inertia' => 'true'])
            ->assertStatus(423)
            ->assertJsonPath('Galat.Kode', 'PerluKonfirmasiDuaFaktor');
        $this->postJson(BantuanPengelola::Url('/tim-internal/undangan'), $isi)
            ->assertStatus(423)
            ->assertJsonPath('Galat.Kode', 'PerluKonfirmasiDuaFaktor');
        $this->assertDatabaseCount('UndanganPengelola', 0);

        $this->postJson(BantuanPengelola::Url('/dua-faktor/konfirmasi'), ['Kode' => '000000'])->assertUnprocessable();
        $this->postJson(BantuanPengelola::Url('/dua-faktor/konfirmasi'), ['Kode' => 'AAAAA-BBBBB'])
            ->assertOk()
            ->assertJsonPath('Berhasil', true);

        $this->post(BantuanPengelola::Url('/tim-internal/undangan'), $isi, ['X-Inertia' => 'true'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('UndanganPengelola', 1);
    });

    it('alamat kembali di luar host konsol tidak diikuti', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiPerangkatTepercaya() + [
            SesiPengelola::KEMBALI_SETELAH_KONFIRMASI => 'https://jahat.contoh/curi',
        ]);

        $this->post(BantuanPengelola::Url('/dua-faktor/konfirmasi'), ['Kode' => 'AAAAA-BBBBB'])
            ->assertRedirect(route('pengelola.beranda'));
    });
});

describe('Halaman Keamanan akun konsol (D-42)', function (): void {
    it('menampilkan perangkat milik sendiri, menandai perangkat ini, dan mencabutnya', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        $lain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        $nilaiIni = TerbitkanPerangkat($pengguna);
        TerbitkanPerangkat($pengguna);
        TerbitkanPerangkat($lain);
        $uuidLain = PerangkatTepercayaPengelola::query()->where('IdPenggunaPengelola', $lain->Id)->value('Uuid');
        $uuidIni = PenjagaPerangkatTepercaya::AmbilUuid($nilaiIni);

        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiPerangkatTepercaya());
        $this->withCookie(CookiePerangkat(), $nilaiIni)
            ->get(BantuanPengelola::Url('/keamanan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Keamanan')
                ->has('Perangkat', 2)
                ->where('HariPerangkatTepercaya', 90)
                ->where('Perangkat', fn ($daftar) => collect($daftar)->where('PerangkatIni', true)->pluck('Uuid')->all() === [$uuidIni]));

        $this->delete(BantuanPengelola::Url("/keamanan/perangkat/{$uuidLain}"))->assertNotFound();

        $this->withCookie(CookiePerangkat(), $nilaiIni)
            ->delete(BantuanPengelola::Url("/keamanan/perangkat/{$uuidIni}"))
            ->assertRedirect()
            ->assertCookieExpired(CookiePerangkat());
        expect(PerangkatTepercayaPengelola::query()->where('Uuid', $uuidIni)->value('DicabutPada'))->not->toBeNull();

        $this->post(BantuanPengelola::Url('/keamanan/perangkat/cabut-semua'))->assertRedirect();
        expect(PerangkatTepercayaPengelola::query()->where('IdPenggunaPengelola', $pengguna->Id)->whereNull('DicabutPada')->count())->toBe(0)
            ->and(PerangkatTepercayaPengelola::query()->where('Uuid', $uuidLain)->value('DicabutPada'))->toBeNull();
    });

    it('Super Admin mencabut perangkat tepercaya anggota dari Tim internal. Anggota biasa tidak boleh', function (): void {
        $super = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $anggota = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        TerbitkanPerangkat($anggota);

        $this->actingAs($anggota, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->post(BantuanPengelola::Url("/tim-internal/{$super->Uuid}/cabut-perangkat"))->assertForbidden();

        $this->actingAs($super, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->get(BantuanPengelola::Url('/tim-internal'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Anggota', fn ($daftar) => collect($daftar)->firstWhere('Uuid', $anggota->Uuid)['JumlahPerangkatTepercaya'] === 1));
        $this->post(BantuanPengelola::Url("/tim-internal/{$anggota->Uuid}/cabut-perangkat"))->assertSessionHas('Kilat');

        expect(PerangkatTepercayaPengelola::query()->whereNull('DicabutPada')->count())->toBe(0);
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'keamanan.perangkat-tepercaya.cabut-semua', 'IdPenggunaPengelola' => $super->Id]);
    });
});
