<?php

declare(strict_types=1);

use App\Domain\Pengelola\AnalitikSitus\Data\RentangAnalitik;
use App\Domain\Pengelola\AnalitikSitus\Layanan\KlienGoogleAnalytics;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiGoogleAnalytics;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;

/*
 * D-215: laporan Google Analytics 4 di konsol. Google tidak pernah dihubungi sungguhan: `Http::fake` meniru penukaran
 * token akun layanan dan Data API. Yang dijaga: izin, bentuk keluaran tiap laporan, pembanding periode, cache (kuota
 * Data API terbatas), dan pesan galat yang bisa ditindaklanjuti tanpa membocorkan kunci.
 */

/** Akun layanan contoh dengan kunci RSA sungguhan, supaya penandatanganan JWT benar-benar diuji. */
function AkunLayananAnalitikUji(): string
{
    $kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($kunci, $pem);

    return json_encode([
        'type' => 'service_account',
        'project_id' => 'payoung-analitik',
        'client_email' => 'analitik@payoung-analitik.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ], JSON_THROW_ON_ERROR);
}

function AktifkanAnalitikUji(string $idProperti = '412345678'): void
{
    config(['integrasi.AnalitikGoogle' => [
        'Penyedia' => 'GoogleAnalytics',
        'Pengaturan' => ['IdProperti' => $idProperti],
        'Kredensial' => ['AkunLayanan' => AkunLayananAnalitikUji()],
    ]]);
}

/**
 * @param  list<string>  $dimensi
 * @param  list<string>  $metrik
 * @return array{dimensionValues: list<array{value: string}>, metricValues: list<array{value: string}>}
 */
function BarisGa(array $dimensi, array $metrik): array
{
    return [
        'dimensionValues' => array_map(fn (string $v): array => ['value' => $v], $dimensi),
        'metricValues' => array_map(fn (string $v): array => ['value' => $v], $metrik),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 03:00:00', 'UTC'));
});

describe('RentangAnalitik', function (): void {
    it('bawaan 28 hari terakhir sampai kemarin. Pembanding sama panjang tepat sebelumnya', function (): void {
        $rentang = RentangAnalitik::Bangun(null, null);

        expect($rentang->UntukTampilan())->toBe(['Dari' => '2026-09-28', 'Sampai' => '2026-10-25', 'Hari' => 28])
            ->and($rentang->Sebelumnya()->UntukTampilan())->toBe(['Dari' => '2026-08-31', 'Sampai' => '2026-09-27', 'Hari' => 28]);
    });

    it('tanggal depan dipotong ke hari ini, terbalik ditukar, dan maksimal 365 hari', function (): void {
        expect(RentangAnalitik::Bangun('2026-10-01', '2027-03-01')->UntukTampilan()['Sampai'])->toBe('2026-10-26')
            ->and(RentangAnalitik::Bangun('2026-10-10', '2026-10-01')->UntukTampilan())->toBe(['Dari' => '2026-10-01', 'Sampai' => '2026-10-10', 'Hari' => 10])
            ->and(RentangAnalitik::Bangun('2020-01-01', '2026-10-20')->UntukTampilan()['Hari'])->toBe(365)
            ->and(RentangAnalitik::Bangun('bukan-tanggal', 'x')->UntukTampilan()['Hari'])->toBe(28);
    });
});

describe('integrasi Google Analytics (P-05)', function (): void {
    it('terdaftar sebagai jenis platform dengan bidang ID properti dan akun layanan', function (): void {
        expect(PenyediaIntegrasi::GoogleAnalytics->AmbilJenis())->toBe(JenisIntegrasi::AnalitikGoogle)
            ->and(JenisIntegrasi::AnalitikGoogle->AmbilPenyedia())->toBe(PenyediaIntegrasi::GoogleAnalytics)
            ->and(JenisIntegrasi::AmbilJenisPlatform())->toContain(JenisIntegrasi::AnalitikGoogle)
            ->and(array_column(PenyediaIntegrasi::GoogleAnalytics->AmbilBidangPengaturan(), 'Kunci'))->toBe(['IdProperti'])
            ->and(array_column(PenyediaIntegrasi::GoogleAnalytics->AmbilBidangKredensial(), 'Kunci'))->toBe(['AkunLayanan'])
            ->and(PenyediaIntegrasi::GoogleAnalytics->AmbilKelasPenguji())->toBe(PengujiGoogleAnalytics::class);
    });

    it('uji berhasil: token ditukar lalu laporan terkecil dijalankan di properti yang dimaksud', function (): void {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => []]),
        ]);

        $hasil = (new PengujiGoogleAnalytics)->Uji(['IdProperti' => 'properties/412345678'], ['AkunLayanan' => AkunLayananAnalitikUji()]);

        expect($hasil->berhasil)->toBeTrue()->and($hasil->pesan)->toContain('412345678')->toContain('analitik@payoung-analitik');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/properties/412345678:runReport')
            && $r->hasHeader('Authorization', 'Bearer ya29.uji'));
    });

    it('ID pengukuran G-… ditolak dengan penjelasan, ID bukan angka dan JSON rusak juga', function (): void {
        Http::fake();
        $akun = AkunLayananAnalitikUji();

        expect((new PengujiGoogleAnalytics)->Uji(['IdProperti' => 'G-ABC123XYZ'], ['AkunLayanan' => $akun])->pesan)->toContain('ID pengukuran')
            ->and((new PengujiGoogleAnalytics)->Uji(['IdProperti' => 'abc'], ['AkunLayanan' => $akun])->berhasil)->toBeFalse()
            ->and((new PengujiGoogleAnalytics)->Uji(['IdProperti' => '412345678'], ['AkunLayanan' => 'bukan json'])->pesan)->toContain('bukan JSON')
            ->and((new PengujiGoogleAnalytics)->Uji(['IdProperti' => '412345678'], ['AkunLayanan' => json_encode(['project_id' => 'x'])])->pesan)->toContain('client_email');
        Http::assertNothingSent();
    });

    it('akun layanan yang belum jadi Viewer dijelaskan beserta alamat emailnya', function (): void {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'forbidden']], 403),
        ]);

        $hasil = (new PengujiGoogleAnalytics)->Uji(['IdProperti' => '412345678'], ['AkunLayanan' => AkunLayananAnalitikUji()]);

        expect($hasil->berhasil)->toBeFalse()
            ->and($hasil->pesan)->toContain('analitik@payoung-analitik.iam.gserviceaccount.com')->toContain('Viewer');
    });
});

describe('halaman /analitik/situs', function (): void {
    it('Analis, Keuangan, dan Mitra & Penjualan boleh melihat; Teknis, Dukungan, dan Konten & Legal ditolak', function (): void {
        foreach ([PeranPengelolaBawaan::Analis, PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::MitraPenjualan] as $peran) {
            $this->flushSession();
            BantuanTenantPengelola::Masuk($this, $peran);
            $this->get(BantuanPengelola::Url('/analitik/situs'))->assertOk();
            $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=ringkasan'))->assertOk();
        }

        foreach ([PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::KontenLegal] as $peran) {
            $this->flushSession();
            BantuanTenantPengelola::Masuk($this, $peran);
            $this->get(BantuanPengelola::Url('/analitik/situs'))->assertForbidden();
            $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=ringkasan'))->assertForbidden();
        }
    });

    it('menandai belum terhubung sampai integrasi aktif, lalu menampilkan ID properti tanpa kredensial', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);

        $this->get(BantuanPengelola::Url('/analitik/situs'))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/AnalitikSitus/Tampil')
            ->where('Status.Aktif', false)
            ->where('Status.IdProperti', null)
            ->where('Rentang.Hari', 28));

        AktifkanAnalitikUji();

        $respons = $this->get(BantuanPengelola::Url('/analitik/situs?dari=2026-10-01&sampai=2026-10-10'));
        $respons->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Status.Aktif', true)
            ->where('Status.IdProperti', '412345678')
            ->where('Rentang.Dari', '2026-10-01')
            ->where('Rentang.Hari', 10));
        expect($respons->getContent())->not->toContain('BEGIN PRIVATE KEY')->not->toContain('payoung-analitik.iam');
    });
});

describe('data laporan', function (): void {
    it('belum terhubung dan laporan tak dikenal dijawab dengan pesan, tanpa menghubungi Google', function (): void {
        Http::fake();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);

        $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=ringkasan'))->assertOk()->assertJsonPath('Galat', 'Google Analytics belum dihubungkan. Atur di Integrasi › Google Analytics.');
        $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=rahasia'))->assertStatus(422);
        Http::assertNothingSent();
    });

    it('ringkasan memuat kartu dengan pembanding periode sebelumnya dan tren harian bertanggal', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::response(['reports' => [
                ['rows' => [BarisGa([], ['120', '80', '150', '100', '0.6667', '0.3333', '95.5', '400', '900', '12'])]],
                ['rows' => [BarisGa([], ['100', '70', '130', '90', '0.6', '0.4', '80', '350', '800', '10'])]],
                ['rows' => [BarisGa(['20261024'], ['10', '12', '30', '1']), BarisGa(['20261025'], ['15', '18', '40', '2'])]],
            ]]),
        ]);

        $json = $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=ringkasan'))->assertOk()->json();
        $kartu = collect($json['Kartu'])->keyBy('Kunci');

        expect($json['Laporan'])->toBe('ringkasan')
            ->and($kartu['PenggunaAktif']['Nilai'])->toBe('120')
            ->and($kartu['PenggunaAktif']['Pembanding'])->toBe('100')
            ->and($kartu['TingkatKeterlibatan']['Jenis'])->toBe('Persen')
            ->and($kartu['TingkatKeterlibatan']['Nilai'])->toBe('0.6667')
            ->and($kartu['RataRataDurasiSesi']['Jenis'])->toBe('Durasi')
            ->and($kartu['EventKunci']['Nilai'])->toBe('12')
            ->and($json['Tabel'][0]['Id'])->toBe('harian')
            ->and($json['Tabel'][0]['Baris'][0]['Tanggal'])->toBe('2026-10-24')
            ->and($json['Tabel'][0]['Baris'][1]['PenggunaAktif'])->toBe('15')
            ->and($json['Rentang'])->toBe(['Dari' => '2026-09-28', 'Sampai' => '2026-10-25', 'Hari' => 28])
            ->and($json['Pembanding']['Sampai'])->toBe('2026-09-27');

        // Satu panggilan batch berisi tiga laporan: periode ini, periode sebelumnya, dan tren harian.
        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), ':batchRunReports')) {
                return false;
            }

            $daftar = $r['requests'];

            return count($daftar) === 3
                && $daftar[0]['dateRanges'][0] === ['startDate' => '2026-09-28', 'endDate' => '2026-10-25']
                && $daftar[1]['dateRanges'][0] === ['startDate' => '2026-08-31', 'endDate' => '2026-09-27']
                && $daftar[2]['dimensions'][0]['name'] === 'date';
        });
    });

    it('tanpa lalu lintas, angka 0 dan tabel kosong (bukan galat)', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::response(['reports' => [['rowCount' => 0], ['rowCount' => 0], ['rowCount' => 0]]]),
        ]);

        $json = $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=ringkasan'))->assertOk()->json();

        expect(collect($json['Kartu'])->pluck('Nilai')->unique()->all())->toBe(['0'])
            ->and($json['Tabel'][0]['Baris'])->toBe([]);
    });

    it('real-time menjawab pengguna aktif 30 dan 5 menit serta lima tabel', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'oauth2')) {
                return Http::response(['access_token' => 'ya29.uji']);
            }

            if (! isset($r['dimensions'])) {
                return Http::response(['rows' => [BarisGa([], [isset($r['minuteRanges']) ? '3' : '9'])]]);
            }

            return Http::response(['rows' => [BarisGa(['Indonesia'], ['7'])]]);
        });

        $json = $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=realtime'))->assertOk()->json();

        expect(collect($json['Kartu'])->pluck('Nilai', 'Kunci')->all())->toBe(['PenggunaAktif30Menit' => '9', 'PenggunaAktif5Menit' => '3'])
            ->and(collect($json['Tabel'])->pluck('Id')->all())->toBe(['per-menit', 'negara', 'halaman', 'perangkat', 'event'])
            ->and($json['Tabel'][1]['Baris'][0])->toBe(['Negara' => 'Indonesia', 'PenggunaAktif' => '7']);
    });

    it('akuisisi, halaman, event, pengguna, dan teknologi masing-masing satu panggilan batch dengan tabel bernama', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        $harapan = [
            'akuisisi' => ['saluran', 'sumber-medium', 'kampanye'],
            'halaman' => ['halaman', 'landing'],
            'event' => ['event'],
            'pengguna' => ['negara', 'kota', 'bahasa', 'baru-kembali'],
            'teknologi' => ['perangkat', 'sistem-operasi', 'peramban', 'resolusi', 'platform'],
        ];

        foreach ($harapan as $laporan => $idTabel) {
            Http::fake([
                'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
                'analyticsdata.googleapis.com/*' => Http::response(['reports' => array_map(
                    fn (): array => ['rows' => [BarisGa(['new', 'x', 'y', 'z'], ['5', '4', '3', '2', '0.5', '1', '1', '1'])]],
                    $idTabel,
                )]),
            ]);

            $json = $this->getJson(BantuanPengelola::Url("/analitik/situs/data?laporan={$laporan}"))->assertOk()->json();

            expect(collect($json['Tabel'])->pluck('Id')->all())->toBe($idTabel, $laporan);
            expect($json['Tabel'][0]['Kolom'][0]['Jenis'])->toBe('Teks');
        }
    });

    it('hasil disimpan di cache supaya kuota Data API hemat. Segarkan=1 mengambil ulang', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::response(['reports' => [['rows' => [BarisGa(['Direct'], ['1', '1', '1', '1', '0.5', '1'])]], [], []]]),
        ]);

        $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=akuisisi'))->assertOk();
        $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=akuisisi'))->assertOk();
        Http::assertSentCount(2); // satu penukaran token + satu batch

        $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=akuisisi&segarkan=1'))->assertOk();
        Http::assertSentCount(3); // token masih berlaku di cache, hanya batch yang diulang
    });

    it('Google menolak: pesan yang bisa ditindaklanjuti, tanpa kunci atau token', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.rahasia-token']),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'The caller does not have permission']], 403),
        ]);

        $respons = $this->getJson(BantuanPengelola::Url('/analitik/situs/data?laporan=halaman'))->assertOk();

        expect($respons->json('Galat'))->toContain('analitik@payoung-analitik.iam.gserviceaccount.com')->toContain('Viewer')
            ->and($respons->getContent())->not->toContain('ya29.rahasia-token')->not->toContain('PRIVATE KEY');
    });

    it('kuota habis dan permintaan ditolak dijelaskan. Galat tidak ikut tersimpan di cache', function (): void {
        AktifkanAnalitikUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.uji']),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'quota']], 429)
                ->push(['error' => ['message' => 'Dimension tidak cocok']], 400)
                ->push(['reports' => [['rows' => []]]]),
        ]);

        $url = BantuanPengelola::Url('/analitik/situs/data?laporan=event');

        expect($this->getJson($url)->json('Galat'))->toContain('Kuota Google Analytics')
            ->and($this->getJson($url)->json('Galat'))->toContain('Dimension tidak cocok');
        $this->getJson($url)->assertOk()->assertJsonPath('Laporan', 'event');
    });
});

it('klien membersihkan ID properti dari awalan properties/ dan menolak ID pengukuran', function (): void {
    expect(KlienGoogleAnalytics::BersihkanIdProperti(' properties/412345678 '))->toBe('412345678')
        ->and(KlienGoogleAnalytics::BersihkanIdProperti('G-ABC123'))->toBe('')
        ->and(KlienGoogleAnalytics::BersihkanIdProperti('12'))->toBe('');
});
