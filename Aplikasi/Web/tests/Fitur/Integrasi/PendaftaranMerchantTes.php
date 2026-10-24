<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Aksi\AjukanPendaftaranMerchant;
use App\Domain\Integrasi\Aksi\BersihkanBerkasPendaftaranMerchant;
use App\Domain\Integrasi\Aksi\KirimPendaftaranMerchant;
use App\Domain\Integrasi\Aksi\SegarkanStatusPendaftaranMerchant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Integrasi\Merchant\KlienPartnerDoku;
use App\Domain\Integrasi\Merchant\PenyimpanBerkasKyc;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Integrasi\Tugas\KirimPendaftaranMerchantTugas;
use App\Domain\Integrasi\Tugas\SegarkanPendaftaranMerchantTugas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Model\ReferensiBank;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Aktivasi QRIS otomatis lewat DOKU Partner API (KYB): tenant memberi foto KTP, selfie, foto tempat usaha, dan rekening;
 * Payoung mendaftarkannya sebagai merchant di DOKU. Semua panggilan DOKU di-fake (tidak ada panggilan nyata). Foto hanya
 * singgah terenkripsi di disk privat dan dihapus begitu terunggah. Bentuk badan Business Registration, skema tanda
 * tangan Partner, dan payload callback belum terverifikasi dengan DOKU nyata.
 */

const ID_KLIEN_PARTNER = 'BRN-PARTNER-0001';

const KUNCI_PARTNER = 'SK-partner-doku-payoung-rahasia-9988';

const NIK_UJI = '3372010101900001';

const REKENING_UJI = '1234567890123';

const HOST_UAT = 'https://api-uat.doku.com';

const AWALAN_PARTNER = '/adv-core-api/partner/v1.0';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    // D-190: halaman Aktivasi QRIS disembunyikan secara bawaan; berkas ini menguji kodenya yang tetap ada.
    config()->set('integrasi.AktivasiQrisTampil', true);
    IsiKredensialPartner();
    ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
});

function IsiKredensialPartner(bool $isi = true): void
{
    config()->set('integrasi.PendaftaranMerchant', $isi ? [
        'Penyedia' => 'DokuPartner',
        'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => ID_KLIEN_PARTNER],
        'Kredensial' => ['KunciRahasia' => KUNCI_PARTNER],
    ] : []);
}

function UrlMerchant(string $path = ''): string
{
    return rtrim((string) config('app.url'), '/').'/kelola/pembayaran/aktivasi-qris'.$path;
}

/** @return array{Tenant: mixed, Pemilik: mixed} */
function TokoMerchant(string $nama = 'Toko Kelontong Berkah Solo'): array
{
    return BantuanOrganisasi::BuatTenant($nama);
}

function MasukMerchant(TestCase $tes, array $toko): TestCase
{
    return BantuanOrganisasi::Masuk($tes, $toko['Pemilik'], $toko['Tenant']->Id);
}

/** Isian formulir lengkap beserta tiga foto. @param array<string, mixed> $ubah */
function IsianMerchant(array $ubah = [], bool $dengan_foto = true): array
{
    $isian = [
        'NamaPemilik' => 'Budi Santoso',
        'Nik' => NIK_UJI,
        'Email' => 'budi@kelontongberkah.id',
        'NomorHp' => '0812-3456-7890',
        'NamaUsaha' => 'Toko Kelontong Berkah Solo',
        'AlamatUsaha' => 'Jl. Slamet Riyadi No. 120, Laweyan, Surakarta',
        'IdReferensiBank' => ReferensiBank::query()->where('Kode', 'BCA')->value('Id'),
        'NamaPemilikRekening' => 'Budi Santoso',
        'NomorRekening' => REKENING_UJI,
    ];

    if ($dengan_foto) {
        $isian['FotoKtp'] = UploadedFile::fake()->image('ktp.jpg', 640, 400);
        $isian['FotoSwafoto'] = UploadedFile::fake()->image('selfie.jpg', 640, 640);
        $isian['FotoBuktiUsaha'] = UploadedFile::fake()->image('toko.png', 800, 600);
    }

    return [...$isian, ...$ubah];
}

function PendaftaranToko(array $toko): ?PendaftaranMerchantPembayaran
{
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

    return PendaftaranMerchantPembayaran::query()->first();
}

function BerkasTersimpan(): int
{
    return count(Storage::disk('local')->allFiles());
}

function SetelUlangHttp(): void
{
    Http::swap(new Factory);
}

/** @param array<string, mixed> $bisnis */
function JawabanBisnis(string $statusBisnis = 'UPDATING', string $statusBrand = 'UPDATING', array $bisnis = []): array
{
    return ['business' => [
        'id' => 'BSN-0001-AAAA',
        'status' => $statusBisnis,
        'brands' => [[
            'id' => 'BRN-0099-BBBB',
            'status' => $statusBrand,
            'shared_key' => 'SHARED-KEY-RAHASIA-777',
            'services' => [['code' => 'QRIS', 'name' => 'QRIS', 'status' => 'PENDING']],
        ]],
        ...$bisnis,
    ]];
}

/** Semua panggilan Partner sukses: token, tiga unggahan berurutan, lalu registrasi. */
function FakePartnerSukses(array $registrasi = []): void
{
    SetelUlangHttp();
    Http::fake([
        'api-uat.doku.com'.AWALAN_PARTNER.'/token' => Http::response(['token' => 'JWT.header.payload-rahasia'], 200),
        'api-uat.doku.com'.AWALAN_PARTNER.'/file' => Http::sequence()
            ->push(['id' => 'FIL-KTP-01', 'link' => 'https://dl.doku.com/a', 'category' => 'DOCUMENT'])
            ->push(['id' => 'FIL-LIVE-02', 'link' => 'https://dl.doku.com/b', 'category' => 'OWNER_LIVENESS'])
            ->push(['id' => 'FIL-FOTO-03', 'link' => 'https://dl.doku.com/c', 'category' => 'PHOTO_PROOF']),
        'api-uat.doku.com'.AWALAN_PARTNER.'/business' => Http::response($registrasi ?: JawabanBisnis(), 200),
    ]);
}

function KirimMerchant(TestCase $tes, array $toko, array $ubah = [], bool $dengan_foto = true): TestResponse
{
    return MasukMerchant($tes, $toko)->post(UrlMerchant('/kirim'), IsianMerchant($ubah, $dengan_foto));
}

describe('alur kirim: token, unggah berkas, registrasi bisnis', function (): void {
    it('berhasil: token, tiga unggahan multipart, registrasi bertanda tangan dengan NIK & id berkas; status Ditinjau; foto dihapus', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors()->assertSessionHas('Kilat');

        $tanda = fn (Request $r, ?string $isi): string => ProtokolDoku::Tandatangani(
            ID_KLIEN_PARTNER,
            $r->header('Request-Id')[0] ?? '',
            $r->header('Request-Timestamp')[0] ?? '',
            (string) parse_url($r->url(), PHP_URL_PATH),
            $isi,
            KUNCI_PARTNER,
        );

        // 1. Generate Token.
        Http::assertSent(fn (Request $r): bool => $r->url() === HOST_UAT.AWALAN_PARTNER.'/token'
            && $r->method() === 'POST'
            && $r->hasHeader('Client-Id', ID_KLIEN_PARTNER)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $r->header('Request-Timestamp')[0] ?? '') === 1
            && ($r->header('Request-Id')[0] ?? '') !== ''
            && ($r->header('Signature')[0] ?? '') === $tanda($r, $r->body())
            && $r->data() === ['grant_type' => 'client_credentials', 'valid_time' => '360']);

        // 2. Upload File x3: Bearer, multipart, kategori & kode.
        $unggahan = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/file'))->values();
        expect($unggahan)->toHaveCount(3);
        $kategori = [];

        foreach ($unggahan as [$r]) {
            expect($r->method())->toBe('POST')
                ->and($r->hasHeader('Authorization', 'Bearer JWT.header.payload-rahasia'))->toBeTrue()
                ->and($r->isMultipart())->toBeTrue()
                ->and($r->hasHeader('Signature'))->toBeFalse();
            preg_match('/name="category"\s+(\S+)/', $r->body(), $cocok);
            $kategori[] = $cocok[1] ?? '';
        }

        expect($kategori)->toBe(['DOCUMENT', 'OWNER_LIVENESS', 'PHOTO_PROOF']);
        expect($unggahan[0][0]->body())->toContain('name="code"')->toContain('KTP')->toContain('name="file"');
        expect($unggahan[1][0]->body())->not->toContain('name="code"');

        // 3. Business Registration: badan, id berkas, NIK 16 digit, tanda tangan.
        Http::assertSent(function (Request $r) use ($tanda): bool {
            if ($r->url() !== HOST_UAT.AWALAN_PARTNER.'/business') {
                return false;
            }

            $b = $r->data()['business'] ?? [];
            $kontak = $b['contacts'][0] ?? [];

            return $r->method() === 'POST'
                && $r->hasHeader('Client-Id', ID_KLIEN_PARTNER)
                && ($r->header('Signature')[0] ?? '') === $tanda($r, $r->body())
                && $b['type'] === 'PERSONAL' && $b['legal_entity'] === 'PERSEORANGAN'
                && $b['name'] === 'Toko Kelontong Berkah Solo'
                && $b['phone_calling_code'] === '62' && $b['phone_number'] === '81234567890'
                && str_contains($b['callback_url'], '/webhook/pembayaran/doku-partner?ref=')
                && $b['addresses'][0]['country'] === 'ID' && $b['addresses'][0]['primary'] === true
                && $kontak['owner_liveness'] === ['id' => 'FIL-LIVE-02']
                && $kontak['documents'][0] === ['code' => 'KTP', 'category' => 'DOCUMENT', 'id' => 'FIL-KTP-01', 'forms' => [['code' => 'NO_KTP', 'value' => NIK_UJI]]]
                && $b['brands'][0]['photo_proofs'] === [['id' => 'FIL-FOTO-03']]
                && $b['brands'][0]['category'] === 'RETAIL'
                && $b['bank_account']['account_number'] === REKENING_UJI
                && $b['bank_account']['account_name'] === 'Budi Santoso'
                && $b['bank_account']['bank_id'] === 'BCA' && $b['bank_account']['currency'] === 'IDR';
        });

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Ditinjau)
            ->and($p->IdBisnisDoku)->toBe('BSN-0001-AAAA')
            ->and($p->IdBrandDoku)->toBe('BRN-0099-BBBB')
            ->and($p->KunciBersama)->toBe('SHARED-KEY-RAHASIA-777')
            ->and($p->StatusDoku)->toBe('UPDATING')
            ->and($p->DikirimPada)->not->toBeNull()
            ->and($p->PathKtp)->toBeNull()->and($p->PathSwafoto)->toBeNull()->and($p->PathBuktiUsaha)->toBeNull()
            ->and($p->PesanGalat)->toBeNull();
        // Foto TIDAK disimpan permanen: tidak ada berkas tersisa di disk.
        expect(BerkasTersimpan())->toBe(0);
    });

    it('rahasia terenkripsi di database: NIK, rekening, dan shared key tidak tersimpan sebagai teks asli', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $baris = (array) DB::table('PendaftaranMerchantPembayaran')->first();

        expect(json_encode($baris))->not->toContain(NIK_UJI)->not->toContain(REKENING_UJI)->not->toContain('SHARED-KEY-RAHASIA-777')
            ->and($baris['Nik'])->not->toBe(NIK_UJI)
            ->and(PendaftaranToko($toko)->Nik)->toBe(NIK_UJI)
            ->and(PendaftaranToko($toko)->NomorRekening)->toBe(REKENING_UJI)
            ->and(PendaftaranToko($toko)->NikTersamar())->toBe('••••0001')
            ->and(PendaftaranToko($toko)->RekeningTersamar())->toBe('••••0123');
    });

    it('simpan draf: foto tersimpan terenkripsi di disk privat (bukan isi gambar mentah), tidak ada panggilan ke DOKU', function (): void {
        Http::fake();
        $toko = TokoMerchant();

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Draf)
            ->and($p->CekBerkasLengkap())->toBeTrue()
            ->and($p->BerkasDiunggahPada)->not->toBeNull()
            ->and(BerkasTersimpan())->toBe(3);

        foreach ([$p->PathKtp, $p->PathSwafoto, $p->PathBuktiUsaha] as $path) {
            $mentah = Storage::disk('local')->get($path);
            expect($path)->toContain("merchant/kyc/{$toko['Tenant']->Id}/")->and(str_starts_with($mentah, "\xFF\xD8\xFF") || str_starts_with($mentah, "\x89PNG"))->toBeFalse()
                ->and(str_starts_with(Crypt::decryptString($mentah), "\xFF\xD8\xFF") || str_starts_with(Crypt::decryptString($mentah), "\x89PNG"))->toBeTrue();
        }

        Http::assertNothingSent();
    });

    it('draf bisa dilengkapi: foto yang sudah ada dipertahankan, NIK/rekening kosong = pertahankan, foto baru menggantikan yang lama', function (): void {
        $toko = TokoMerchant();
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();
        $lama = PendaftaranToko($toko)->PathKtp;

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['Nik' => '', 'NomorRekening' => '', 'NamaUsaha' => 'Toko Berkah Sejahtera'], false))
            ->assertSessionHasNoErrors();
        $p = PendaftaranToko($toko);
        expect($p->Nik)->toBe(NIK_UJI)->and($p->NomorRekening)->toBe(REKENING_UJI)->and($p->NamaUsaha)->toBe('Toko Berkah Sejahtera')
            ->and($p->PathKtp)->toBe($lama)->and(BerkasTersimpan())->toBe(3);

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['Nik' => '', 'NomorRekening' => '', 'FotoKtp' => UploadedFile::fake()->image('ktp2.png')], false))
            ->assertSessionHasNoErrors();
        $p = PendaftaranToko($toko);
        expect($p->PathKtp)->not->toBe($lama)->and(BerkasTersimpan())->toBe(3)
            ->and(Storage::disk('local')->exists($lama))->toBeFalse();
    });

    it('kirim tanpa foto lengkap ditolak dan DOKU tidak dipanggil', function (): void {
        Http::fake();
        $toko = TokoMerchant();

        KirimMerchant($this, $toko, [], false)->assertSessionHasErrors('Umum');

        Http::assertNothingSent();
        expect(PendaftaranToko($toko)->Status)->toBe(StatusPendaftaranMerchant::Draf);
    });

    it('kirim ulang saat sudah Ditinjau ditolak: tidak ada pendaftaran ganda dan DOKU tidak dipanggil lagi', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();
        Http::assertSentCount(5);

        KirimMerchant($this, $toko)->assertSessionHasErrors('Umum');

        Http::assertSentCount(5);
        expect(PendaftaranMerchantPembayaran::query()->count())->toBe(1);
    });

    it('idempoten di tingkat aksi: bila IdBisnisDoku sudah ada, DOKU tidak didaftari ulang', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();
        PendaftaranToko($toko)->update(['Status' => StatusPendaftaranMerchant::Dikirim]);
        Http::assertSentCount(5);

        $perluUlang = app(KirimPendaftaranMerchant::class)->Jalankan();

        expect($perluUlang)->toBeFalse();
        Http::assertSentCount(5);
    });

    it('galat 4xx pasti: Gagal dengan pesan tersaring (tanpa secret, token, NIK, rekening); foto lokal dihapus; boleh dikirim ulang', function (): void {
        SetelUlangHttp();
        Http::fake([
            'api-uat.doku.com'.AWALAN_PARTNER.'/token' => Http::response(['token' => 'JWT.header.payload-rahasia'], 200),
            'api-uat.doku.com'.AWALAN_PARTNER.'/file' => Http::sequence()->push(['id' => 'F1'])->push(['id' => 'F2'])->push(['id' => 'F3']),
            'api-uat.doku.com'.AWALAN_PARTNER.'/business' => Http::response(['message' => ['NIK '.NIK_UJI.' tidak valid. Kunci '.KUNCI_PARTNER.' rekening '.REKENING_UJI]], 422),
        ]);
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Gagal)
            ->and($p->PesanGalat)->toContain('HTTP 422')->toContain('tidak valid')
            ->and($p->PesanGalat)->not->toContain(NIK_UJI)->not->toContain(KUNCI_PARTNER)->not->toContain(REKENING_UJI)
            ->and($p->IdBisnisDoku)->toBeNull()
            ->and(BerkasTersimpan())->toBe(0);
        expect(json_encode(LogAudit::query()->get()->all()))->not->toContain(NIK_UJI)->not->toContain(KUNCI_PARTNER)->not->toContain(REKENING_UJI);

        // Diperbaiki lalu dikirim ulang: mulai dari awal (foto wajib diunggah ulang), berhasil.
        FakePartnerSukses();
        KirimMerchant($this, $toko, ['Nik' => '', 'NomorRekening' => ''])->assertSessionHasNoErrors();
        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Ditinjau)->and($p->IdBisnisDoku)->toBe('BSN-0001-AAAA')->and($p->PesanGalat)->toBeNull();
    });

    it('galat 5xx saat registrasi: tetap Dikirim dan dapat diulang; id berkas tersimpan jadi unggahan tidak diulang', function (): void {
        SetelUlangHttp();
        Http::fake([
            'api-uat.doku.com'.AWALAN_PARTNER.'/token' => Http::response(['token' => 'JWT-X'], 200),
            'api-uat.doku.com'.AWALAN_PARTNER.'/file' => Http::sequence()->push(['id' => 'F1'])->push(['id' => 'F2'])->push(['id' => 'F3']),
            'api-uat.doku.com'.AWALAN_PARTNER.'/business' => Http::response(['message' => 'Layanan sibuk'], 503),
        ]);
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Dikirim)
            ->and($p->PesanGalat)->toContain('HTTP 503')
            ->and($p->IdFileKtp)->toBe('F1')->and($p->IdFileSwafoto)->toBe('F2')->and($p->IdFileBuktiUsaha)->toBe('F3')
            ->and($p->IdBisnisDoku)->toBeNull()
            ->and(BerkasTersimpan())->toBe(0);

        // Percobaan ulang: hanya registrasi yang dikirim lagi (tanpa token/unggahan baru).
        SetelUlangHttp();
        Http::fake(['api-uat.doku.com'.AWALAN_PARTNER.'/business' => Http::response(JawabanBisnis(), 200)]);
        $perluUlang = app(KirimPendaftaranMerchant::class)->Jalankan();

        expect($perluUlang)->toBeFalse();
        Http::assertSentCount(1);
        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Ditinjau)->and($p->IdBisnisDoku)->toBe('BSN-0001-AAAA');
    });

    it('jaringan putus di tengah unggahan: berkas yang sudah terunggah dihapus, sisanya tetap; percobaan habis kembali ke Draf', function (): void {
        SetelUlangHttp();
        Http::fake([
            'api-uat.doku.com'.AWALAN_PARTNER.'/token' => Http::response(['token' => 'JWT-X'], 200),
            'api-uat.doku.com'.AWALAN_PARTNER.'/file' => Http::sequence()->push(['id' => 'F1'])->push(['message' => 'sibuk'], 502),
        ]);
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Dikirim)
            ->and($p->IdFileKtp)->toBe('F1')->and($p->PathKtp)->toBeNull()
            ->and($p->IdFileSwafoto)->toBeNull()->and($p->PathSwafoto)->not->toBeNull()
            ->and(BerkasTersimpan())->toBe(2);

        app(KirimPendaftaranMerchant::class)->Menyerah();
        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Draf)->and($p->PesanGalat)->toContain('Coba kirim lagi');
    });

    it('kredensial Partner Payoung ditolak (401): dianggap belum pasti, pesan tidak menyalahkan isian tenant dan tanpa secret', function (): void {
        SetelUlangHttp();
        Http::fake(['api-uat.doku.com'.AWALAN_PARTNER.'/token' => Http::response(['message' => 'Invalid signature '.KUNCI_PARTNER], 401)]);
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Dikirim)
            ->and($p->PesanGalat)->toContain('Kredensial Partner DOKU di sisi Payoung')->not->toContain(KUNCI_PARTNER);
    });

    it('layanan belum tersedia (kredensial Partner kosong): kirim ditolak jelas dan DOKU tidak dipanggil', function (): void {
        IsiKredensialPartner(false);
        Http::fake();
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasErrors('Umum');

        Http::assertNothingSent();
        expect(PendaftaranToko($toko)?->Status)->not->toBe(StatusPendaftaranMerchant::Dikirim);
    });

    it('mode Produksi memakai host api.doku.com', function (): void {
        config()->set('integrasi.PendaftaranMerchant.Pengaturan.Mode', 'Produksi');
        SetelUlangHttp();
        Http::fake(['api.doku.com/*' => Http::response(['token' => 'T'], 200)]);

        expect(app(KlienPartnerDoku::class)->AmbilToken())->toBe('T');
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.doku.com'.AWALAN_PARTNER.'/token');
    });

    it('Check Requirements: GET /file dengan parameter PERSONAL dan tanda tangan tanpa Digest', function (): void {
        SetelUlangHttp();
        Http::fake(['api-uat.doku.com/*' => Http::response(['data' => [['category' => 'PHOTO_PROOF']]], 200)]);

        $hasil = app(KlienPartnerDoku::class)->AmbilPersyaratan();

        expect($hasil)->toHaveKey('data');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && str_starts_with($r->url(), HOST_UAT.AWALAN_PARTNER.'/file?')
            && str_contains($r->url(), 'businessType=PERSONAL') && str_contains($r->url(), 'businessLegalEntity=PERSEORANGAN')
            && str_contains($r->url(), 'brandBusinessLine=RETAIL') && str_contains($r->url(), 'businessContactNationality=ID')
            && ($r->header('Signature')[0] ?? '') === ProtokolDoku::Tandatangani(ID_KLIEN_PARTNER, $r->header('Request-Id')[0], $r->header('Request-Timestamp')[0], AWALAN_PARTNER.'/file', null, KUNCI_PARTNER));
    });
});

describe('validasi isian dan foto', function (): void {
    it('NIK bukan 16 digit, rekening kosong, nomor HP salah, dan bank tak dikenal ditolak', function (): void {
        $toko = TokoMerchant();

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['Nik' => '33720101900001', 'NomorRekening' => '', 'NomorHp' => '12345']))
            ->assertSessionHasErrors(['Nik', 'NomorRekening', 'NomorHp']);

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['IdReferensiBank' => 999999]))
            ->assertSessionHasErrors('IdReferensiBank');

        expect(PendaftaranToko($toko))->toBeNull()->and(BerkasTersimpan())->toBe(0);
    });

    it('foto bukan JPG/PNG atau terlalu besar ditolak validasi; isi byte yang menyamar jadi .jpg ditolak Aksi', function (): void {
        $toko = TokoMerchant();

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant([
            'FotoSwafoto' => UploadedFile::fake()->create('selfie.pdf', 100, 'application/pdf'),
            'FotoBuktiUsaha' => UploadedFile::fake()->image('toko.jpg')->size(6000),
        ]))->assertSessionHasErrors(['FotoSwafoto', 'FotoBuktiUsaha']);

        // Ekstensi .jpg tetapi isinya bukan gambar: lolos validasi nama, ditolak pemeriksaan byte.
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant([
            'FotoKtp' => UploadedFile::fake()->createWithContent('ktp.jpg', 'ini bukan gambar sama sekali'),
        ]))->assertSessionHasErrors('FotoKtp');

        expect(PendaftaranToko($toko))->toBeNull()->and(BerkasTersimpan())->toBe(0);
    });

    it('penyimpan memeriksa isi byte, bukan ekstensi: berkas menyamar jadi .jpg ditolak', function (): void {
        $palsu = UploadedFile::fake()->createWithContent('ktp.jpg', '<?php echo "x"; ?>');

        expect(fn () => app(PenyimpanBerkasKyc::class)->Simpan(1, $palsu, 'Foto KTP'))->toThrow(PelanggaranAturanBisnis::class, 'Foto KTP harus berupa foto JPG atau PNG.');
        expect(BerkasTersimpan())->toBe(0);
    });

    it('data tidak bisa diubah saat Ditinjau', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['NamaUsaha' => 'Nama Lain']))->assertSessionHasErrors('Umum');

        expect(PendaftaranToko($toko)->NamaUsaha)->toBe('Toko Kelontong Berkah Solo');
    });
});

describe('status dari DOKU (Get Business Data)', function (): void {
    function SiapkanDitinjau(TestCase $tes, array $toko): void
    {
        FakePartnerSukses();
        KirimMerchant($tes, $toko)->assertSessionHasNoErrors();
        SetelUlangHttp();
    }

    it('ACTIVE: Aktif, menyimpan Brand ID & shared key (terenkripsi), DisetujuiPada; permintaan GET bertanda tangan dengan parameter PERSONAL', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('ACTIVE', 'ACTIVE', ['id' => 'BSN-0001-AAAA']), 200)]);
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

        $p = app(SegarkanStatusPendaftaranMerchant::class)->Jalankan();

        expect($p->Status)->toBe(StatusPendaftaranMerchant::Aktif)
            ->and($p->IdBrandDoku)->toBe('BRN-0099-BBBB')
            ->and($p->KunciBersama)->toBe('SHARED-KEY-RAHASIA-777')
            ->and($p->DisetujuiPada)->not->toBeNull()->and($p->DiperiksaPada)->not->toBeNull()
            ->and(DB::table('PendaftaranMerchantPembayaran')->value('KunciBersama'))->not->toContain('SHARED-KEY-RAHASIA-777');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && str_starts_with($r->url(), HOST_UAT.AWALAN_PARTNER.'/business/BRN-0099-BBBB?')
            && str_contains($r->url(), 'businessType=PERSONAL')
            && $r->hasHeader('Client-Id', ID_KLIEN_PARTNER)
            && ($r->header('Signature')[0] ?? '') === ProtokolDoku::Tandatangani(ID_KLIEN_PARTNER, $r->header('Request-Id')[0], $r->header('Request-Timestamp')[0], AWALAN_PARTNER.'/business/BRN-0099-BBBB', null, KUNCI_PARTNER));
        expect(LogAudit::query()->where('Peristiwa', 'merchant-pembayaran.status')->count())->toBe(1);
    });

    it('UPDATING tetap Ditinjau; ACTIVE tanpa brand siap tidak dianggap Aktif', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('UPDATING', 'UPDATING'), 200)]);
        expect(app(SegarkanStatusPendaftaranMerchant::class)->Jalankan()->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);

        SetelUlangHttp();
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('ACTIVE', 'UPDATING'), 200)]);
        expect(app(SegarkanStatusPendaftaranMerchant::class)->Jalankan()->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);
    });

    it('ditolak membawa alasan dari DOKU', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        $jawaban = JawabanBisnis('REJECTED', 'REJECTED');
        $jawaban['business']['rejection_reason'] = 'Foto KTP buram, mohon unggah ulang.';
        Http::fake(['api-uat.doku.com/*' => Http::response($jawaban, 200)]);

        $p = app(SegarkanStatusPendaftaranMerchant::class)->Jalankan();

        expect($p->Status)->toBe(StatusPendaftaranMerchant::Ditolak)->and($p->AlasanPenolakan)->toBe('Foto KTP buram, mohon unggah ulang.');

        // Ditolak: simpan data baru memulai pendaftaran dari awal (jejak lama dilepas, foto wajib diunggah ulang).
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant(['Nik' => '', 'NomorRekening' => '']))->assertSessionHasNoErrors();
        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Draf)->and($p->IdBisnisDoku)->toBeNull()->and($p->AlasanPenolakan)->toBeNull()->and($p->CekBerkasLengkap())->toBeTrue();
    });

    it('galat DOKU saat membaca status tidak mengubah status tersimpan', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        Http::fake(['api-uat.doku.com/*' => Http::response(['message' => 'down'], 503)]);

        expect(fn () => app(SegarkanStatusPendaftaranMerchant::class)->Jalankan())->toThrow(Exception::class, 'HTTP 503');
        expect(PendaftaranMerchantPembayaran::query()->first()->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);
    });

    it('penyapu terjadwal hanya memeriksa tenant berstatus Ditinjau yang belum diperiksa dalam 25 menit', function (): void {
        $tokoA = TokoMerchant('Toko A Solo');
        $tokoB = TokoMerchant('Toko B Klaten');
        SiapkanDitinjau($this, $tokoA);
        SiapkanDitinjau($this, $tokoB);
        // B baru saja diperiksa (mis. oleh callback): dilewati.
        BantuanOrganisasi::AturKonteks($tokoB['Tenant']->Id);
        PendaftaranMerchantPembayaran::query()->update(['DiperiksaPada' => now()->subMinutes(5)]);
        BantuanOrganisasi::AturKonteks($tokoA['Tenant']->Id);
        PendaftaranMerchantPembayaran::query()->update(['DiperiksaPada' => now()->subMinutes(40)]);
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('ACTIVE', 'ACTIVE'), 200)]);

        Artisan::call('pembayaran:segarkan-pendaftaran-merchant');

        Http::assertSentCount(1);
        expect(PendaftaranToko($tokoA)->Status)->toBe(StatusPendaftaranMerchant::Aktif)
            ->and(PendaftaranToko($tokoB)->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);
    });
});

describe('webhook callback KYB /webhook/pembayaran/doku-partner', function (): void {
    function TokenCallback(array $toko): string
    {
        return (string) PendaftaranToko($toko)->TokenCallback;
    }

    /** @return TestResponse */
    function KirimCallback(TestCase $tes, string $ref, ?string $kunci = KUNCI_PARTNER, string $idKlien = ID_KLIEN_PARTNER, string $isi = '{"type":"BUSINESS","status":"ACTIVE"}', bool $denganQuery = false)
    {
        $path = '/webhook/pembayaran/doku-partner';
        $waktu = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $tanda = $kunci === null ? 'HMACSHA256=salah' : ProtokolDoku::Tandatangani($idKlien, 'REQ-1', $waktu, $denganQuery ? "{$path}?ref={$ref}" : $path, $isi, $kunci);

        return $tes->call('POST', "{$path}?ref={$ref}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => $idKlien,
            'HTTP_REQUEST_ID' => 'REQ-1',
            'HTTP_REQUEST_TIMESTAMP' => $waktu,
            'HTTP_SIGNATURE' => $tanda,
        ], $isi);
    }

    it('tanda tangan salah atau Client-Id bukan milik kita: 401 dan tidak ada tugas', function (): void {
        Queue::fake();
        $toko = TokoMerchant();
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant());
        $ref = TokenCallback($toko);

        KirimCallback($this, $ref, null)->assertUnauthorized()->assertJsonPath('Galat.Kode', 'TandaTanganTidakSah');
        KirimCallback($this, $ref, KUNCI_PARTNER, 'BRN-ORANG-LAIN')->assertUnauthorized();
        KirimCallback($this, $ref, 'kunci-lain-lagi')->assertUnauthorized();

        Queue::assertNothingPushed();
        expect(PendaftaranToko($toko)->CallbackDiterimaPada)->toBeNull();
    });

    it('kredensial Partner belum diisi: callback ditolak', function (): void {
        IsiKredensialPartner(false);
        Queue::fake();

        KirimCallback($this, 'x', 'apa-saja')->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    it('callback sah hanya menandai dan memicu penyegaran; isi badan (ACTIVE) tidak dipercaya', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        Queue::fake();

        KirimCallback($this, TokenCallback($toko))->assertOk()->assertJson(['Diterima' => true]);

        Queue::assertPushed(SegarkanPendaftaranMerchantTugas::class, fn ($t): bool => $t->idTenant === $toko['Tenant']->Id);
        $p = PendaftaranToko($toko);
        expect($p->Status)->toBe(StatusPendaftaranMerchant::Ditinjau)->and($p->CallbackDiterimaPada)->not->toBeNull();
        Http::assertNothingSent();
    });

    it('tanda tangan yang menyertakan query pada Request-Target juga diterima', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        Queue::fake();

        KirimCallback($this, TokenCallback($toko), denganQuery: true)->assertOk()->assertJson(['Diterima' => true]);

        Queue::assertPushed(SegarkanPendaftaranMerchantTugas::class);
    });

    it('end-to-end: callback sah → status dibaca dari Get Business Data (bukan dari badan callback)', function (): void {
        $toko = TokoMerchant();
        SiapkanDitinjau($this, $toko);
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('UPDATING', 'UPDATING'), 200)]);

        // Badan callback mengaku ACTIVE, tetapi sumber kebenaran (Get Business Data) masih UPDATING.
        KirimCallback($this, TokenCallback($toko))->assertOk();

        Http::assertSentCount(1);
        expect(PendaftaranToko($toko)->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);

        SetelUlangHttp();
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanBisnis('ACTIVE', 'ACTIVE'), 200)]);
        KirimCallback($this, TokenCallback($toko))->assertOk();
        expect(PendaftaranToko($toko)->Status)->toBe(StatusPendaftaranMerchant::Aktif);
    });

    it('token tak dikenal, milik tenant lain dengan awalan salah, atau tanpa ref: 200 Diterima=false tanpa tugas', function (): void {
        Queue::fake();
        $tokoA = TokoMerchant('Toko A Solo');
        $tokoB = TokoMerchant('Toko B Klaten');
        MasukMerchant($this, $tokoA)->post(UrlMerchant('/draf'), IsianMerchant());
        $tokenA = TokenCallback($tokoA);
        // Token A dengan awalan tenant B: pencarian di scope B tidak menemukannya.
        $palsu = base_convert((string) $tokoB['Tenant']->Id, 10, 36).'-'.explode('-', $tokenA, 2)[1];

        KirimCallback($this, $palsu)->assertOk()->assertJson(['Diterima' => false]);
        KirimCallback($this, 'ngawur')->assertOk()->assertJson(['Diterima' => false]);
        KirimCallback($this, '')->assertOk()->assertJson(['Diterima' => false]);

        Queue::assertNothingPushed();
        expect(PendaftaranToko($tokoA)->CallbackDiterimaPada)->toBeNull();
    });
});

describe('pembersihan foto sementara', function (): void {
    it('foto lebih dari 24 jam dihapus dan draf kembali meminta unggah ulang; yang masih baru dibiarkan', function (): void {
        $tokoLama = TokoMerchant('Toko Lama');
        $tokoBaru = TokoMerchant('Toko Baru');
        MasukMerchant($this, $tokoLama)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();
        $this->travel(25)->hours();
        MasukMerchant($this, $tokoBaru)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();
        expect(BerkasTersimpan())->toBe(6);

        Artisan::call('pembayaran:bersihkan-berkas-pendaftaran');

        $lama = PendaftaranToko($tokoLama);
        expect(BerkasTersimpan())->toBe(3)
            ->and($lama->CekBerkasLengkap())->toBeFalse()->and($lama->Status)->toBe(StatusPendaftaranMerchant::Draf)
            ->and($lama->PesanGalat)->toContain('24 jam')
            ->and(PendaftaranToko($tokoBaru)->CekBerkasLengkap())->toBeTrue();
    });

    it('aksi pembersih idempoten dan tidak menyentuh pendaftaran tanpa berkas', function (): void {
        $toko = TokoMerchant();
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();
        $this->travel(30)->hours();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

        expect(app(BersihkanBerkasPendaftaranMerchant::class)->Jalankan())->toBe(1)
            ->and(app(BersihkanBerkasPendaftaranMerchant::class)->Jalankan())->toBe(0);
    });

    it('batal menghapus baris dan foto; tidak bisa batal saat Ditinjau', function (): void {
        $toko = TokoMerchant();
        MasukMerchant($this, $toko)->post(UrlMerchant('/draf'), IsianMerchant())->assertSessionHasNoErrors();
        expect(BerkasTersimpan())->toBe(3);

        MasukMerchant($this, $toko)->post(UrlMerchant('/batal'))->assertSessionHasNoErrors();
        expect(PendaftaranToko($toko))->toBeNull()->and(BerkasTersimpan())->toBe(0);

        FakePartnerSukses();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();
        MasukMerchant($this, $toko)->post(UrlMerchant('/batal'))->assertSessionHasErrors('Umum');
        expect(PendaftaranToko($toko))->not->toBeNull();
    });
});

describe('halaman tenant: props, izin, dan isolasi tenant', function (): void {
    it('halaman hanya memuat NIK & rekening tersamar; tidak ada foto, shared key, ID bisnis, atau token callback', function (): void {
        FakePartnerSukses();
        $toko = TokoMerchant();
        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        $halaman = MasukMerchant($this, $toko)->get(UrlMerchant())->assertOk();
        $halaman->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembayaran/AktivasiQris')
            ->where('LayananTersedia', true)
            ->where('Pendaftaran.Status', 'Ditinjau')
            ->where('Pendaftaran.NikTersamar', '••••0001')
            ->where('Pendaftaran.RekeningTersamar', '••••0123')
            ->where('Pendaftaran.NamaBank', 'Bank Central Asia')
            ->where('Pendaftaran.FotoTersimpan', ['Ktp' => true, 'Swafoto' => true, 'BuktiUsaha' => true])
            ->missing('Pendaftaran.Nik')->missing('Pendaftaran.KunciBersama')->missing('Pendaftaran.IdBisnisDoku')->missing('Pendaftaran.TokenCallback'));
        $isi = $halaman->getContent();

        foreach ([NIK_UJI, REKENING_UJI, 'SHARED-KEY-RAHASIA-777', 'BSN-0001-AAAA', 'BRN-0099-BBBB', KUNCI_PARTNER] as $rahasia) {
            expect($isi)->not->toContain($rahasia);
        }
    });

    it('isolasi tenant: toko lain tidak melihat pendaftaran dan tidak bisa mengubahnya', function (): void {
        FakePartnerSukses();
        $tokoA = TokoMerchant('Toko A Solo');
        $tokoB = TokoMerchant('Toko B Klaten');
        KirimMerchant($this, $tokoA)->assertSessionHasNoErrors();

        MasukMerchant($this, $tokoB)->get(UrlMerchant())->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Pendaftaran', null));
        MasukMerchant($this, $tokoB)->post(UrlMerchant('/batal'))->assertSessionHasNoErrors();

        expect(PendaftaranToko($tokoA))->not->toBeNull()->and(PendaftaranToko($tokoB))->toBeNull();
        BantuanOrganisasi::AturKonteks($tokoB['Tenant']->Id);
        expect(PendaftaranMerchantPembayaran::query()->count())->toBe(0);
        expect(DB::table('PendaftaranMerchantPembayaran')->count())->toBe(1);
    });

    it('izin: peran tanpa pembayaran.gerbang.atur (Kasir) ditolak 403 di semua rute', function (): void {
        $toko = TokoMerchant();
        $kasir = BantuanOrganisasi::TambahAnggota($toko['Tenant']->Id, PeranTenantBawaan::Kasir);
        Http::fake();

        BantuanOrganisasi::Masuk($this, $kasir, $toko['Tenant']->Id)->get(UrlMerchant())->assertForbidden();
        BantuanOrganisasi::Masuk($this, $kasir, $toko['Tenant']->Id)->post(UrlMerchant('/kirim'), IsianMerchant())->assertForbidden();
        BantuanOrganisasi::Masuk($this, $kasir, $toko['Tenant']->Id)->post(UrlMerchant('/draf'), IsianMerchant())->assertForbidden();

        Http::assertNothingSent();
        expect(PendaftaranToko($toko))->toBeNull();
    });

    it('kirim memakai antrean: tugas dipasang dengan Id saja (tanpa NIK, rekening, atau foto di payload)', function (): void {
        Queue::fake();
        $toko = TokoMerchant();

        KirimMerchant($this, $toko)->assertSessionHasNoErrors();

        Queue::assertPushed(KirimPendaftaranMerchantTugas::class, function (KirimPendaftaranMerchantTugas $t) use ($toko): bool {
            $payload = serialize($t);

            return $t->idTenant === $toko['Tenant']->Id && ! str_contains($payload, NIK_UJI) && ! str_contains($payload, REKENING_UJI);
        });
        expect(PendaftaranToko($toko)->Status)->toBe(StatusPendaftaranMerchant::Dikirim);
    });

    it('Ajukan tanpa draf ditolak', function (): void {
        $toko = TokoMerchant();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

        expect(fn () => app(AjukanPendaftaranMerchant::class)->Jalankan())->toThrow(PelanggaranAturanBisnis::class, 'Isi data pendaftaran dulu');
    });
});

describe('skema: tabel sub account lama dihapus, tabel pendaftaran merchant baru', function (): void {
    it('SubAkunPembayaran (pendekatan sub account yang salah arah) tidak ada lagi; tabel baru lengkap dengan kolom penampung QRIS', function (): void {
        expect(Schema::hasTable('SubAkunPembayaran'))->toBeFalse()
            ->and(Schema::hasTable('PendaftaranMerchantPembayaran'))->toBeTrue()
            ->and(Schema::hasColumns('PendaftaranMerchantPembayaran', [
                'Id', 'Uuid', 'IdTenant', 'Penyedia', 'Status', 'NamaPemilik', 'Nik', 'NomorRekening', 'IdReferensiBank', 'IdFileKtp',
                'IdFileSwafoto', 'IdFileBuktiUsaha', 'IdBisnisDoku', 'IdBrandDoku', 'KunciBersama', 'PesanGalat', 'AlasanPenolakan',
                'DikirimPada', 'DisetujuiPada', 'DiperiksaPada', 'IdPedagangQris', 'IdTerminalQris',
            ]))->toBeTrue();
    });

    it('unik per (tenant, penyedia) dan token callback unik', function (): void {
        $toko = TokoMerchant();
        BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
        PendaftaranMerchantPembayaran::query()->create(['TokenCallback' => 'a1-'.str_repeat('x', 40)]);

        expect(fn () => PendaftaranMerchantPembayaran::query()->create(['TokenCallback' => 'b2-'.str_repeat('y', 40)]))->toThrow(QueryException::class);

        $lain = TokoMerchant('Toko Lain');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(fn () => PendaftaranMerchantPembayaran::query()->create(['TokenCallback' => 'a1-'.str_repeat('x', 40)]))->toThrow(QueryException::class);
    });
});
