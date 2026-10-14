<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Kueri\VersiAplikasiPerangkat;
use App\Domain\Tenant\Model\RilisAplikasi;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['aplikasi.Pos.Android.VersiTerbaru' => '1.4.0', 'aplikasi.Pos.Android.VersiMinimal' => '1.0.0']);
});

afterEach(fn () => Carbon::setTestNow());

function MasukSebagaiTeknisRilis(TestCase $tes, ?PenggunaPengelola $pengguna = null): PenggunaPengelola
{
    $pengguna ??= BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
    $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

    return $pengguna;
}

/** @return array<string, mixed> */
function KonfigurasiPerangkatRilis(TestCase $tes, string $token, string $versi = '1.4.0', ?int $outbox = null): array
{
    $permintaan = $tes->withToken($token)->withHeader('X-Versi-Aplikasi', $versi);

    if ($outbox !== null) {
        $permintaan = $permintaan->withHeader('X-Outbox-Tertunda', (string) $outbox);
    }

    return $permintaan->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->json('Aplikasi');
}

function CatatDrafRilis(TestCase $tes, string $versi, string $kanal = 'Stabil'): RilisAplikasi
{
    $tes->post(BantuanPengelola::Url('/rilis'), [
        'Aplikasi' => 'Pos', 'Platform' => 'Android', 'Kanal' => $kanal, 'Versi' => $versi, 'Build' => 150,
        'UrlUnduh' => 'https://unduh.payoung.id/payoung-kasir-'.$versi.'.apk', 'CatatanRilis' => 'Cetak struk Bluetooth.',
    ])->assertSessionHasNoErrors();

    return RilisAplikasi::query()->where('Versi', $versi)->where('Kanal', $kanal)->sole();
}

describe('P-10 rilis aplikasi', function (): void {
    it('izin: Teknis & Super Admin mengelola; Keuangan tidak bisa membuka', function (): void {
        MasukSebagaiTeknisRilis($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));
        $this->get(BantuanPengelola::Url('/rilis'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/rilis'), [])->assertForbidden();

        MasukSebagaiTeknisRilis($this);
        $this->get(BantuanPengelola::Url('/rilis'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Pengelola/Rilis/Daftar')->has('Rilis', 0));
    });

    it('draf → terbit bertahap per ember perangkat → 100%; draf tidak ditawarkan; versi tidak valid & ganda ditolak; audit', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        MasukSebagaiTeknisRilis($this);

        $this->post(BantuanPengelola::Url('/rilis'), ['Aplikasi' => 'Pos', 'Platform' => 'Android', 'Kanal' => 'Stabil', 'Versi' => '1.5'])
            ->assertSessionHasErrors(['Versi' => 'Versi berformat MAJOR.MINOR.PATCH, misal 1.4.0.']);
        $rilis = CatatDrafRilis($this, '1.5.0');
        $this->post(BantuanPengelola::Url('/rilis'), ['Aplikasi' => 'Pos', 'Platform' => 'Android', 'Kanal' => 'Stabil', 'Versi' => '1.5.0'])
            ->assertSessionHasErrors('Versi');
        expect(KonfigurasiPerangkatRilis($this, $token)['VersiTerbaru'])->toBe('1.4.0');

        $ember = VersiAplikasiPerangkat::HitungEmber($perangkat->Uuid);
        $persenDiLuar = max(1, $ember);
        MasukSebagaiTeknisRilis($this);
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/terbitkan"), ['PersenRollout' => $persenDiLuar])->assertSessionHasNoErrors();
        // Ember perangkat = persen: belum masuk rollout (ember < persen).
        $aplikasi = KonfigurasiPerangkatRilis($this, $token);
        expect($aplikasi['VersiTerbaru'])->toBe($ember === 0 ? '1.5.0' : '1.4.0');

        MasukSebagaiTeknisRilis($this);
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/rollout"), ['PersenRollout' => 100])->assertSessionHasNoErrors();
        $aplikasi = KonfigurasiPerangkatRilis($this, $token);
        expect($aplikasi['VersiTerbaru'])->toBe('1.5.0')
            ->and($aplikasi['AdaPembaruan'])->toBeTrue()
            ->and($aplikasi['WajibPembaruan'])->toBeFalse()
            ->and($aplikasi['TautanUnduh'])->toBe('https://unduh.payoung.id/payoung-kasir-1.5.0.apk')
            ->and($aplikasi['CatatanRilis'])->toBe('Cetak struk Bluetooth.')
            ->and(LogAuditPengelola::query()->whereIn('Aksi', ['rilis.draf.simpan', 'rilis.terbit', 'rilis.rollout.ubah'])->count())->toBe(3);

        MasukSebagaiTeknisRilis($this);
        $this->put(BantuanPengelola::Url("/rilis/{$rilis->Uuid}"), ['Aplikasi' => 'Pos', 'Platform' => 'Android', 'Kanal' => 'Stabil', 'Versi' => '1.5.1'])
            ->assertSessionHasErrors('Umum');
    });

    it('POS-23 checksum unduhan: wajib untuk Windows bertautan, disimpan huruf kecil, dikirim ke aplikasi kasir hanya bersama tautan rilisnya', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Kode' => $kode] = BantuanPerangkat::BuatPerangkat($tenant->Id);
        $tokenWindows = BantuanPerangkat::Aktifkan($this, $kode, 'Windows');
        MasukSebagaiTeknisRilis($this);
        $windows = ['Aplikasi' => 'Pos', 'Platform' => 'Windows', 'Kanal' => 'Stabil', 'Versi' => '1.6.0', 'UrlUnduh' => 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.6.0.exe'];

        $this->post(BantuanPengelola::Url('/rilis'), $windows)->assertSessionHasErrors('Sha256Unduhan');
        $this->post(BantuanPengelola::Url('/rilis'), [...$windows, 'Sha256Unduhan' => 'bukan-hex'])->assertSessionHasErrors('Sha256Unduhan');
        $this->post(BantuanPengelola::Url('/rilis'), [...$windows, 'Sha256Unduhan' => str_repeat('AB', 31)])->assertSessionHasErrors('Sha256Unduhan');
        // Android boleh tanpa checksum (lewat toko aplikasi); tanpa tautan pun tidak wajib.
        $this->post(BantuanPengelola::Url('/rilis'), ['Aplikasi' => 'Pos', 'Platform' => 'Android', 'Kanal' => 'Stabil', 'Versi' => '1.6.0', 'UrlUnduh' => 'https://unduh.payoung.id/a.apk'])->assertSessionHasNoErrors();

        $this->post(BantuanPengelola::Url('/rilis'), [...$windows, 'Sha256Unduhan' => str_repeat('AB', 32)])->assertSessionHasNoErrors();
        $rilis = RilisAplikasi::query()->where('Platform', 'Windows')->sole();
        expect($rilis->Sha256Unduhan)->toBe(str_repeat('ab', 32));

        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/terbitkan"), ['PersenRollout' => 100])->assertSessionHasNoErrors();
        $this->withToken($tokenWindows)->withHeader('X-Versi-Aplikasi', '1.0.0')->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()
            ->assertJsonPath('Aplikasi.VersiTerbaru', '1.6.0')
            ->assertJsonPath('Aplikasi.TautanUnduh', 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.6.0.exe')
            ->assertJsonPath('Aplikasi.Sha256Unduhan', str_repeat('ab', 32));

        // Perangkat Android: rilis Android belum terbit, tautan cadangan config tidak punya checksum.
        ['Token' => $tokenAndroid] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id, nama: 'Kasir Belakang');
        $this->withToken($tokenAndroid)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('Aplikasi.Sha256Unduhan', null);
        unset($perangkat);
    });

    it('kanal Beta hanya untuk tenant Uji/Internal; hentikan rollout wajib alasan dan berhenti ditawarkan', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        MasukSebagaiTeknisRilis($this);
        $beta = CatatDrafRilis($this, '2.0.0', 'Beta');
        $this->post(BantuanPengelola::Url("/rilis/{$beta->Uuid}/terbitkan"), ['PersenRollout' => 10])->assertSessionHasNoErrors();
        expect($beta->refresh()->PersenRollout)->toBe(100)
            ->and(KonfigurasiPerangkatRilis($this, $token)['VersiTerbaru'])->toBe('1.4.0');

        Tenant::query()->whereKey($tenant->Id)->update(['Penanda' => PenandaTenant::Uji->value]);
        expect(KonfigurasiPerangkatRilis($this, $token)['VersiTerbaru'])->toBe('2.0.0');

        MasukSebagaiTeknisRilis($this);
        $this->post(BantuanPengelola::Url("/rilis/{$beta->Uuid}/hentikan"), ['Alasan' => 'crash'])->assertSessionHasErrors('Alasan');
        $this->post(BantuanPengelola::Url("/rilis/{$beta->Uuid}/hentikan"), ['Alasan' => 'Crash saat cetak struk di Sunmi V2'])->assertSessionHasNoErrors();
        expect(KonfigurasiPerangkatRilis($this, $token)['VersiTerbaru'])->toBe('1.4.0')
            ->and(LogAuditPengelola::query()->where('Aksi', 'rilis.hentikan')->sole()->Alasan)->toBe('Crash saat cetak struk di Sunmi V2');
    });

    it('BR-P10.1 versi minimum diumumkan ≥ 7 hari kecuali keamanan; BR-P10.2 dampak perangkat lama & outbox; perangkat lama tetap dilayani', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        // Perangkat melaporkan 3 transaksi belum terkirim di versi 1.4.0.
        KonfigurasiPerangkatRilis($this, $token, '1.4.0', 3);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Perangkat::query()->findOrFail($perangkat->Id)->JumlahOutboxTertunda)->toBe(3);

        MasukSebagaiTeknisRilis($this);
        $rilis = CatatDrafRilis($this, '1.5.0');
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/terbitkan"), ['PersenRollout' => 100]);

        $this->getJson(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/dampak-versi-minimum"))
            ->assertOk()
            ->assertExactJson(['PerangkatDiBawah' => 1, 'PerangkatDiBawahDenganOutbox' => 1, 'OutboxTertunda' => 3]);

        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/versi-minimum"), ['BerlakuPada' => '2026-10-23', 'Alasan' => 'Format sinkron lama dihapus'])
            ->assertSessionHasErrors(['BerlakuPada' => 'Versi minimum wajib diumumkan paling lambat 7 hari sebelumnya. Paling cepat berlaku 27 Oktober 2026, kecuali perbaikan keamanan.']);
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/versi-minimum"), ['BerlakuPada' => '2026-10-27', 'Alasan' => 'Format sinkron lama dihapus'])
            ->assertSessionHasNoErrors();
        $log = LogAuditPengelola::query()->where('Aksi', 'rilis.versi-minimum.atur')->sole();
        expect($log->NilaiBaru['PerangkatDiBawahDenganOutbox'] ?? null)->toBe(1)
            ->and(KonfigurasiPerangkatRilis($this, $token)['WajibPembaruan'])->toBeFalse();

        // Setelah berlaku: wajib perbarui, tetapi perangkat lama tetap dilayani API (outbox boleh dikirim).
        Carbon::setTestNow(Carbon::parse('2026-10-27 08:00:00', 'Asia/Jakarta'));
        $aplikasi = KonfigurasiPerangkatRilis($this, $token, '1.4.0', 0);
        expect($aplikasi['VersiMinimal'])->toBe('1.5.0')->and($aplikasi['WajibPembaruan'])->toBeTrue();
        expect(KonfigurasiPerangkatRilis($this, $token, '1.5.0')['WajibPembaruan'])->toBeFalse();

        MasukSebagaiTeknisRilis($this);
        $this->delete(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/versi-minimum"))->assertSessionHasErrors('Umum');
    });

    it('perbaikan keamanan boleh berlaku hari ini; versi minimum yang belum berlaku bisa dibatalkan', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        MasukSebagaiTeknisRilis($this);
        $rilis = CatatDrafRilis($this, '1.4.1');
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/terbitkan"), ['PersenRollout' => 100]);
        $this->post(BantuanPengelola::Url("/rilis/{$rilis->Uuid}/versi-minimum"), ['BerlakuPada' => '2026-10-20', 'PerbaikanKeamanan' => true, 'Alasan' => 'Celah token perangkat diperbaiki'])
            ->assertSessionHasNoErrors();
        expect(KonfigurasiPerangkatRilis($this, $token)['WajibPembaruan'])->toBeTrue();

        $lain = CatatDrafRilis($this, '1.6.0');
        $this->post(BantuanPengelola::Url("/rilis/{$lain->Uuid}/terbitkan"), ['PersenRollout' => 100]);
        $this->post(BantuanPengelola::Url("/rilis/{$lain->Uuid}/versi-minimum"), ['BerlakuPada' => '2026-11-30', 'Alasan' => 'Rencana penghapusan API lama']);
        $this->delete(BantuanPengelola::Url("/rilis/{$lain->Uuid}/versi-minimum"))->assertSessionHasNoErrors();
        expect($lain->refresh()->VersiMinimum)->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'rilis.versi-minimum.batal')->count())->toBe(1);
    });
});
