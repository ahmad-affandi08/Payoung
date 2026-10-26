<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\JalankanJadwalKasBank;
use App\Domain\Akuntansi\Aksi\KunciPeriodeAkuntansi;
use App\Domain\Akuntansi\Enum\FrekuensiJadwalKasBank;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JadwalKasBank;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\TransaksiKasBank;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * D-23 D bagian 2: transaksi kas & bank berulang. "Ulangi otomatis" di formulir mencatat transaksi pertama + jadwal;
 * tiap jatuh tempo (bulanan: tanggal acuan, tanggal 31 → hari terakhir bulan pendek) sistem mencatat transaksi biasa
 * (nomor, jurnal seimbang, audit) dan memajukan jadwal; tertinggal = disusul; idempoten per (jadwal, tanggal);
 * periode terkunci = ditolak, alasan tampil di Kotak Tindakan; hentikan/aktifkan lagi tanpa menyusul tanggal lewat.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake((string) config('akuntansi.DiskLampiran'));
});

function UuidAkunJadwal(string $kode): string
{
    return (string) Akun::query()->where('Kode', $kode)->value('Uuid');
}

/**
 * Tenant + sewa ruko bulanan mulai 31 Januari 2026 (dicatat lewat formulir oleh Akuntan).
 *
 * @return array<string, mixed>
 */
function SiapkanSewaBulanan(TestCase $tes): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanPersediaan::MasukSebagai($tes, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

    $tes->post('/kelola/akuntansi/kas-bank', [
        'Jenis' => 'Pengeluaran',
        'Tanggal' => '2026-01-31',
        'UuidAkunSumber' => UuidAkunJadwal('1-1100'),
        'UuidAkunTujuan' => UuidAkunJadwal('6-2000'),
        'Jumlah' => '4500000',
        'Keterangan' => 'Sewa ruko Jl. Slamet Riyadi Solo',
        'Ulangi' => 'Bulanan',
    ])->assertSessionHasNoErrors()->assertSessionHas('Kilat', fn (string $p): bool => str_contains($p, 'berikutnya 2026-02-28'));

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    return $t + ['Jadwal' => JadwalKasBank::query()->sole()];
}

it('bulanan tanggal 31: jatuh tempo dicatat & disusul mengikuti akhir bulan; jurnal seimbang; idempoten', function (): void {
    $k = SiapkanSewaBulanan($this);
    $jadwal = $k['Jadwal'];
    expect($jadwal->Frekuensi)->toBe(FrekuensiJadwalKasBank::Bulanan)
        ->and($jadwal->TanggalBerikutnya->toDateString())->toBe('2026-02-28')
        ->and($jadwal->JumlahDicatat)->toBe(1)
        ->and(TransaksiKasBank::query()->count())->toBe(1);

    // Dijalankan 10 April: 28 Feb & 31 Mar disusul, berikutnya 30 April.
    expect(app(JalankanJadwalKasBank::class)->Jalankan(CarbonImmutable::parse('2026-04-10')))->toBe(['Dicatat' => 2, 'Gagal' => 0]);
    $jadwal->refresh();
    $otomatis = TransaksiKasBank::query()->where('IdJadwalKasBank', $jadwal->Id)->orderBy('Tanggal')->get();
    expect($otomatis->map(fn (TransaksiKasBank $t): string => $t->Tanggal->toDateString())->all())->toBe(['2026-02-28', '2026-03-31'])
        ->and($otomatis->pluck('Jumlah')->unique()->values()->all())->toBe(['4500000.00'])
        ->and($jadwal->TanggalBerikutnya->toDateString())->toBe('2026-04-30')
        ->and($jadwal->JumlahDicatat)->toBe(3)
        ->and((string) JurnalDetail::query()->sum('Debit'))->toBe((string) JurnalDetail::query()->sum('Kredit'));

    // Idempoten: jalan ulang tidak menambah; unik per (jadwal, tanggal) juga dijaga database.
    expect(app(JalankanJadwalKasBank::class)->Jalankan(CarbonImmutable::parse('2026-04-10')))->toBe(['Dicatat' => 0, 'Gagal' => 0])
        ->and(TransaksiKasBank::query()->count())->toBe(3);
    expect(fn () => DB::table('TransaksiKasBank')->where('Id', $otomatis->first()->Id)->update(['Tanggal' => '2026-03-31']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('periode terkunci: ditolak, alasan di Kotak Tindakan, tidak dimajukan. Hentikan & aktifkan lagi tanpa menyusul. Lewat jadwal harian', function (): void {
    $k = SiapkanSewaBulanan($this);
    $jadwal = $k['Jadwal'];
    app(KunciPeriodeAkuntansi::class)->Jalankan('2026-02', (int) $jadwal->DibuatOleh);

    expect(app(JalankanJadwalKasBank::class)->Jalankan(CarbonImmutable::parse('2026-03-05')))->toBe(['Dicatat' => 0, 'Gagal' => 1]);
    $jadwal->refresh();
    expect($jadwal->TanggalBerikutnya->toDateString())->toBe('2026-02-28')
        ->and($jadwal->GalatTerakhir)->not->toBeNull();

    $this->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h) {
        $butir = collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'kas-bank.berulang-gagal');
        expect($butir['Jumlah'] ?? null)->toBe(1)->and($butir['Tingkat'] ?? null)->toBe('Penting');

        return $h;
    });

    // Hentikan lalu aktifkan lagi pada 20 Mei: tanggal lewat tidak disusul, berikutnya 31 Mei; galat dibersihkan.
    $this->put("/kelola/akuntansi/kas-bank/berulang/{$jadwal->Uuid}", ['Aktif' => false])->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-05-20 10:00', 'Asia/Jakarta'));
    $this->put("/kelola/akuntansi/kas-bank/berulang/{$jadwal->Uuid}", ['Aktif' => true, 'Jumlah' => '4750000'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $jadwal->refresh();
    expect($jadwal->Aktif)->toBeTrue()
        ->and($jadwal->TanggalBerikutnya->toDateString())->toBe('2026-05-31')
        ->and($jadwal->Jumlah)->toBe('4750000.00')
        ->and($jadwal->GalatTerakhir)->toBeNull();

    // Jadwal harian (perintah) pada 31 Mei.
    $this->travelTo(CarbonImmutable::parse('2026-05-31 06:00', 'Asia/Jakarta'));
    expect(Artisan::call('akuntansi:jalankan-jadwal-kas-bank', ['--tenant' => [$k['Tenant']->Id]]))->toBe(0);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TransaksiKasBank::query()->where('IdJadwalKasBank', $jadwal->Id)->sole()->only(['Jumlah']))->toBe(['Jumlah' => '4750000.00']);

    // Daftar jadwal (TabelData) & pengguna tanpa izin kelola tidak bisa mengubah.
    $this->get('/kelola/akuntansi/kas-bank/berulang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Akuntansi/KasBank/Berulang')
        ->where('Jadwal.Data.0.Keterangan', 'Sewa ruko Jl. Slamet Riyadi Solo')
        ->where('Izin.Kelola', true));
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->put("/kelola/akuntansi/kas-bank/berulang/{$jadwal->Uuid}", ['Aktif' => false])->assertForbidden();
});
