<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\StatusAsetTetap;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\AsetTetap;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PenyusutanAset;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * FIN-10 (v3.38) aset tetap & penyusutan garis lurus: perolehan Dr Aset Tetap / Cr kas, saldo awal (akumulasi awal +
 * ekuitas saldo awal), penyusutan bulanan terjadwal Dr Beban / Cr Akumulasi (idempoten, menyusul bulan tertinggal),
 * pelepasan dengan laba/rugi, pembatalan sebelum disusutkan, validasi, izin, isolasi tenant, invarian Σ debit = Σ kredit.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

function SaldoAkunAset(int $idAkun): string
{
    return (string) JurnalDetail::query()->where('IdAkun', $idAkun)->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS s')->value('s');
}

function PastikanJurnalSeimbang(): void
{
    expect((string) JurnalDetail::query()->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00');
}

/** @return array<string, mixed> */
function IsianAset(string $uuidKas, array $timpa = []): array
{
    return [
        'Nama' => 'Mesin espresso La Marzocco Linea Mini 2 grup',
        'Kelompok' => 'Kelompok1',
        'TanggalPerolehan' => '2026-07-15',
        'HargaPerolehan' => '12000000',
        'NilaiSisa' => '0',
        'UmurBulan' => 48,
        'SumberDana' => 'KasBank',
        'AkunKasBank' => $uuidKas,
        ...$timpa,
    ];
}

it('perolehan → penyusutan terjadwal (menyusul, idempoten) → pelepasan dengan rugi. Jurnal seimbang', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid))->assertRedirect();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $aset = AsetTetap::query()->sole();
    $idAset = BantuanJurnal::IdAkunPeran(PeranAkun::AsetTetap);
    $idAkum = BantuanJurnal::IdAkunPeran(PeranAkun::AkumulasiPenyusutan);
    $idBeban = BantuanJurnal::IdAkunPeran(PeranAkun::BebanPenyusutan);
    expect($aset->Nomor)->toBe('AT/2026/07/0001')
        ->and($aset->PeriodeMulai)->toBe('2026-07')
        ->and(SaldoAkunAset($idAset))->toBe('12000000.00')
        ->and(SaldoAkunAset($kas->Id))->toBe('-12000000.00')
        ->and(Akun::query()->whereKey($idAkum)->sole()->CekKontra())->toBeTrue()
        ->and(LogAudit::query()->where('Peristiwa', 'aset-tetap.catat')->count())->toBe(1);

    // Jadwal harian: sampai bulan lalu (Juli–September = 3 bulan), lalu dijalankan lagi tanpa jurnal ganda.
    $this->artisan('akuntansi:susutkan-aset-tetap', ['--tenant' => [$t['Tenant']->Id]])->assertSuccessful();
    $this->artisan('akuntansi:susutkan-aset-tetap', ['--tenant' => [$t['Tenant']->Id]])->assertSuccessful();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(PenyusutanAset::query()->orderBy('Periode')->pluck('Periode')->all())->toBe(['2026-07', '2026-08', '2026-09'])
        ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::PenyusutanAset->value)->count())->toBe(3)
        ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::PenyusutanAset->value)->orderBy('Tanggal')->first()?->Tanggal->toDateString())->toBe('2026-07-31')
        ->and(SaldoAkunAset($idAkum))->toBe('-750000.00')
        ->and(SaldoAkunAset($idBeban))->toBe('750000.00');

    $this->get("/kelola/akuntansi/aset-tetap/{$aset->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Akuntansi/AsetTetap/Detail')
        ->where('Aset.Akumulasi', '750000.00')
        ->where('Aset.NilaiBuku', '11250000.00')
        ->where('Aset.BisaDibatalkan', false)
        ->count('Jadwal', 48)
        ->where('Jadwal.2.Dijurnal', true)
        ->where('Jadwal.3.Dijurnal', false));

    // Batal ditolak karena sudah disusutkan; pelepasan menyusutkan Oktober dulu (akum 1.000.000, nilai buku 11 juta).
    $this->post("/kelola/akuntansi/aset-tetap/{$aset->Uuid}/batalkan", ['Alasan' => 'Salah input harga'])->assertSessionHasErrors();
    $this->post("/kelola/akuntansi/aset-tetap/{$aset->Uuid}/lepas", ['Tanggal' => '2026-10-20', 'NilaiJual' => '10500000'])->assertSessionHasErrors('AkunKasBank');
    $this->post("/kelola/akuntansi/aset-tetap/{$aset->Uuid}/lepas", ['Tanggal' => '2026-10-20', 'NilaiJual' => '10500000', 'AkunKasBank' => $kas->Uuid])->assertRedirect();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($aset->refresh()->Status)->toBe(StatusAsetTetap::Dilepas)
        ->and(PenyusutanAset::query()->count())->toBe(4)
        ->and(SaldoAkunAset($idAset))->toBe('0.00')
        ->and(SaldoAkunAset($idAkum))->toBe('0.00')
        // Beban = penyusutan 4 bulan 1.000.000 + rugi pelepasan 500.000.
        ->and(SaldoAkunAset($idBeban))->toBe('1500000.00')
        ->and(SaldoAkunAset($kas->Id))->toBe('-1500000.00');
    PastikanJurnalSeimbang();

    // Aset dilepas tidak disusutkan lagi.
    $this->post('/kelola/akuntansi/aset-tetap/susutkan', ['Periode' => '2026-10'])->assertSessionHas('Kilat');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(PenyusutanAset::query()->count())->toBe(4);
});

it('saldo awal: Dr aset / Cr akumulasi awal / Cr ekuitas. Mulai bulan berjalan. Pelepasan berlaba ke pendapatan lain', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

    $this->post('/kelola/akuntansi/aset-tetap', IsianAset('', [
        'Nama' => 'Mobil boks pengiriman Gran Max', 'Kelompok' => 'Kelompok2', 'TanggalPerolehan' => '2024-10-01',
        'HargaPerolehan' => '20000000', 'UmurBulan' => 96, 'SumberDana' => 'SaldoAwal', 'AkunKasBank' => null, 'AkumulasiAwal' => '5000000',
    ]))->assertRedirect();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $aset = AsetTetap::query()->sole();
    expect($aset->PeriodeMulai)->toBe('2026-10')
        ->and(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::AsetTetap)))->toBe('20000000.00')
        ->and(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::AkumulasiPenyusutan)))->toBe('-5000000.00')
        ->and(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::EkuitasSaldoAwal)))->toBe('-15000000.00');

    $this->post('/kelola/akuntansi/aset-tetap/susutkan', ['Periode' => '2026-11'])->assertSessionHasErrors('Periode');
    $this->post('/kelola/akuntansi/aset-tetap/susutkan', ['Periode' => '2026-10'])->assertSessionHas('Kilat');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect((string) PenyusutanAset::query()->sole()->Jumlah)->toBe('208333.33');

    // Nilai buku 14.791.666,67 dijual 16 juta → laba 1.208.333,33.
    $this->post("/kelola/akuntansi/aset-tetap/{$aset->Uuid}/lepas", ['Tanggal' => '2026-10-20', 'NilaiJual' => '16000000', 'AkunKasBank' => $kas->Uuid])->assertRedirect();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::PendapatanLain)))->toBe('-1208333.33')
        ->and(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::AkumulasiPenyusutan)))->toBe('0.00');
    PastikanJurnalSeimbang();
});

it('validasi, tanah tanpa penyusutan, pembatalan sebelum disusutkan membalik jurnal', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid, ['HargaPerolehan' => '0']))->assertSessionHasErrors('HargaPerolehan');
    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid, ['TanggalPerolehan' => '2026-10-21']))->assertSessionHasErrors('TanggalPerolehan');
    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid, ['NilaiSisa' => '12000000']))->assertSessionHasErrors('NilaiSisa');
    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid, ['UmurBulan' => 0]))->assertSessionHasErrors('UmurBulan');
    $this->post('/kelola/akuntansi/aset-tetap', IsianAset('', ['AkunKasBank' => null]))->assertSessionHasErrors('AkunKasBank');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(AsetTetap::query()->count())->toBe(0);

    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid, ['Nama' => 'Tanah gudang Sukoharjo', 'Kelompok' => 'Tanah', 'HargaPerolehan' => '350000000', 'UmurBulan' => 240]))->assertRedirect();
    $this->post('/kelola/akuntansi/aset-tetap/susutkan', ['Periode' => '2026-10'])->assertSessionHas('Kilat');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $tanah = AsetTetap::query()->sole();
    expect($tanah->UmurBulan)->toBe(0)->and(PenyusutanAset::query()->count())->toBe(0);

    $this->post("/kelola/akuntansi/aset-tetap/{$tanah->Uuid}/batalkan", ['Alasan' => 'Ups'])->assertSessionHasErrors('Alasan');
    $this->post("/kelola/akuntansi/aset-tetap/{$tanah->Uuid}/batalkan", ['Alasan' => 'Tanah milik pribadi, bukan usaha'])->assertRedirect();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($tanah->refresh()->Status)->toBe(StatusAsetTetap::Dibatalkan)
        ->and(SaldoAkunAset(BantuanJurnal::IdAkunPeran(PeranAkun::AsetTetap)))->toBe('0.00')
        ->and(SaldoAkunAset($kas->Id))->toBe('0.00');
    PastikanJurnalSeimbang();
});

it('izin: kasir tanpa akses, supervisor tanpa kelola. Tenant lain 404', function (): void {
    $a = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
    $this->post('/kelola/akuntansi/aset-tetap', IsianAset($kas->Uuid))->assertRedirect();
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    $aset = AsetTetap::query()->sole();
    $this->getJson('/kelola/akuntansi/aset-tetap')->assertOk()->assertJsonPath('Data.0.Nomor', $aset->Nomor)->assertJsonPath('Data.0.NilaiBuku', '12000000.00');

    BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->get('/kelola/akuntansi/aset-tetap')->assertForbidden();

    BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $this->post('/kelola/akuntansi/aset-tetap/susutkan', ['Periode' => '2026-10'])->assertForbidden();

    $b = BantuanPersediaan::SiapkanTenant('Toko Lain Sejahtera');
    BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
    $this->get("/kelola/akuntansi/aset-tetap/{$aset->Uuid}")->assertNotFound();
});
