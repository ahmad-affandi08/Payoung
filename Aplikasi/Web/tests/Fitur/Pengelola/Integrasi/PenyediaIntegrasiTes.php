<?php

declare(strict_types=1);

use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorFonnte;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\StatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Layanan\PenerapKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * v2.04 katalog penyedia integrasi (P-05): tim platform memilih penyedia per jenis (email lewat SMTP berbagai
 * penyedia, gerbang pembayaran QRIS dinamis, WhatsApp resmi & tidak resmi), mengisi bidangnya, menguji, lalu
 * mengaktifkan. Ganti penyedia = kredensial wajib diisi ulang dan status kembali "Belum diuji".
 */

function MasukTeknisPenyedia($tes): void
{
    $tes->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function KonfigurasiJenis(string $jenis): KonfigurasiIntegrasi
{
    return KonfigurasiIntegrasi::query()->where('Jenis', $jenis)->where('Lingkungan', 'Staging')->sole();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'Asia/Jakarta'));
});

describe('v2.04 katalog penyedia', function (): void {
    it('setiap jenis punya daftar penyedia. Semua penyedia email memakai bidang SMTP dengan nilai bawaan', function (): void {
        expect(count(JenisIntegrasi::Email->AmbilDaftarPenyedia()))->toBeGreaterThanOrEqual(15)
            ->and(array_map(fn ($p) => $p->value, JenisIntegrasi::GerbangPembayaran->AmbilDaftarPenyedia()))->toBe(['Doku'])
            ->and(array_map(fn ($p) => $p->value, JenisIntegrasi::Whatsapp->AmbilDaftarPenyedia()))->toBe(['MetaCloud', 'Fonnte', 'Wablas', 'StarSender', 'Watzap'])
            ->and(PenyediaIntegrasi::MetaCloud->CekResmi())->toBeTrue()
            ->and(PenyediaIntegrasi::Fonnte->CekResmi())->toBeFalse();

        $bidang = collect(PenyediaIntegrasi::SendGrid->AmbilBidangPengaturan())->keyBy('Kunci');
        expect($bidang['Host']['Bawaan'])->toBe('smtp.sendgrid.net')
            ->and($bidang['NamaPengguna']['Bawaan'])->toBe('apikey')
            ->and(PenyediaIntegrasi::SendGrid->AmbilBidangKredensial()[0]['Label'])->toBe('API key SendGrid');

        foreach (PenyediaIntegrasi::cases() as $penyedia) {
            expect($penyedia->AmbilLabel())->not->toBe('')
                ->and(class_exists($penyedia->AmbilKelasPenguji()))->toBeTrue();
        }

        MasukTeknisPenyedia($this);
        $this->get(BantuanPengelola::Url('/integrasi'))->assertInertia(fn (AssertableInertia $halaman) => $halaman
            // v2.06: gerbang pembayaran bukan lagi slot platform; tampil sebagai katalog untuk tenant.
            ->where('Integrasi.6.Jenis', 'Whatsapp')
            ->where('Integrasi.6.DaftarPenyedia.1.Resmi', false)
            ->has('GerbangTenant', 1)
            ->where('GerbangTenant.0.Penyedia', 'Doku')
            ->where('GerbangTenant.0.Diizinkan', true));
    });

    it('pilih SendGrid untuk email. Ganti ke Brevo mewajibkan kredensial baru dan mengulang uji', function (): void {
        MasukTeknisPenyedia($this);
        $dasar = [
            'Jenis' => 'Email', 'Lingkungan' => 'Staging', 'Penyedia' => 'SendGrid',
            'Pengaturan' => ['Host' => 'smtp.sendgrid.net', 'Port' => 587, 'Enkripsi' => 'Tls', 'NamaPengguna' => 'apikey', 'AlamatPengirim' => 'noreply@contoh.id', 'NamaPengirim' => 'Payoung'],
            'Kredensial' => ['KataSandi' => 'SG.kunci-sendgrid-rahasia-1234'], 'RotasiSetiapHari' => 90,
        ];
        $this->post(BantuanPengelola::Url('/integrasi'), $dasar)->assertSessionHasNoErrors();
        $konfigurasi = KonfigurasiJenis('Email');
        expect($konfigurasi->Penyedia)->toBe(PenyediaIntegrasi::SendGrid);
        $konfigurasi->forceFill(['Status' => StatusIntegrasi::Terhubung, 'Aktif' => true])->save();

        $brevo = ['Penyedia' => 'Brevo', 'Kredensial' => ['KataSandi' => '']] + $dasar;
        $brevo['Pengaturan']['Host'] = 'smtp-relay.brevo.com';
        $this->post(BantuanPengelola::Url('/integrasi'), $brevo)->assertSessionHasErrors('Kredensial.KataSandi');

        $brevo['Kredensial'] = ['KataSandi' => 'xsmtpsib-kunci-brevo-5678'];
        $this->post(BantuanPengelola::Url('/integrasi'), $brevo)->assertSessionHasNoErrors();
        $konfigurasi->refresh();
        expect($konfigurasi->Penyedia)->toBe(PenyediaIntegrasi::Brevo)
            ->and($konfigurasi->Status)->toBe(StatusIntegrasi::BelumDiuji)
            ->and($konfigurasi->Aktif)->toBeFalse()
            ->and($konfigurasi->Kredensial)->toBe(['KataSandi' => 'xsmtpsib-kunci-brevo-5678'])
            ->and(LogAuditPengelola::query()->where('Aksi', 'integrasi.ubah')->sole()->NilaiBaru['Penyedia'])->toBe('Brevo');
    });

    it('penyedia dari jenis lain ditolak', function (): void {
        MasukTeknisPenyedia($this);
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Whatsapp', 'Lingkungan' => 'Staging', 'Penyedia' => 'Doku',
            'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], 'Kredensial' => ['KunciRahasia' => 'SK-doku-xxxxxxxx'], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasErrors('Penyedia');
        expect(KonfigurasiIntegrasi::query()->count())->toBe(0);
    });

    it('penyedia gerbang toko yang sudah dihapus (Midtrans, Xendit, Tripay, Duitku, iPaymu) bukan penyedia integrasi lagi', function (string $lama): void {
        expect(PenyediaIntegrasi::tryFrom($lama))->toBeNull();

        MasukTeknisPenyedia($this);
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'GerbangPembayaran', 'Lingkungan' => 'Staging', 'Penyedia' => $lama,
            'Pengaturan' => [], 'Kredensial' => [], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasErrors('Penyedia');
        expect(KonfigurasiIntegrasi::query()->count())->toBe(0);
    })->with(['Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu']);

    it('DOKU Partner (pendaftaran merchant) adalah jenis tersendiri: Mode + Brand ID di pengaturan, secret key terenkripsi di kredensial', function (): void {
        expect(array_map(fn ($p) => $p->value, JenisIntegrasi::PendaftaranMerchant->AmbilDaftarPenyedia()))->toBe(['DokuPartner'])
            ->and(PenyediaIntegrasi::DokuPartner->AmbilJenis())->toBe(JenisIntegrasi::PendaftaranMerchant)
            ->and(JenisIntegrasi::PendaftaranMerchant->AmbilPenyedia())->toBe(PenyediaIntegrasi::DokuPartner)
            ->and(JenisIntegrasi::PendaftaranMerchant->value)->toBe('PendaftaranMerchant')
            ->and(PenyediaIntegrasi::DokuPartner->AmbilLabel())->toBe('DOKU Partner (pendaftaran merchant)')
            ->and(array_column(PenyediaIntegrasi::DokuPartner->AmbilBidangPengaturan(), 'Kunci'))->toBe(['Mode', 'IdKlien'])
            ->and(array_column(PenyediaIntegrasi::DokuPartner->AmbilBidangKredensial(), 'Kunci'))->toBe(['KunciRahasia'])
            ->and(PenyediaIntegrasi::DokuPartner->AmbilPenyediaGerbang())->toBeNull()
            ->and(in_array(JenisIntegrasi::PendaftaranMerchant, JenisIntegrasi::AmbilJenisPlatform(), true))->toBeTrue();
    });

    it('DOKU untuk tagihan langganan platform adalah satu-satunya penyedia gerbang billing (jalur terpisah dari gerbang toko)', function (): void {
        expect(array_map(fn ($p) => $p->value, JenisIntegrasi::GerbangBilling->AmbilDaftarPenyedia()))->toBe(['DokuBilling'])
            ->and(PenyediaIntegrasi::DokuBilling->AmbilJenis())->toBe(JenisIntegrasi::GerbangBilling)
            ->and(JenisIntegrasi::GerbangBilling->AmbilPenyedia())->toBe(PenyediaIntegrasi::DokuBilling)
            // Penyedia Midtrans untuk tagihan langganan sudah dihapus.
            ->and(PenyediaIntegrasi::tryFrom('MidtransBilling'))->toBeNull()
            // Bidang: Client ID (pengaturan, tidak rahasia) + Mode; Secret key (kredensial, terenkripsi).
            ->and(array_column(PenyediaIntegrasi::DokuBilling->AmbilBidangPengaturan(), 'Kunci'))->toBe(['Mode', 'IdKlien', 'IdPedagangQris', 'IdTerminalQris', 'KodePosQris', 'KanalVa', 'DompetDana', 'DompetShopeePay'])
            ->and(array_column(PenyediaIntegrasi::DokuBilling->AmbilBidangKredensial(), 'Kunci'))->toBe(['KunciRahasia'])
            // Bukan penyedia gerbang toko: tidak muncul di katalog `PenyediaGerbang` (webhook per tenant).
            ->and(PenyediaIntegrasi::DokuBilling->AmbilPenyediaGerbang())->toBeNull();
    });

    it('v2.06: gerbang pembayaran tidak lagi disimpan di tingkat platform (akun merchant milik tenant)', function (): void {
        MasukTeknisPenyedia($this);
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'GerbangPembayaran', 'Lingkungan' => 'Staging', 'Penyedia' => 'Doku',
            'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-001'], 'Kredensial' => ['KunciRahasia' => 'SK-doku-rahasia-4321'], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasErrors('Jenis');

        expect(KonfigurasiIntegrasi::query()->count())->toBe(0)
            ->and(DB::table('GerbangPembayaranTenant')->count())->toBe(0);
    });

    it('WhatsApp tidak resmi Fonnte: perangkat tersambung = berhasil. Belum tersambung = gagal. Aktif di runtime', function (): void {
        Http::fakeSequence('api.fonnte.com/*')
            ->push(['status' => true, 'device_status' => 'disconnect'])
            ->push(['status' => true, 'device_status' => 'connect']);
        MasukTeknisPenyedia($this);
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Whatsapp', 'Lingkungan' => 'Staging', 'Penyedia' => 'Fonnte', 'Pengaturan' => [],
            'Kredensial' => ['Token' => 'token-fonnte-rahasia-777'], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasNoErrors();
        $konfigurasi = KonfigurasiJenis('Whatsapp');
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/uji"))->assertSessionHasErrors('Umum');
        expect($konfigurasi->refresh()->HasilUji['Pesan'])->toContain('belum tersambung');
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/uji"))->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/aktifkan"))->assertSessionHasNoErrors();

        PenerapKonfigurasiIntegrasi::LupakanCache();
        app(PenerapKonfigurasiIntegrasi::class)->Terapkan();
        expect(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->toBeInstanceOf(AdaptorFonnte::class);
    });

    it('kredensial opsional (secret key Wablas) boleh kosong. Bidang pengaturan opsional (templat Meta) boleh kosong', function (): void {
        MasukTeknisPenyedia($this);
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Whatsapp', 'Lingkungan' => 'Staging', 'Penyedia' => 'Wablas', 'Pengaturan' => ['Domain' => 'https://jkt.wablas.com'],
            'Kredensial' => ['Token' => 'token-wablas-rahasia-12345', 'KunciRahasia' => ''], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasNoErrors();
        expect(KonfigurasiJenis('Whatsapp')->Kredensial)->toBe(['Token' => 'token-wablas-rahasia-12345']);

        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Whatsapp', 'Lingkungan' => 'Staging', 'Penyedia' => 'MetaCloud',
            'Pengaturan' => ['IdNomorTelepon' => '1234567890', 'VersiApi' => 'v21.0', 'NamaTemplatStruk' => '', 'BahasaTemplat' => 'id'],
            'Kredensial' => ['TokenAkses' => 'EAAG-token-meta-rahasia-000'], 'RotasiSetiapHari' => 90,
        ])->assertSessionHasNoErrors();
        expect(KonfigurasiJenis('Whatsapp')->Penyedia)->toBe(PenyediaIntegrasi::MetaCloud);
    });
});
