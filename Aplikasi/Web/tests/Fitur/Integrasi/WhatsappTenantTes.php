<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\Model\PemakaianPesanWa;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PengirimWhatsappBerkuota;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PaketFitur;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * D-108: WhatsApp milik tenant. Toko memasang nomor dan akun penyedianya sendiri (Fonnte, Wablas, StarSender, Watzap,
 * atau Meta Cloud); kredensial terenkripsi dan hanya petunjuk 4 karakter terakhir yang tampil (BR-P05.1); aktif hanya
 * setelah uji berhasil (BR-P05.4). Pesan lewat nomor ini tidak memakai kuota pesan paket.
 */

const TOKEN_FONNTE_TOKO = 'tokenFonnteTokoSolo-4521';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'token-payoung']]]);
});

/** @return array{Tenant: mixed, Pemilik: mixed} */
function TokoWa(string $nama = 'Toko Kelontong Berkah Solo', ?int $kuota = null): array
{
    $toko = BantuanOrganisasi::BuatTenant($nama);
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
    $pembuat = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id;
    $baris = fn (string $jenis, string $kunci, ?int $nilai): array => [
        'IdTenant' => $toko['Tenant']->Id, 'Jenis' => $jenis, 'Kunci' => $kunci, 'Nilai' => $nilai,
        'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji WhatsApp tenant', 'DibuatOleh' => $pembuat,
    ];
    OverrideTenant::query()->create($baris(JenisOverride::Fitur->value, 'integrasi.whatsapp', null));
    OverrideTenant::query()->create($baris(JenisOverride::Batas->value, 'KuotaPesanWaBulanan', $kuota));

    return $toko;
}

function MasukWa(TestCase $tes, array $toko): TestCase
{
    return BantuanOrganisasi::Masuk($tes, $toko['Pemilik'], $toko['Tenant']->Id);
}

function SimpanFonnteToko(TestCase $tes, array $toko, string $token = TOKEN_FONNTE_TOKO): void
{
    MasukWa($tes, $toko)->post('/kelola/pengaturan/whatsapp', [
        'Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => $token],
    ])->assertSessionHasNoErrors();
}

describe('D-108 WhatsApp milik tenant', function (): void {
    it('simpan: kredensial terenkripsi, halaman hanya petunjuk; aktifkan ditolak sebelum uji; uji berhasil → aktif dan dipakai tanpa kuota', function (): void {
        Http::fake([
            'api.fonnte.com/device' => Http::response(['status' => true, 'device_status' => 'connect']),
            'api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']]),
        ]);
        $toko = TokoWa(kuota: 1);

        SimpanFonnteToko($this, $toko);
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $baris = WhatsappTenant::query()->sole();
        $mentah = DB::table('WhatsappTenant')->where('Id', $baris->Id)->value('Kredensial');

        expect($baris->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and($baris->Aktif)->toBeFalse()
            ->and($mentah)->not->toContain(TOKEN_FONNTE_TOKO)
            ->and($baris->Kredensial)->toBe(['Token' => TOKEN_FONNTE_TOKO])
            ->and($baris->PetunjukKredensial)->toBe(['Token' => '••••4521']);

        MasukWa($this, $toko)->get('/kelola/pengaturan/whatsapp')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pengaturan/Whatsapp')
            ->where('Milik.Penyedia', 'Fonnte')
            ->where('Milik.PetunjukKredensial', ['Token' => '••••4521'])
            ->where('Milik.Aktif', false)
            ->where('Kuota.Batas', 1)
            ->where('NomorPayoungTersedia', true)
            ->has('DaftarPenyedia', 5));

        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/aktifkan')->assertSessionHasErrors();
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/uji')->assertSessionHasNoErrors();
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/aktifkan')->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $pengirim = app(PembuatPengirimWhatsapp::class)->AmbilAktif();

        // Kuota paket hanya 1, tetapi nomor sendiri tidak dihitung: lima pesan berhasil, meteran tetap kosong.
        expect($pengirim)->not->toBeInstanceOf(PengirimWhatsappBerkuota::class);

        foreach (range(1, 5) as $_) {
            expect($pengirim->Kirim(new PesanWhatsapp('6281234567890', 'Halo'))->berhasil)->toBeTrue();
        }

        expect(PemakaianPesanWa::query()->count())->toBe(0)
            ->and(LogAudit::query()->whereIn('Peristiwa', ['whatsapp-tenant.buat', 'whatsapp-tenant.uji', 'whatsapp-tenant.aktifkan'])->count())->toBe(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.fonnte.com/send' && $r->header('Authorization') === [TOKEN_FONNTE_TOKO]);
    });

    it('uji gagal tidak mengaktifkan; mengubah isian setelah aktif menonaktifkan dan pesan kembali ke nomor Payoung berkuota', function (): void {
        $toko = TokoWa();
        SimpanFonnteToko($this, $toko);
        Http::fake(['api.fonnte.com/device' => Http::response(['status' => false, 'reason' => 'invalid token'])]);

        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/uji')->assertSessionHasErrors('Umum');
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/aktifkan')->assertSessionHasErrors();

        Http::swap(new Factory);
        Http::fake(['api.fonnte.com/device' => Http::response(['status' => true, 'device_status' => 'connect'])]);
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/uji')->assertSessionHasNoErrors();
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp/aktifkan')->assertSessionHasNoErrors();

        // Token dikosongkan = dipertahankan, pengaturan lain berubah → kembali BelumDiuji dan nonaktif.
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp', ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tokenBaruLagi-9988']])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $baris = WhatsappTenant::query()->sole();

        expect($baris->Aktif)->toBeFalse()
            ->and($baris->StatusUji)->toBe(StatusUjiGerbang::BelumDiuji)
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->toBeInstanceOf(PengirimWhatsappBerkuota::class);
    });

    it('validasi: penyedia di luar daftar ditolak, kredensial wajib diisi saat pertama kali, templat Meta tersimpan di pengaturan tenant', function (): void {
        $toko = TokoWa();

        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp', ['Penyedia' => 'Smtp', 'Pengaturan' => [], 'Kredensial' => []])->assertSessionHasErrors('Penyedia');
        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp', ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => []])->assertSessionHasErrors('Kredensial.Token');

        MasukWa($this, $toko)->post('/kelola/pengaturan/whatsapp', [
            'Penyedia' => 'MetaCloud',
            'Pengaturan' => ['IdNomorTelepon' => '1099887766', 'VersiApi' => 'v21.0', 'BahasaTemplat' => 'id', 'NamaTemplatStruk' => 'struk_toko_berkah'],
            'Kredensial' => ['TokenAkses' => 'EAAGtokenMetaTokoBerkah-7766'],
        ])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $baris = WhatsappTenant::query()->sole();
        $baris->forceFill(['Aktif' => true, 'StatusUji' => StatusUjiGerbang::Berhasil])->save();

        expect(app(PembuatPengirimWhatsapp::class)->AmbilTemplatStruk())->toBe('struk_toko_berkah')
            ->and($baris->Pengaturan)->not->toHaveKey('NamaTemplatPengingatTagihan');
    });

    it('izin integrasi.api.kelola (kasir 403), paket tanpa fitur WhatsApp ditolak, dan tenant lain tidak ikut terpengaruh', function (): void {
        $a = TokoWa('Toko A Solo');
        $b = TokoWa('Toko B Solo');
        SimpanFonnteToko($this, $a);

        MasukWa($this, $b)->get('/kelola/pengaturan/whatsapp')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Milik', null));

        $kasir = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $a['Tenant']->Id)->get('/kelola/pengaturan/whatsapp')->assertForbidden();

        $c = BantuanOrganisasi::BuatTenant('Toko Tanpa Fitur Solo');
        PaketFitur::query()->where('KunciFitur', 'integrasi.whatsapp')->delete();
        MasukWa($this, $c)->post('/kelola/pengaturan/whatsapp', ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tokenTokoC-1122']])->assertSessionHasErrors('Penyedia');
    });
});
