<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDokuSnap;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Integrasi\Model\KatalogGerbangPembayaran;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-08 / P-05 v2.06: gerbang pembayaran QRIS dinamis milik tenant. Toko memakai akun merchant sendiri (dana langsung ke
 * rekening toko): pilih penyedia yang diizinkan platform, kredensial terenkripsi (hanya 4 karakter terakhir, BR-P05.1),
 * uji koneksi, aktifkan (pola BR-P05.4). Platform hanya mengatur katalog penyedia dan tidak pernah melihat kredensial.
 */

const KUNCI_DOKU_TOKO = 'SK-doku-TokoBerkahSolo-7788';

const ID_KLIEN_DOKU_TOKO = 'BRN-0012-7788';

/** Isian khas DOKU SNAP (D-314) selain Client ID: Merchant ID, Terminal ID, dan kode pos dari dasbor DOKU. */
const PENGATURAN_DOKU_TOKO = [
    'IdKlien' => ID_KLIEN_DOKU_TOKO,
    'IdPedagangQris' => '111642',
    'IdTerminalQris' => 'A01',
    'KodePosQris' => '57214',
];

/*
 * D-312: halaman & aksi kredensial gerbang milik toko terbuka di semua edisi secara bawaan (DOKU tidak mendukung sub
 * account), jadi berkas ini sengaja berjalan tanpa memaksa sakelar `integrasi.GerbangTokoTampil`.
 * `GerbangPembayaranDisembunyikanTes` menguji keadaan saat sakelar dimatikan.
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{Tenant: mixed, Pemilik: mixed}
 */
function TokoGerbang(string $nama = 'Toko Kelontong Berkah Solo'): array
{
    return BantuanOrganisasi::BuatTenant($nama);
}

function MasukToko(TestCase $tes, array $toko): TestCase
{
    return BantuanOrganisasi::Masuk($tes, $toko['Pemilik'], $toko['Tenant']->Id);
}

function SimpanDokuToko(TestCase $tes, array $toko, string $kunci = KUNCI_DOKU_TOKO): void
{
    MasukToko($tes, $toko)->post('/kelola/pembayaran/gerbang', [
        'Penyedia' => 'Doku',
        'Lingkungan' => 'Sandbox',
        'Pengaturan' => PENGATURAN_DOKU_TOKO,
        'Kredensial' => ['KunciRahasia' => $kunci],
    ])->assertSessionHasNoErrors();
}

/** URL absolut back-office toko: setelah request ke subdomain pengelola, path relatif ikut host pengelola. */
function UrlToko(string $path): string
{
    return rtrim((string) config('app.url'), '/').$path;
}

function GerbangToko(array $toko): ?GerbangPembayaranTenant
{
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

    return GerbangPembayaranTenant::query()->first();
}

describe('v2.06 gerbang pembayaran milik toko', function (): void {
    it('simpan DOKU: kredensial terenkripsi, halaman hanya petunjuk 4 karakter. Aktifkan ditolak sebelum uji. Uji berhasil → aktif dipakai runtime', function (): void {
        // DOKU SNAP menerima tanda tangan RSA dan menerbitkan token B2B: Client ID dan kunci publik dianggap terdaftar.
        Http::fake(['api-sandbox.doku.com/*' => BantuanGerbangTenant::ResponsStatus('PENDING')]);
        $toko = TokoGerbang();

        SimpanDokuToko($this, $toko);
        $gerbang = GerbangToko($toko);
        $mentah = DB::table('GerbangPembayaranTenant')->where('Id', $gerbang->Id)->value('Kredensial');

        expect($gerbang->Penyedia)->toBe(PenyediaGerbang::Doku)
            ->and($gerbang->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($gerbang->Aktif)->toBeFalse()
            ->and($mentah)->not->toContain(KUNCI_DOKU_TOKO)
            ->and($gerbang->Pengaturan)->toMatchArray(PENGATURAN_DOKU_TOKO)
            ->and($gerbang->Pengaturan['KunciPublikSnap'])->toStartWith('-----BEGIN PUBLIC KEY-----')
            ->and($gerbang->Kredensial['KunciRahasia'])->toBe(KUNCI_DOKU_TOKO)
            ->and($gerbang->Kredensial['KunciPribadiSnap'])->toStartWith('-----BEGIN PRIVATE KEY-----')
            ->and($gerbang->PetunjukKredensial)->toBe(['KunciRahasia' => '••••7788']);

        $halaman = MasukToko($this, $toko)->get('/kelola/pembayaran/gerbang')->assertOk();
        $halaman->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembayaran/Gerbang')
            ->where('Gerbang.Penyedia', 'Doku')
            ->where('Gerbang.PetunjukKredensial', ['KunciRahasia' => '••••7788'])
            ->where('Gerbang.UrlWebhook', fn (string $url) => str_contains($url, '/webhook/doku/'))
            ->where('Gerbang.UrlToken', fn (string $url) => str_ends_with($url, '/authorization/v1/access-token/b2b'))
            ->where('Gerbang.KunciPublikSnap', fn (string $kunci) => str_starts_with($kunci, '-----BEGIN PUBLIC KEY-----'))
            ->missing('Gerbang.Pengaturan.KunciPublikSnap')
            ->has('DaftarPenyedia', 1)
            ->where('DaftarPenyedia.0.Nilai', 'Doku'));
        expect($halaman->getContent())->not->toContain(KUNCI_DOKU_TOKO)->not->toContain('PRIVATE KEY');

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasErrors();
        expect(GerbangToko($toko)->Aktif)->toBeFalse();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/uji')->assertSessionHasNoErrors();
        // Token B2B diminta dengan tanda tangan RSA atas `{ClientId}|{waktu}` yang bisa diverifikasi kunci publik tersimpan.
        $kunciPublik = GerbangToko($toko)->Pengaturan['KunciPublikSnap'];
        Http::assertSent(function ($r) use ($kunciPublik): bool {
            if ($r->url() !== 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' || ! $r->hasHeader('X-CLIENT-KEY', ID_KLIEN_DOKU_TOKO)) {
                return false;
            }

            return openssl_verify(ID_KLIEN_DOKU_TOKO.'|'.$r->header('X-TIMESTAMP')[0], (string) base64_decode($r->header('X-SIGNATURE')[0], true), $kunciPublik, OPENSSL_ALGO_SHA256) === 1;
        });
        expect(GerbangToko($toko)->StatusUji)->toBe(StatusUjiGerbang::Berhasil);

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $aktif = app(PembuatGerbangPembayaran::class)->AmbilAktifTenant();
        expect($aktif?->gerbang)->toBeInstanceOf(AdaptorDokuSnap::class)
            ->and($aktif?->urlNotifikasi)->toContain('/webhook/doku/'.GerbangToko($toko)->TokenWebhook);

        // Log audit tenant tanpa nilai kredensial, hanya nama bidang yang diganti.
        $audit = LogAudit::query()->where('Peristiwa', 'gerbang-pembayaran.buat')->sole();
        expect(json_encode($audit->NilaiBaru))->not->toContain(KUNCI_DOKU_TOKO)->not->toContain('PRIVATE KEY')->not->toContain('PUBLIC KEY')
            ->and($audit->NilaiBaru['KredensialDiganti'])->toBe(['KunciRahasia', 'KunciPribadiSnap'])
            ->and(LogAudit::query()->whereIn('Peristiwa', ['gerbang-pembayaran.uji', 'gerbang-pembayaran.aktifkan'])->count())->toBe(2);
    });

    it('audit kemudahan pakai #24: aktifkan gerbang membuat pilihan bayar QRIS dinamis sekali. Metode yang sudah ada tidak diubah', function (): void {
        $toko = TokoGerbang();
        SimpanDokuToko($this, $toko);
        GerbangToko($toko)->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil])->save();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'QRIS tersambung. Pilihan bayar "QRIS" sudah ditambahkan ke kasir.');
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $metode = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->sole();
        expect($metode->Nama)->toBe('QRIS')->and($metode->Aktif)->toBeTrue()
            ->and(MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tunai->value)->exists())->toBeTrue();

        // Toko menonaktifkan pilihan QRIS lalu menyambung ulang gerbang: tidak dibuat kembar, tidak diaktifkan diam-diam.
        $metode->update(['Aktif' => false]);
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/nonaktifkan')->assertSessionHasNoErrors();
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        expect(MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->count())->toBe(1)
            ->and($metode->refresh()->Aktif)->toBeFalse();

        // Isolasi tenant: toko lain tidak ikut mendapat metode.
        $lain = TokoGerbang('Warung Sate Pak Kumis Klaten');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->exists())->toBeFalse();
    });

    it('ubah isian → Belum diuji & nonaktif. Kosongkan kredensial = dipertahankan. Kredensial wajib saat gerbang belum ada. Penyedia lama ditolak', function (): void {
        $toko = TokoGerbang();
        SimpanDokuToko($this, $toko);
        GerbangToko($toko)->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil, 'Aktif' => true])->save();
        $kunciPribadiAwal = GerbangToko($toko)->Kredensial['KunciPribadiSnap'];

        // Menyimpan isian yang sama persis tidak membatalkan hasil uji (urutan kunci JSON tidak berpengaruh).
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => PENGATURAN_DOKU_TOKO, 'Kredensial' => ['KunciRahasia' => ''],
        ])->assertSessionHasNoErrors();
        expect(GerbangToko($toko)->StatusUji)->toBe(StatusUjiGerbang::Berhasil)->and(GerbangToko($toko)->Aktif)->toBeTrue();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Produksi', 'Pengaturan' => PENGATURAN_DOKU_TOKO, 'Kredensial' => ['KunciRahasia' => ''],
        ])->assertSessionHasNoErrors();
        $gerbang = GerbangToko($toko);
        expect($gerbang->Kredensial)->toBe(['KunciRahasia' => KUNCI_DOKU_TOKO, 'KunciPribadiSnap' => $kunciPribadiAwal])
            ->and($gerbang->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($gerbang->Aktif)->toBeFalse();

        // Toko lain yang belum punya gerbang: kredensial kosong ditolak.
        $lain = TokoGerbang('Warung Makan Sederhana Klaten');
        MasukToko($this, $lain)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Produksi', 'Pengaturan' => PENGATURAN_DOKU_TOKO, 'Kredensial' => ['KunciRahasia' => ''],
        ])->assertSessionHasErrors('Kredensial.KunciRahasia');
        expect(GerbangToko($lain))->toBeNull();

        // Penyedia lama (sudah dihapus) tidak bisa dipilih lagi dan tidak mengubah gerbang tersimpan.
        foreach (['Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu'] as $lama) {
            MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
                'Penyedia' => $lama, 'Lingkungan' => 'Produksi', 'Pengaturan' => [], 'Kredensial' => ['KunciRahasia' => 'rahasia-baru-12345'],
            ])->assertSessionHasErrors('Penyedia');
        }
        expect(GerbangToko($toko)->Penyedia)->toBe(PenyediaGerbang::Doku)
            ->and(GerbangToko($toko)->Kredensial['KunciRahasia'])->toBe(KUNCI_DOKU_TOKO);
    });

    it('uji ditolak penyedia (401) → Uji gagal dengan pesan tanpa kredensial, tidak bisa diaktifkan', function (): void {
        Http::fake(['api-sandbox.doku.com/*' => Http::response(['responseCode' => '4017300', 'responseMessage' => 'Unauthorized. Signature SK-doku-rahasia-toko-9999'], 401)]);
        $toko = TokoGerbang();
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => PENGATURAN_DOKU_TOKO,
            'Kredensial' => ['KunciRahasia' => 'SK-doku-rahasia-toko-9999'],
        ])->assertSessionHasNoErrors();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/uji')->assertSessionHasErrors('Umum');
        $gerbang = GerbangToko($toko);
        expect($gerbang->StatusUji)->toBe(StatusUjiGerbang::Gagal)
            ->and($gerbang->PesanUji)->not->toContain('SK-doku-rahasia-toko-9999');
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasErrors();
        expect(GerbangToko($toko)->Aktif)->toBeFalse();
    });

    it('platform melarang penyedia (wajib alasan): toko tidak bisa memilihnya dan gerbang aktifnya berhenti dipakai. Platform tidak melihat kredensial', function (): void {
        $toko = TokoGerbang();
        SimpanDokuToko($this, $toko);
        GerbangToko($toko)->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil, 'Aktif' => true])->save();
        $teknis = fn () => $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $teknis()->post(BantuanPengelola::Url('/integrasi/gerbang-pembayaran/Doku'), ['Diizinkan' => false])->assertSessionHasErrors('Alasan');
        $teknis()->post(BantuanPengelola::Url('/integrasi/gerbang-pembayaran/Doku'), ['Diizinkan' => false, 'Alasan' => 'Gangguan penyelesaian dana dari penyedia'])
            ->assertSessionHasNoErrors();
        expect(KatalogGerbangPembayaran::query()->where('Penyedia', 'Doku')->value('Diizinkan'))->toBeFalse()
            ->and(LogAuditPengelola::query()->where('Aksi', 'integrasi.gerbang.larang')->count())->toBe(1);

        $halamanPengelola = $teknis()->get(BantuanPengelola::Url('/integrasi'))->assertOk();
        $halamanPengelola->assertInertia(fn (AssertableInertia $h) => $h
            ->where('GerbangTenant.0.Penyedia', 'Doku')
            ->where('GerbangTenant.0.Diizinkan', false)
            ->has('GerbangTenant', 1)
            ->where('GerbangTenant.0.JumlahTenant', 1)
            ->where('GerbangTenant.0.JumlahAktif', 1));
        expect($halamanPengelola->getContent())->not->toContain(KUNCI_DOKU_TOKO)->not->toContain('••••7788');

        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        expect(app(PembuatGerbangPembayaran::class)->AmbilAktifTenant())->toBeNull();
        MasukToko($this, $toko)->get(UrlToko('/kelola/pembayaran/gerbang'))->assertInertia(fn (AssertableInertia $h) => $h
            ->has('DaftarPenyedia', 0)
            ->where('Gerbang.PenyediaDiizinkan', false));
        MasukToko($this, $toko)->post(UrlToko('/kelola/pembayaran/gerbang'), [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => PENGATURAN_DOKU_TOKO, 'Kredensial' => ['KunciRahasia' => 'SK-doku-baru-1234'],
        ])->assertSessionHasErrors('Penyedia');

        $teknis()->post(BantuanPengelola::Url('/integrasi/gerbang-pembayaran/Doku'), ['Diizinkan' => true])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        expect(app(PembuatGerbangPembayaran::class)->AmbilAktifTenant())->not->toBeNull();
    });

    it('isolasi tenant & izin: toko lain tidak melihat/memakai gerbang toko A. Kasir tanpa izin 403', function (): void {
        $a = TokoGerbang('Toko Kelontong Berkah Solo');
        $b = TokoGerbang('Warung Makan Sederhana Klaten');
        SimpanDokuToko($this, $a);
        GerbangToko($a)->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil, 'Aktif' => true])->save();

        MasukToko($this, $b)->get('/kelola/pembayaran/gerbang')->assertInertia(fn (AssertableInertia $h) => $h->where('Gerbang', null));
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(app(PembuatGerbangPembayaran::class)->AmbilAktifTenant())->toBeNull();
        MasukToko($this, $b)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasErrors();
        expect(GerbangToko($a)->Aktif)->toBeTrue();

        $kasir = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $a['Tenant']->Id)->get('/kelola/pembayaran/gerbang')->assertForbidden();
    });

    it('D-314 isian SNAP wajib lengkap, kunci RSA dibuat sekali dan hanya berganti bila diminta (Belum diuji & nonaktif)', function (): void {
        $toko = TokoGerbang();

        foreach (['IdPedagangQris', 'IdTerminalQris', 'KodePosQris'] as $bidang) {
            MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
                'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => [...PENGATURAN_DOKU_TOKO, $bidang => ''], 'Kredensial' => ['KunciRahasia' => KUNCI_DOKU_TOKO],
            ])->assertSessionHasErrors("Pengaturan.{$bidang}");
        }

        expect(GerbangToko($toko))->toBeNull();
        SimpanDokuToko($this, $toko);
        SimpanDokuToko($this, $toko, 'SK-doku-TokoBerkahSolo-9999');
        $awal = GerbangToko($toko);
        // Ganti secret key tidak mengganti pasangan kunci RSA yang sudah didaftarkan di dasbor DOKU.
        expect($awal->Kredensial['KunciRahasia'])->toBe('SK-doku-TokoBerkahSolo-9999');
        $pribadi = $awal->Kredensial['KunciPribadiSnap'];
        $publik = $awal->Pengaturan['KunciPublikSnap'];
        $awal->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil, 'Aktif' => true])->save();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/kunci-snap')->assertSessionHasNoErrors();
        $baru = GerbangToko($toko);
        expect($baru->Kredensial['KunciPribadiSnap'])->not->toBe($pribadi)
            ->and($baru->Pengaturan['KunciPublikSnap'])->not->toBe($publik)
            ->and($baru->Kredensial['KunciRahasia'])->toBe('SK-doku-TokoBerkahSolo-9999')
            ->and($baru->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($baru->Aktif)->toBeFalse();
        $audit = LogAudit::query()->where('Peristiwa', 'gerbang-pembayaran.kunci-snap-baru')->sole();
        expect(json_encode($audit->NilaiBaru))->not->toContain('KEY-----');

        // Toko lain tidak bisa membuat ulang kunci gerbang toko ini (tanpa gerbang sendiri = ditolak).
        $lain = TokoGerbang('Warung Makan Sederhana Klaten');
        MasukToko($this, $lain)->post('/kelola/pembayaran/gerbang/kunci-snap')->assertSessionHasErrors();
    });

    it('D-314 uji koneksi tanpa isian lengkap gagal dengan pesan yang menyebut bidangnya, tanpa menghubungi DOKU', function (): void {
        Http::fake();
        $toko = TokoGerbang();
        BantuanGerbangTenant::Aktifkan($toko['Tenant']->Id, pengaturan: ['IdKlien' => ID_KLIEN_DOKU_TOKO]);
        GerbangToko($toko)->forceFill(['StatusUji' => StatusUjiGerbang::BelumDiuji, 'Aktif' => false])->save();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/uji')->assertSessionHasErrors('Umum');
        expect(GerbangToko($toko)->PesanUji)->toContain('Merchant ID QRIS')->toContain('Terminal ID QRIS')->toContain('Kode pos QRIS');
        Http::assertNothingSent();
    });
});
