<?php

declare(strict_types=1);

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pengelola\Konten\Kueri\DaftarProspekSitus;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Situs\Aksi\TerimaProspekSitus;
use App\Domain\Situs\Enum\JenisProspek;
use App\Domain\Situs\Enum\StatusProspek;
use App\Domain\Situs\Kueri\PengaturanSitusBerlaku;
use App\Domain\Situs\Layanan\AturanSlugSitus;
use App\Domain\Situs\Model\PengaturanSitus;
use App\Domain\Situs\Model\ProspekSitus;
use App\Domain\Situs\Surel\ProspekSitusBaru;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * Situs pemasaran bagian B: formulir kontak/minta demo (prospek) — persetujuan wajib, perangkap bot, nomor terenkripsi,
 * batas per nomor, email ke tim; konsol daftar & tindak lanjut; retensi; pengaturan analitik (cookie consent).
 */

/** @return array<string, mixed> */
function IsianProspek(array $timpa = []): array
{
    return [
        'Jenis' => 'Demo',
        'Nama' => 'Sari Wulandari',
        'NamaUsaha' => 'Kopi Senja',
        'NoHp' => '0812-3456-7890',
        'Email' => 'Sari@Contoh.id',
        'JenisUsaha' => 'Kafe',
        'Kota' => 'Surakarta',
        'Pesan' => 'Ingin lihat mode meja.',
        'HalamanAsal' => '/kontak',
        'Setuju' => '1',
        'Situs' => '',
        ...$timpa,
    ];
}

function SimpanPengaturanSitusUji(array $nilai): void
{
    PengaturanSitus::query()->updateOrCreate(['Kunci' => PengaturanSitus::KUNCI_UMUM], ['Nilai' => $nilai]);
}

describe('formulir prospek publik', function (): void {
    it('menyimpan prospek dengan nomor ternormalisasi & terenkripsi, lalu mengabari tim lewat email', function (): void {
        Mail::fake();
        SimpanPengaturanSitusUji(['Prospek' => ['EmailNotifikasi' => 'sales@payoung.test']]);

        $this->from('/kontak')->post('/prospek', IsianProspek())
            ->assertRedirect('/kontak')
            ->assertSessionHas('ProspekTerkirim', true);

        $p = ProspekSitus::query()->sole();
        expect($p->NoHp)->toBe('6281234567890')
            ->and($p->Email)->toBe('sari@contoh.id')
            ->and($p->Status)->toBe(StatusProspek::Baru)
            ->and($p->HalamanAsal)->toBe('/kontak')
            ->and($p->PersetujuanPada)->not->toBeNull();

        // Nomor & email tidak tersimpan mentah; IP hanya sebagai sidik.
        $mentah = (array) DB::table('ProspekSitus')->first();
        expect((string) $mentah['NoHp'])->not->toContain('6281234567890')
            ->and((string) $mentah['Email'])->not->toContain('contoh.id')
            ->and($mentah['SidikIp'])->toHaveLength(64);

        Mail::assertQueued(ProspekSitusBaru::class, fn (ProspekSitusBaru $surel): bool => $surel->hasTo('sales@payoung.test'));
    });

    it('email notifikasi jatuh ke email kontak situs, tanpa keduanya tidak mengirim email', function (): void {
        Mail::fake();
        $this->post('/prospek', IsianProspek())->assertSessionHas('ProspekTerkirim', true);
        Mail::assertNothingQueued();

        SimpanPengaturanSitusUji(['Kontak' => ['Email' => 'halo@payoung.test']]);
        $this->post('/prospek', IsianProspek(['NoHp' => '081299998888']));
        Mail::assertQueued(ProspekSitusBaru::class, fn (ProspekSitusBaru $surel): bool => $surel->hasTo('halo@payoung.test'));
    });

    it('isi email tim tidak memuat nomor & email pengunjung, dan menaut ke konsol', function (): void {
        config(['pengelola.Domain' => 'konsol.payoung.test']);
        $p = app(TerimaProspekSitus::class)->Jalankan([
            'Jenis' => JenisProspek::Kontak, 'Nama' => 'Budi', 'NamaUsaha' => null, 'NoHp' => '08123000111',
            'Email' => 'budi@contoh.id', 'JenisUsaha' => null, 'Kota' => null, 'Pesan' => null, 'HalamanAsal' => null,
        ], '10.0.0.1');

        Mail::fake();
        app(TerimaProspekSitus::class)->Jalankan([
            'Jenis' => JenisProspek::Kontak, 'Nama' => 'Budi', 'NamaUsaha' => null, 'NoHp' => '08123000222',
            'Email' => 'budi@contoh.id', 'JenisUsaha' => null, 'Kota' => null, 'Pesan' => null, 'HalamanAsal' => null,
        ], '10.0.0.1');
        $isi = '';
        Mail::assertNothingQueued();
        SimpanPengaturanSitusUji(['Kontak' => ['Email' => 'halo@payoung.test']]);
        app(TerimaProspekSitus::class)->Jalankan([
            'Jenis' => JenisProspek::Kontak, 'Nama' => 'Budi', 'NamaUsaha' => null, 'NoHp' => '08123000333',
            'Email' => 'budi@contoh.id', 'JenisUsaha' => null, 'Kota' => null, 'Pesan' => null, 'HalamanAsal' => null,
        ], '10.0.0.1');
        Mail::assertQueued(ProspekSitusBaru::class, function (ProspekSitusBaru $surel) use (&$isi): bool {
            $isi = $surel->render();

            return true;
        });
        expect($p->Nama)->toBe('Budi');
        expect($isi)->toContain('Budi')->toContain('konsol.payoung.test/situs/prospek')
            ->not->toContain('628123000111')->not->toContain('budi@contoh.id');
    });

    it('persetujuan wajib, nomor wajib valid', function (): void {
        $this->post('/prospek', IsianProspek(['Setuju' => '0']))->assertSessionHasErrors('Setuju');
        $this->post('/prospek', IsianProspek(['NoHp' => 'bukan nomor']))->assertSessionHasErrors('NoHp');
        $this->post('/prospek', IsianProspek(['Nama' => '']))->assertSessionHasErrors('Nama');
        expect(ProspekSitus::query()->count())->toBe(0);
    });

    it('perangkap bot terisi: tampak berhasil tetapi tidak disimpan', function (): void {
        Mail::fake();
        $this->post('/prospek', IsianProspek(['Situs' => 'https://spam.example']))->assertSessionHas('ProspekTerkirim', true);
        expect(ProspekSitus::query()->count())->toBe(0);
        Mail::assertNothingQueued();
    });

    it('paling banyak 3 prospek per nomor per 24 jam (format nomor berbeda tetap dikenali)', function (): void {
        foreach (['081234567890', '+62 812 3456 7890', '6281234567890'] as $no) {
            app(TerimaProspekSitus::class)->Jalankan([
                'Jenis' => JenisProspek::Kontak, 'Nama' => 'A', 'NamaUsaha' => null, 'NoHp' => $no,
                'Email' => null, 'JenisUsaha' => null, 'Kota' => null, 'Pesan' => null, 'HalamanAsal' => null,
            ], '10.0.0.1');
        }

        $this->post('/prospek', IsianProspek(['NoHp' => '0812 3456 7890']))->assertSessionHasErrors('NoHp');
        expect(ProspekSitus::query()->count())->toBe(3);

        $this->travel(25)->hours();
        $this->post('/prospek', IsianProspek(['NoHp' => '0812 3456 7890']))->assertSessionHas('ProspekTerkirim', true);
        expect(ProspekSitus::query()->count())->toBe(4);
    });

    it('halaman asal hanya jalur relatif situs (query & domain dibuang)', function (): void {
        $this->post('/prospek', IsianProspek(['HalamanAsal' => 'https://lain.example/x?utm=1']));
        expect(ProspekSitus::query()->sole()->HalamanAsal)->toBeNull();
    });

    it('jalur prospek & blog tidak bisa dipakai slug halaman situs', function (): void {
        expect(AturanSlugSitus::Periksa('prospek'))->not->toBeNull()
            ->and(AturanSlugSitus::Periksa('blog'))->not->toBeNull();
    });
});

describe('konsol prospek', function (): void {
    it('Konten & Legal melihat daftar lengkap; Dukungan ditolak', function (): void {
        $this->post('/prospek', IsianProspek());

        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->get(BantuanPengelola::Url('/situs/prospek'))->assertForbidden();

        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->get(BantuanPengelola::Url('/situs/prospek'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Situs/Prospek')
            ->where('Izin.Kelola', true)
            ->where('Prospek.Data.0.Nama', 'Sari Wulandari')
            ->where('Prospek.Data.0.NoHp', '6281234567890')
            ->where('Prospek.Data.0.Email', 'sari@contoh.id'));
    });

    it('tanpa izin kelola: nomor & email disamarkan', function (): void {
        $this->post('/prospek', IsianProspek());
        $hasil = app(DaftarProspekSitus::class)->AmbilTabel(DataPermintaanTabel::Dari([], DaftarProspekSitus::KOLOM_URUT, '', DaftarProspekSitus::KOLOM_SARING), false);

        expect($hasil['Data'][0]['NoHp'])->not->toBe('6281234567890')->toContain('*')
            ->and($hasil['Data'][0]['Email'])->toBe('s***@contoh.id');
    });

    it('cari dengan nomor HP (format apa pun) & bawaan menyembunyikan Spam', function (): void {
        $this->post('/prospek', IsianProspek());
        $this->post('/prospek', IsianProspek(['Nama' => 'Bot', 'NoHp' => '081111111111']));
        ProspekSitus::query()->where('Nama', 'Bot')->update(['Status' => StatusProspek::Spam->value]);

        $daftar = app(DaftarProspekSitus::class);
        $tabel = fn (array $q) => $daftar->AmbilTabel(DataPermintaanTabel::Dari($q, DaftarProspekSitus::KOLOM_URUT, '', DaftarProspekSitus::KOLOM_SARING), true);

        expect($tabel([])['Meta']['Total'])->toBe(1)
            ->and($tabel(['saring' => ['Status' => 'Spam']])['Data'][0]['Nama'])->toBe('Bot')
            ->and($tabel(['cari' => '+62 812-3456-7890'])['Data'][0]['Nama'])->toBe('Sari Wulandari')
            ->and($tabel(['cari' => 'senja'])['Meta']['Total'])->toBe(1);
    });

    it('ubah status mencatat penangan pertama & audit (tanpa nomor)', function (): void {
        $this->post('/prospek', IsianProspek());
        $p = ProspekSitus::query()->sole();

        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->put(BantuanPengelola::Url("/situs/prospek/{$p->Uuid}"), ['Status' => 'Dihubungi', 'Catatan' => 'Demo Kamis 10.00'])
            ->assertSessionHasNoErrors();

        $p->refresh();
        expect($p->Status)->toBe(StatusProspek::Dihubungi)
            ->and($p->Catatan)->toBe('Demo Kamis 10.00')
            ->and($p->IdPenggunaPengelolaPenangan)->toBe($pengguna->Id)
            ->and($p->DitanganiPada)->not->toBeNull();

        $audit = LogAuditPengelola::query()->where('Aksi', 'situs.prospek.ubah')->sole();
        expect(json_encode($audit->toArray()))->not->toContain('6281234567890');

        $this->put(BantuanPengelola::Url("/situs/prospek/{$p->Uuid}"), ['Status' => 'Tidak ada'])->assertSessionHasErrors('Status');
    });
});

describe('retensi & analitik', function (): void {
    it('Spam dihapus setelah 30 hari, lainnya setelah 24 bulan', function (): void {
        $this->post('/prospek', IsianProspek(['Nama' => 'Lama']));
        $this->post('/prospek', IsianProspek(['Nama' => 'Spam', 'NoHp' => '081111111111']));
        ProspekSitus::query()->where('Nama', 'Spam')->update(['Status' => StatusProspek::Spam->value]);

        $this->travel(31)->days();
        $this->post('/prospek', IsianProspek(['Nama' => 'Baru', 'NoHp' => '081222222222']));
        $this->artisan('situs:bersihkan-prospek')->assertSuccessful();
        expect(ProspekSitus::query()->pluck('Nama')->sort()->values()->all())->toBe(['Baru', 'Lama']);

        $this->travel(24)->months();
        $this->travel(1)->days();
        $this->artisan('situs:bersihkan-prospek')->assertSuccessful();
        expect(ProspekSitus::query()->count())->toBe(0);
    });

    it('ID analitik tersimpan dari konsol & dibagikan ke situs; ID tidak valid ditolak', function (): void {
        $pengguna = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $this->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $pengaturan = PengaturanSitusBerlaku::AmbilBawaan();

        $this->put(BantuanPengelola::Url('/situs/pengaturan'), [...$pengaturan, 'Analitik' => ['IdGoogleAnalytics' => '<script>', 'IdMetaPixel' => 'abc']])
            ->assertSessionHasErrors(['Analitik.IdGoogleAnalytics', 'Analitik.IdMetaPixel']);

        $this->put(BantuanPengelola::Url('/situs/pengaturan'), [
            ...$pengaturan,
            'Analitik' => ['IdGoogleAnalytics' => 'g-abc123xyz', 'IdMetaPixel' => '123456789012'],
            'Prospek' => ['EmailNotifikasi' => 'Sales@Payoung.test'],
        ])->assertSessionHasNoErrors();

        $this->get(rtrim((string) config('app.url'), '/').'/')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Situs.Analitik.IdGoogleAnalytics', 'G-ABC123XYZ')
            ->where('Situs.Analitik.IdMetaPixel', '123456789012'));
        expect(app(PengaturanSitusBerlaku::class)->Ambil()['Prospek']['EmailNotifikasi'])->toBe('sales@payoung.test');
    });
});

it('audit F-21: sidik nomor/IP memakai kunci situs.KunciSidik bila diisi (tidak ikut rotasi APP_KEY), bawaan APP_KEY', function (): void {
    config(['situs.KunciSidik' => null]);
    $lama = ProspekSitus::BuatSidik('6281234567890');
    expect($lama)->toBe(hash_hmac('sha256', '6281234567890', (string) config('app.key')));

    config(['situs.KunciSidik' => 'kunci-sidik-khusus-uji']);
    $baru = ProspekSitus::BuatSidik('6281234567890');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect($baru)->toBe(hash_hmac('sha256', '6281234567890', 'kunci-sidik-khusus-uji'))
        ->and(ProspekSitus::BuatSidik('6281234567890'))->toBe($baru);
});
