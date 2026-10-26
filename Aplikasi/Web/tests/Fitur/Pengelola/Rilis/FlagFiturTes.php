<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Kueri\FlagFiturTenant;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\FlagFitur;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

function MasukSebagaiPengelolaFlag(TestCase $tes, PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Teknis): void
{
    $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @param array<string, mixed> $isian */
function SimpanFlagUji(TestCase $tes, array $isian): void
{
    $tes->post(BantuanPengelola::Url('/flag-fitur'), $isian + ['Alasan' => 'Peluncuran bertahap fitur baru'])->assertSessionHasNoErrors();
}

describe('P-10 flag fitur', function (): void {
    it('kill switch Global mati mengalahkan aturan tenant. Aturan tenant > paket > persentase > global hidup; EvaluatorFitur & konfigurasi-aplikasi ikut', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $b] = BantuanOrganisasi::BuatTenant('Toko Budi');
        ['Token' => $tokenA] = BantuanPerangkat::BuatDanAktifkan($this, $a->Id);
        $kunci = app(SumberFiturTenant::class)->Ambil($a->Id)->fiturPaket[0] ?? 'pos.mode-meja';
        $pemeriksa = app(PemeriksaFiturTenant::class);
        expect($pemeriksa->CekAktif($a->Id, $kunci))->toBeTrue();
        expect($this->withToken($tokenA)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->json('FlagFitur'))->toBe([]);

        MasukSebagaiPengelolaFlag($this);
        SimpanFlagUji($this, ['Kunci' => $kunci, 'Cakupan' => 'Tenant', 'Objek' => $a->Uuid, 'Nilai' => true]);
        SimpanFlagUji($this, ['Kunci' => $kunci, 'Cakupan' => 'Global', 'Nilai' => false]);
        expect(app(PemeriksaFiturTenant::class)->CekAktif($a->Id, $kunci))->toBeFalse();
        // Kunci bertitik: dibaca utuh (bukan jalur JSON bersarang).
        expect($this->withToken($tokenA)->getJson('/api/pos/v1/konfigurasi-aplikasi')->json('FlagFitur'))->toBe([$kunci => false]);

        // Kill switch dicabut: aturan tenant (hidup) berlaku untuk A, tenant B mengikuti paket (mati).
        $global = FlagFitur::query()->where('Cakupan', 'Global')->sole();
        MasukSebagaiPengelolaFlag($this);
        $this->delete(BantuanPengelola::Url("/flag-fitur/{$global->Uuid}"), ['Alasan' => 'singkat'])->assertSessionHasErrors('Alasan');
        $this->delete(BantuanPengelola::Url("/flag-fitur/{$global->Uuid}"), ['Alasan' => 'Perbaikan crash sudah dirilis'])->assertSessionHasNoErrors();
        $idPaketB = (int) Langganan::query()->where('IdTenant', $b->Id)->value('IdPaket');
        SimpanFlagUji($this, ['Kunci' => $kunci, 'Cakupan' => 'Paket', 'Objek' => Paket::query()->whereKey($idPaketB)->value('Uuid'), 'Nilai' => false]);
        $flag = app(FlagFiturTenant::class);
        expect($flag->AmbilUntukTenant($a->Id)[$kunci])->toBeTrue()
            ->and((new FlagFiturTenant)->AmbilUntukTenant($b->Id)[$kunci])->toBeFalse();

        // Persentase: 100% hidup untuk semua, 0% mati untuk semua (tanpa aturan tenant/paket).
        SimpanFlagUji($this, ['Kunci' => 'kasir.struk-digital', 'Cakupan' => 'Persentase', 'Persen' => 100]);
        expect((new FlagFiturTenant)->AmbilUntukTenant($b->Id)['kasir.struk-digital'])->toBeTrue();
        SimpanFlagUji($this, ['Kunci' => 'kasir.struk-digital', 'Cakupan' => 'Persentase', 'Persen' => 0]);
        expect((new FlagFiturTenant)->AmbilUntukTenant($b->Id)['kasir.struk-digital'])->toBeFalse()
            ->and(FlagFitur::query()->where('Kunci', 'kasir.struk-digital')->count())->toBe(1);

        // BR-P10.3: setiap perubahan tercatat dengan alasan.
        expect(LogAuditPengelola::query()->where('Aksi', 'flag-fitur.simpan')->count())->toBe(5)
            ->and(LogAuditPengelola::query()->where('Aksi', 'flag-fitur.hapus')->sole()->Alasan)->toBe('Perbaikan crash sudah dirilis')
            ->and(LogAuditPengelola::query()->where('Aksi', 'flag-fitur.simpan')->pluck('Alasan')->unique()->all())->toBe(['Peluncuran bertahap fitur baru']);

        MasukSebagaiPengelolaFlag($this);
        $this->get(BantuanPengelola::Url('/flag-fitur'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Rilis/FlagFitur')
            ->has('Aturan', 3)
            ->where('Aturan.2.Kunci', 'kasir.struk-digital')
            ->where('Aturan.2.Persen', 0));
    });

    it('validasi: alasan wajib, kunci berformat D-06, objek wajib untuk paket/tenant. Izin hanya Teknis & Super Admin', function (): void {
        MasukSebagaiPengelolaFlag($this, PeranPengelolaBawaan::Keuangan);
        $this->get(BantuanPengelola::Url('/flag-fitur'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/flag-fitur'), [])->assertForbidden();

        MasukSebagaiPengelolaFlag($this);
        $this->post(BantuanPengelola::Url('/flag-fitur'), ['Kunci' => 'pos.meja', 'Cakupan' => 'Global', 'Nilai' => false, 'Alasan' => 'x'])
            ->assertSessionHasErrors('Alasan');
        $this->post(BantuanPengelola::Url('/flag-fitur'), ['Kunci' => 'Pos Meja', 'Cakupan' => 'Global', 'Nilai' => false, 'Alasan' => 'Kill switch uji coba'])
            ->assertSessionHasErrors('Kunci');
        $this->post(BantuanPengelola::Url('/flag-fitur'), ['Kunci' => 'pos.meja', 'Cakupan' => 'Tenant', 'Nilai' => false, 'Alasan' => 'Kill switch uji coba'])
            ->assertSessionHasErrors(['Objek' => 'Pilih tenant.']);
        expect(FlagFitur::query()->count())->toBe(0);
    });
});
