<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDoku;
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
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-08 / P-05 v2.06: gerbang pembayaran QRIS dinamis milik tenant. Toko memakai akun merchant sendiri (dana langsung ke
 * rekening toko): pilih penyedia yang diizinkan platform, kredensial terenkripsi (hanya 4 karakter terakhir, BR-P05.1),
 * uji koneksi, aktifkan (pola BR-P05.4). Platform hanya mengatur katalog penyedia dan tidak pernah melihat kredensial.
 */

const KUNCI_DOKU_TOKO = 'SK-doku-TokoBerkahSolo-7788';

const ID_KLIEN_DOKU_TOKO = 'BRN-0012-7788';

/*
 * D-162: halaman & aksi kredensial gerbang milik toko hanya hidup di edisi Lisensi (tenant SaaS memakai Aktivasi QRIS);
 * berkas ini membukanya lewat sakelar `integrasi.GerbangTokoTampil`. `GerbangPembayaranDisembunyikanTes` menguji sisi SaaS.
 */
beforeEach(function (): void {
    config(['integrasi.GerbangTokoTampil' => true]);
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
        'Pengaturan' => ['IdKlien' => ID_KLIEN_DOKU_TOKO],
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
    it('simpan DOKU: kredensial terenkripsi, halaman hanya petunjuk 4 karakter; aktifkan ditolak sebelum uji; uji berhasil → aktif dipakai runtime', function (): void {
        // Order uji tidak ada (404) tetapi tanda tangan diterima DOKU: kunci dianggap benar.
        Http::fake(['api-sandbox.doku.com/*' => Http::response(['message' => ['Order not found']], 404)]);
        $toko = TokoGerbang();

        SimpanDokuToko($this, $toko);
        $gerbang = GerbangToko($toko);
        $mentah = DB::table('GerbangPembayaranTenant')->where('Id', $gerbang->Id)->value('Kredensial');

        expect($gerbang->Penyedia)->toBe(PenyediaGerbang::Doku)
            ->and($gerbang->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($gerbang->Aktif)->toBeFalse()
            ->and($mentah)->not->toContain(KUNCI_DOKU_TOKO)
            ->and($gerbang->Pengaturan)->toBe(['IdKlien' => ID_KLIEN_DOKU_TOKO])
            ->and($gerbang->Kredensial)->toBe(['KunciRahasia' => KUNCI_DOKU_TOKO])
            ->and($gerbang->PetunjukKredensial)->toBe(['KunciRahasia' => '••••7788']);

        $halaman = MasukToko($this, $toko)->get('/kelola/pembayaran/gerbang')->assertOk();
        $halaman->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembayaran/Gerbang')
            ->where('Gerbang.Penyedia', 'Doku')
            ->where('Gerbang.PetunjukKredensial', ['KunciRahasia' => '••••7788'])
            ->where('Gerbang.UrlWebhook', fn (string $url) => str_contains($url, '/webhook/doku/'))
            ->has('DaftarPenyedia', 1)
            ->where('DaftarPenyedia.0.Nilai', 'Doku'));
        expect($halaman->getContent())->not->toContain(KUNCI_DOKU_TOKO);

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasErrors();
        expect(GerbangToko($toko)->Aktif)->toBeFalse();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/uji')->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api-sandbox.doku.com/orders/v1/status/')
            && $r->hasHeader('Client-Id', ID_KLIEN_DOKU_TOKO)
            && str_starts_with($r->header('Signature')[0] ?? '', 'HMACSHA256='));
        expect(GerbangToko($toko)->StatusUji)->toBe(StatusUjiGerbang::Berhasil);

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $aktif = app(PembuatGerbangPembayaran::class)->AmbilAktifTenant();
        expect($aktif?->gerbang)->toBeInstanceOf(AdaptorDoku::class)
            ->and($aktif?->urlNotifikasi)->toContain('/webhook/doku/'.GerbangToko($toko)->TokenWebhook);

        // Log audit tenant tanpa nilai kredensial, hanya nama bidang yang diganti.
        $audit = LogAudit::query()->where('Peristiwa', 'gerbang-pembayaran.buat')->sole();
        expect(json_encode($audit->NilaiBaru))->not->toContain(KUNCI_DOKU_TOKO)
            ->and($audit->NilaiBaru['KredensialDiganti'])->toBe(['KunciRahasia'])
            ->and(LogAudit::query()->whereIn('Peristiwa', ['gerbang-pembayaran.uji', 'gerbang-pembayaran.aktifkan'])->count())->toBe(2);
    });

    it('audit kemudahan pakai #24: aktifkan gerbang membuat pilihan bayar QRIS dinamis sekali; metode yang sudah ada tidak diubah', function (): void {
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

    it('ubah isian → Belum diuji & nonaktif; kosongkan kredensial = dipertahankan; kredensial wajib saat gerbang belum ada; penyedia lama ditolak', function (): void {
        $toko = TokoGerbang();
        SimpanDokuToko($this, $toko);
        GerbangToko($toko)->forceFill(['StatusUji' => StatusUjiGerbang::Berhasil, 'Aktif' => true])->save();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Produksi', 'Pengaturan' => ['IdKlien' => ID_KLIEN_DOKU_TOKO], 'Kredensial' => ['KunciRahasia' => ''],
        ])->assertSessionHasNoErrors();
        $gerbang = GerbangToko($toko);
        expect($gerbang->Kredensial)->toBe(['KunciRahasia' => KUNCI_DOKU_TOKO])
            ->and($gerbang->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($gerbang->Aktif)->toBeFalse();

        // Toko lain yang belum punya gerbang: kredensial kosong ditolak.
        $lain = TokoGerbang('Warung Makan Sederhana Klaten');
        MasukToko($this, $lain)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Produksi', 'Pengaturan' => ['IdKlien' => ID_KLIEN_DOKU_TOKO], 'Kredensial' => ['KunciRahasia' => ''],
        ])->assertSessionHasErrors('Kredensial.KunciRahasia');
        expect(GerbangToko($lain))->toBeNull();

        // Penyedia lama (sudah dihapus) tidak bisa dipilih lagi dan tidak mengubah gerbang tersimpan.
        foreach (['Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu'] as $lama) {
            MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
                'Penyedia' => $lama, 'Lingkungan' => 'Produksi', 'Pengaturan' => [], 'Kredensial' => ['KunciRahasia' => 'rahasia-baru-12345'],
            ])->assertSessionHasErrors('Penyedia');
        }
        expect(GerbangToko($toko)->Penyedia)->toBe(PenyediaGerbang::Doku)
            ->and(GerbangToko($toko)->Kredensial)->toBe(['KunciRahasia' => KUNCI_DOKU_TOKO]);
    });

    it('uji ditolak penyedia (401) → Uji gagal dengan pesan tanpa kredensial, tidak bisa diaktifkan', function (): void {
        Http::fake(['api-sandbox.doku.com/*' => Http::response(['message' => ['Invalid signature SK-doku-rahasia-toko-9999']], 401)]);
        $toko = TokoGerbang();
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang', [
            'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => ['IdKlien' => ID_KLIEN_DOKU_TOKO],
            'Kredensial' => ['KunciRahasia' => 'SK-doku-rahasia-toko-9999'],
        ])->assertSessionHasNoErrors();

        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/uji')->assertSessionHasErrors('Umum');
        $gerbang = GerbangToko($toko);
        expect($gerbang->StatusUji)->toBe(StatusUjiGerbang::Gagal)
            ->and($gerbang->PesanUji)->not->toContain('SK-doku-rahasia-toko-9999');
        MasukToko($this, $toko)->post('/kelola/pembayaran/gerbang/aktifkan')->assertSessionHasErrors();
        expect(GerbangToko($toko)->Aktif)->toBeFalse();
    });

    it('platform melarang penyedia (wajib alasan): toko tidak bisa memilihnya dan gerbang aktifnya berhenti dipakai; platform tidak melihat kredensial', function (): void {
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
            'Penyedia' => 'Doku', 'Lingkungan' => 'Sandbox', 'Pengaturan' => ['IdKlien' => ID_KLIEN_DOKU_TOKO], 'Kredensial' => ['KunciRahasia' => 'SK-doku-baru-1234'],
        ])->assertSessionHasErrors('Penyedia');

        $teknis()->post(BantuanPengelola::Url('/integrasi/gerbang-pembayaran/Doku'), ['Diizinkan' => true])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        expect(app(PembuatGerbangPembayaran::class)->AmbilAktifTenant())->not->toBeNull();
    });

    it('isolasi tenant & izin: toko lain tidak melihat/memakai gerbang toko A; kasir tanpa izin 403', function (): void {
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
});
