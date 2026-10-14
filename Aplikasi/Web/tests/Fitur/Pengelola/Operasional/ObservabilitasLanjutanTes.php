<?php

declare(strict_types=1);

use App\Domain\Bersama\Sinkron\Layanan\PencatatGalatSinkron;
use App\Domain\Integrasi\ApiPublik\Enum\StatusKirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Model\KirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Model\WebhookTenant;
use App\Domain\Pengelola\Operasional\Enum\JenisAlertOperasional;
use App\Domain\Pengelola\Operasional\Kueri\GalatSinkronTerbanyak;
use App\Domain\Pengelola\Operasional\Kueri\RuteApiTerlambat;
use App\Domain\Pengelola\Operasional\Kueri\WebhookGagalPerTenant;
use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use App\Domain\Pengelola\Operasional\Layanan\PengaturanAlertOperasional;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Operasional\Model\MetrikGalatSinkron;
use App\Domain\Pengelola\Operasional\Model\MetrikRuteApi;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PGL-20 lanjutan (PRD v6.28, D-148): rute API terlambat per jam, penolakan sinkron terbanyak, webhook keluar gagal per
 * tenant, dan tautan runbook per alert. Semua dari penghitung cache yang disiram detak scheduler; tanpa kueri database di
 * jalur permintaan dan tanpa data usaha tenant di tabel metrik.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:30', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    Cache::flush();
});

/** Catat [jumlah] permintaan ke [rute] dengan durasi [ms]. */
function CatatRute(string $rute, int $jumlah, int $ms, int $status = 200, string $kelompok = 'Pos'): void
{
    $pencatat = app(PencatatMetrikApi::class);

    for ($i = 0; $i < $jumlah; $i++) {
        $pencatat->Catat($kelompok, $status, $ms, namaRute: $rute);
    }
}

describe('Metrik per rute', function (): void {
    it('permintaan dihitung per nama rute di cache lalu disiram per jam ke MetrikRuteApi; rute lambat diperingkat dahulu', function (): void {
        CatatRute('pos.sinkron.kirim', 25, 900);
        CatatRute('pos.sinkron.kirim', 5, 50, 500);
        CatatRute('pos.data-awal', 40, 30);
        CatatRute('pos.katalog', 10, 5000);

        $this->travel(1)->minutes();
        expect(app(PencatatMetrikApi::class)->Vaskan())->toBe(1);

        $sinkron = MetrikRuteApi::query()->where('NamaRute', 'pos.sinkron.kirim')->sole();
        expect($sinkron->Kelompok)->toBe('Pos')
            ->and($sinkron->JumlahPermintaan)->toBe(30)
            ->and($sinkron->JumlahGalatServer)->toBe(5)
            ->and($sinkron->TotalDurasiMs)->toBe(22750)
            ->and([$sinkron->Hingga100Ms, $sinkron->Hingga800Ms, $sinkron->Hingga1600Ms])->toBe([5, 0, 25])
            ->and($sinkron->Jam->toIso8601String())->toBe(now()->utc()->startOfHour()->toIso8601String());

        $hasil = app(RuteApiTerlambat::class)->Ambil(24);

        // pos.katalog hanya 10 permintaan (< 20): tidak diperingkat. Sinkron p95 1.600 ms mendahului data-awal 100 ms.
        expect(array_column($hasil, 'NamaRute'))->toBe(['pos.sinkron.kirim', 'pos.data-awal'])
            ->and($hasil[0]['P95Ms'])->toBe(1600)
            ->and($hasil[0]['GalatServer'])->toBe(5)
            ->and($hasil[0]['RataRataMs'])->toBe(758)
            ->and($hasil[1]['P95Ms'])->toBe(100);
    });

    it('penyiraman berulang di jam yang sama menjumlahkan, dan data lewat retensi dipangkas', function (): void {
        CatatRute('pos.data-awal', 2, 40);
        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->Vaskan();
        CatatRute('pos.data-awal', 3, 40);
        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->Vaskan();

        expect(MetrikRuteApi::query()->sole()->JumlahPermintaan)->toBe(5);

        MetrikRuteApi::query()->create(['Kelompok' => 'Pos', 'NamaRute' => 'pos.lama', 'Jam' => now()->subDays(8), 'JumlahPermintaan' => 50]);
        app(PencatatMetrikApi::class)->Vaskan();
        expect(MetrikRuteApi::query()->where('NamaRute', 'pos.lama')->exists())->toBeFalse();
    });

    it('kardinalitas dibatasi: nama rute tidak sah menjadi tanpa-nama dan rute melebihi batas digabung ke lainnya', function (): void {
        $pencatat = app(PencatatMetrikApi::class);
        $pencatat->Catat('Pos', 200, 10, namaRute: 'pos/produk/01K5ULIDTENANTINVALID');
        $pencatat->Catat('Pos', 200, 10);

        for ($i = 0; $i < 85; $i++) {
            $pencatat->Catat('Pemilik', 200, 10, namaRute: "pemilik.rute-{$i}");
        }

        $this->travel(1)->minutes();
        $pencatat->Vaskan();

        expect(MetrikRuteApi::query()->where('NamaRute', PencatatMetrikApi::RUTE_TANPA_NAMA)->sole()->JumlahPermintaan)->toBe(2);
        $pemilik = MetrikRuteApi::query()->where('Kelompok', 'Pemilik')->pluck('JumlahPermintaan', 'NamaRute');
        expect($pemilik)->toHaveCount(81)->and($pemilik[PencatatMetrikApi::RUTE_LAINNYA])->toBe(5);
    });

    it('API nyata dicatat dengan nama rutenya, dan jalur web tidak', function (): void {
        $this->getJson('/api/pos/v1/data-awal')->assertUnauthorized();
        $this->get('/masuk');

        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->Vaskan();

        expect(MetrikRuteApi::query()->pluck('JumlahPermintaan', 'NamaRute')->all())->toBe(['pos.data-awal' => 1]);
    });
});

describe('Penolakan sinkron terbanyak', function (): void {
    it('item ditolak server dihitung per jenis dan kode, disiram per jam, dan diurutkan terbanyak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);

        $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [
            ['Jenis' => 'Jenis.TidakAda', 'Uuid' => BantuanKasir::Uuid(), 'Data' => []],
            ['Jenis' => 'Jenis.TidakAda', 'Uuid' => BantuanKasir::Uuid(), 'Data' => []],
            ['Jenis' => 'Jenis.LainTidakAda', 'Uuid' => BantuanKasir::Uuid(), 'Data' => []],
        ]);
        expect(array_column($hasil, 0))->toBe(['Ditolak', 'Ditolak', 'Ditolak']);

        $this->travel(1)->minutes();
        expect(app(PencatatMetrikApi::class)->VaskanGalatSinkron(app(PencatatGalatSinkron::class)))->toBe(2);

        expect(MetrikGalatSinkron::query()->count())->toBe(2);
        $daftar = app(GalatSinkronTerbanyak::class)->Ambil(24);
        expect($daftar)->toBe([
            ['JenisItem' => 'Jenis.TidakAda', 'KodeGalat' => 'JenisItemTidakDikenal', 'Jumlah' => 2],
            ['JenisItem' => 'Jenis.LainTidakAda', 'KodeGalat' => 'JenisItemTidakDikenal', 'Jumlah' => 1],
        ]);

        // Dipanen sekali: penyiraman kedua tidak menggandakan.
        expect(app(PencatatMetrikApi::class)->VaskanGalatSinkron(app(PencatatGalatSinkron::class)))->toBe(0)
            ->and((int) MetrikGalatSinkron::query()->sum('Jumlah'))->toBe(3);
    });

    it('pasangan berbeda dibatasi per menit dan data lewat retensi dipangkas', function (): void {
        $pencatat = app(PencatatGalatSinkron::class);

        for ($i = 0; $i < 70; $i++) {
            $pencatat->Catat('Penjualan.Buat', "Kode{$i}");
        }

        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->VaskanGalatSinkron($pencatat);

        expect(MetrikGalatSinkron::query()->count())->toBe(61)
            ->and(MetrikGalatSinkron::query()->where('JenisItem', 'Lainnya')->sole()->Jumlah)->toBe(10);

        MetrikGalatSinkron::query()->create(['Jam' => now()->subDays(8), 'JenisItem' => 'Lama', 'KodeGalat' => 'X', 'Jumlah' => 4]);
        app(PencatatMetrikApi::class)->VaskanGalatSinkron($pencatat);
        expect(MetrikGalatSinkron::query()->where('JenisItem', 'Lama')->exists())->toBeFalse();
    });
});

describe('Webhook keluar gagal per tenant', function (): void {
    it('menghitung kiriman Gagal 24 jam terakhir per tenant, mengurutkan terbanyak, dan mengaudit pembacaan lintas tenant', function (): void {
        $a = BantuanOrganisasi::BuatTenant('Toko Alfa Webhook');
        $b = BantuanOrganisasi::BuatTenant('Toko Beta Webhook');

        foreach ([[$a, 1, StatusKirimanWebhook::Gagal], [$b, 3, StatusKirimanWebhook::Gagal], [$b, 2, StatusKirimanWebhook::Terkirim]] as [$t, $jumlah, $status]) {
            BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
            $webhook = WebhookTenant::query()->create(['Nama' => 'Sistem gudang', 'Url' => 'https://gudang.contoh.co.id/h', 'Rahasia' => 'rahasia', 'Peristiwa' => ['penjualan.selesai'], 'DibuatOleh' => $t['Pemilik']->Id]);

            for ($i = 0; $i < $jumlah; $i++) {
                KirimanWebhook::query()->create(['IdWebhookTenant' => $webhook->Id, 'Peristiwa' => 'penjualan.selesai', 'IdDokumen' => $i + 1, 'KunciPeristiwa' => '', 'Muatan' => ['x' => 1], 'Status' => $status, 'Percobaan' => 6]);
            }
        }

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        KirimanWebhook::query()->update(['DiubahPada' => now()->subDays(3)]);
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);

        $daftar = app(WebhookGagalPerTenant::class)->Ambil(24);

        expect($daftar)->toHaveCount(1)
            ->and($daftar[0]['NamaTenant'])->toBe('Toko Beta Webhook')
            ->and($daftar[0]['JumlahGagal'])->toBe(3);
        expect(LogAuditPengelola::query()->where('Alasan', 'like', '%webhook keluar gagal%')->exists())->toBeTrue();
    });
});

describe('Dasbor dan tautan runbook', function (): void {
    it('Teknis melihat bagian lanjutan dan jangkar alert; mengatur tautan runbook yang diaudit; alamat tidak aman ditolak; peran lain ditolak', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $this->actingAs($teknis, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        CatatRute('pos.sinkron.kirim', 25, 900);
        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->Vaskan();
        AlertOperasional::query()->create(['Kunci' => 'OutboxMacet', 'Tingkat' => 'Peringatan', 'Pesan' => 'Uji', 'MulaiPada' => now()]);

        $this->get(BantuanPengelola::Url('/operasional'))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Operasional/Dasbor')
            ->where('Dasbor.Lanjutan.JamJendela', 24)
            ->where('Dasbor.Lanjutan.RuteTerlambat.0.NamaRute', 'pos.sinkron.kirim')
            ->where('Dasbor.Lanjutan.RuteTerlambat.0.P95Ms', 1600)
            ->has('Dasbor.Lanjutan.GalatSinkron')
            ->has('Dasbor.Lanjutan.WebhookGagal')
            ->where('Dasbor.Runbook.Tautan', null)
            ->where('Dasbor.Alert.Aktif.0.Jangkar', 'outbox-macet'));

        $this->post(BantuanPengelola::Url('/operasional/tautan-runbook'), ['TautanRunbook' => 'https://wiki.payoung.id/runbook'])->assertSessionHasNoErrors();
        expect(PengaturanPlatform::query()->where('Kunci', 'operasional.tautan-runbook')->value('Nilai'))->toBe('https://wiki.payoung.id/runbook');
        $audit = LogAuditPengelola::query()->where('Aksi', 'operasional.runbook.atur')->sole();
        expect($audit->NilaiBaru)->toBe(['Tautan' => 'https://wiki.payoung.id/runbook']);

        foreach (['http://wiki.payoung.id/runbook', 'https://u:p@wiki.payoung.id/runbook', 'https://wiki.payoung.id/runbook#sudah-ada', 'javascript:alert(1)', 'https://wiki payoung.id/x'] as $buruk) {
            $this->post(BantuanPengelola::Url('/operasional/tautan-runbook'), ['TautanRunbook' => $buruk])->assertSessionHasErrors();
        }

        expect(PengaturanPlatform::query()->where('Kunci', 'operasional.tautan-runbook')->value('Nilai'))->toBe('https://wiki.payoung.id/runbook');

        $this->get(BantuanPengelola::Url('/operasional'))->assertInertia(fn (AssertableInertia $h) => $h->where('Dasbor.Runbook.Tautan', 'https://wiki.payoung.id/runbook'));

        $this->post(BantuanPengelola::Url('/operasional/tautan-runbook'), ['TautanRunbook' => ''])->assertSessionHasNoErrors();
        $this->get(BantuanPengelola::Url('/operasional'))->assertInertia(fn (AssertableInertia $h) => $h->where('Dasbor.Runbook.Tautan', null));

        $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->post(BantuanPengelola::Url('/operasional/tautan-runbook'), ['TautanRunbook' => 'https://wiki.payoung.id/x'])->assertForbidden();
    });

    it('jangkar runbook mengikuti kunci alert dan dokumen runbook memuat semua jangkarnya', function (): void {
        $dokumen = (string) file_get_contents(base_path('../../Panduan/RunbookOperasional.md'));

        foreach (['PenjadwalBerhenti', 'AntreanTertunda', 'BackupTerlambat', 'ErrorRateTinggi', 'LatensiPosTinggi', 'OutboxMacet'] as $kunci) {
            $jangkar = PengaturanAlertOperasional::JangkarRunbook($kunci);
            expect($dokumen)->toContain("## {$jangkar}");
        }

        foreach (JenisAlertOperasional::cases() as $jenis) {
            expect($dokumen)->toContain('## '.PengaturanAlertOperasional::JangkarRunbook($jenis->value));
        }
    });
});
