<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLangganan;
use App\Domain\Pengelola\Tagihan\Kueri\LaporanLanggananPlatform;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * P-08 langkah 7 (PRD v4.09): laporan langganan konsol — MRR/ARR dari tagihan lunas yang periodenya berjalan, churn
 * periode, pendapatan per paket & sektor (basis kas, tanpa PPN), dan umur piutang; tenant uji/demo/internal tidak
 * dihitung.
 */

/** Owner membuat tagihan PRO bulanan; kembalikan tagihannya. */
function TagihanLaporanUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
        ->assertSessionHasNoErrors();
    $tes->flushSession();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

/** Tagihan dibayar transfer lalu diverifikasi Keuangan → Lunas & periode berjalan. */
function LunasiTagihanLaporanUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant): TagihanLangganan
{
    $tagihan = TagihanLaporanUji($tes, $pemilik, $tenant);
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    $tes->flushSession();
    app(TerimaPembayaranLangganan::class)->Jalankan($tes->keuangan, $pembayaran->Uuid, $tagihan->Total);

    return $tagihan->refresh();
}

function LaporanLanggananUji(string $dari, string $sampai): array
{
    return app(LaporanLanggananPlatform::class)->Ambil(CarbonImmutable::parse($dari, 'Asia/Jakarta'), CarbonImmutable::parse($sampai, 'Asia/Jakarta'));
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    $this->keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
});

it('MRR, ARR, pendapatan per paket & sektor dari tagihan lunas. Piutang per umur. Tenant uji tidak dihitung', function (): void {
    ['Tenant' => $a, 'Pengguna' => $pemilikA] = BantuanTagihan::DaftarTenant();
    ['Tenant' => $b, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('bayu@sinarjaya.id', '081298765432', 'Sinar Jaya');
    ['Tenant' => $c, 'Pengguna' => $pemilikC] = BantuanTagihan::DaftarTenant('sari@warungsari.id', '081311112222', 'Warung Sari');
    $a->forceFill(['Pengaturan' => ['Sektor' => ['FNB-RST']]])->save();

    $tagihanA = LunasiTagihanLaporanUji($this, $pemilikA, $a);
    LunasiTagihanLaporanUji($this, $pemilikB, $b);
    $terbukaC = TagihanLaporanUji($this, $pemilikC, $c);
    $bulanan = Uang::Dari($tagihanA->Subtotal)->Kurangi(Uang::Dari($tagihanA->Diskon));

    $laporan = LaporanLanggananUji('2026-09-01', '2026-09-30');

    expect($tagihanA->PeriodeMulai)->not->toBeNull()
        ->and($laporan['Ringkasan']['PelangganBerbayar'])->toBe(2)
        ->and($laporan['Ringkasan']['Mrr'])->toBe($bulanan->Kali(2)->KeString())
        ->and($laporan['Ringkasan']['Arr'])->toBe($bulanan->Kali(24)->KeString())
        ->and($laporan['MrrPerPaket'])->toHaveCount(1)
        ->and($laporan['MrrPerPaket'][0]['Pelanggan'])->toBe(2)
        ->and($laporan['PendapatanPerPaket'][0]['JumlahTagihan'])->toBe(2)
        ->and($laporan['PendapatanPerPaket'][0]['Pendapatan'])->toBe($bulanan->Kali(2)->KeString())
        ->and(collect($laporan['PendapatanPerSektor'])->firstWhere('Kunci', 'FNB-RST')['Pendapatan'])->toBe($bulanan->KeString())
        ->and(collect($laporan['PendapatanPerSektor'])->firstWhere('Kunci', '-')['JumlahTagihan'])->toBe(1)
        ->and($laporan['Piutang']['Jumlah'])->toBe(1)
        ->and($laporan['Piutang']['Total'])->toBe((string) $terbukaC->Total)
        ->and($laporan['Piutang']['Umur'][0]['Jumlah'])->toBe(1);

    // Tenant uji (P-07) dikecualikan dari semua metrik.
    $c->forceFill(['Penanda' => PenandaTenant::Uji])->save();
    $b->forceFill(['Penanda' => PenandaTenant::Demo])->save();
    $laporan = LaporanLanggananUji('2026-09-01', '2026-09-30');
    expect($laporan['Ringkasan']['PelangganBerbayar'])->toBe(1)
        ->and($laporan['Piutang']['Jumlah'])->toBe(0);
});

it('churn: pelanggan berbayar di awal periode yang periodenya habis lewat masa tenggang dihitung berhenti', function (): void {
    ['Tenant' => $a, 'Pengguna' => $pemilikA] = BantuanTagihan::DaftarTenant();
    ['Tenant' => $b, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('bayu@sinarjaya.id', '081298765432', 'Sinar Jaya');
    $tagihan = LunasiTagihanLaporanUji($this, $pemilikA, $a);
    LunasiTagihanLaporanUji($this, $pemilikB, $b);
    $bulanan = Uang::Dari($tagihan->Subtotal)->Kurangi(Uang::Dari($tagihan->Diskon));

    // Periode 23 Sep–23 Okt + tenggang 7 hari: 15 Nov keduanya sudah tidak berbayar.
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'Asia/Jakarta'));
    $churn = LaporanLanggananUji('2026-10-01', '2026-11-15')['Churn'];

    expect($churn['PelangganAwal'])->toBe(2)
        ->and($churn['PelangganBerhenti'])->toBe(2)
        ->and($churn['PelangganAkhir'])->toBe(0)
        ->and($churn['PersenChurn'])->toBe('100.00')
        ->and($churn['MrrBerhenti'])->toBe($bulanan->Kali(2)->KeString())
        ->and($churn['PersenChurnMrr'])->toBe('100.00');

    // Dalam masa tenggang (27 Okt) masih dihitung berbayar.
    expect(LaporanLanggananUji('2026-10-01', '2026-10-27')['Churn']['PelangganBerhenti'])->toBe(0);
});

it('HTTP: Keuangan membuka /laporan-langganan. Peran tanpa izin tagihan ditolak', function (): void {
    ['Tenant' => $a, 'Pengguna' => $pemilikA] = BantuanTagihan::DaftarTenant();
    LunasiTagihanLaporanUji($this, $pemilikA, $a);
    $sesi = BantuanPengelola::SesiTerverifikasi();

    $this->actingAs($this->keuangan, 'pengelola')->withSession($sesi)
        ->get(BantuanPengelola::Url('/laporan-langganan?dari=2026-09-01&sampai=2026-09-30'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Pengelola/Tagihan/Laporan')
            ->where('Saring.Dari', '2026-09-01')
            ->where('Ringkasan.PelangganBerbayar', 1)
            ->has('Piutang.Umur', 5));

    $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
    $this->actingAs($teknis, 'pengelola')->withSession($sesi)->get(BantuanPengelola::Url('/laporan-langganan'))->assertForbidden();
});
