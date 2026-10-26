<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Kueri\DetailShift;
use App\Domain\Kasir\Model\BukaLaci;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use App\Domain\Tenant\Model\Tenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemBukaLaciUji(string $uuidShift, string $uuidPembuka, array $timpa = [], ?string $uuid = null): array
{
    return [
        'Jenis' => 'Laci.Buka',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => array_merge([
            'UuidShift' => $uuidShift,
            'Alasan' => 'Tukar uang kecil untuk kembalian',
            'UuidPembuka' => $uuidPembuka,
            'DibukaPada' => now()->subMinutes(5)->utc()->toIso8601ZuluString(),
        ], $timpa),
    ];
}

/**
 * @return array<string, mixed>
 */
function SiapkanShiftLaciUji(object $tes): array
{
    $k = BantuanKasir::Siapkan($tes);
    $shift = BantuanKasir::ItemBukaShift($k['Kasir']);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$shift]))->toBe([['Diterima', null]]);

    return $k + ['UuidShift' => $shift['Uuid']];
}

function AturBukaLaciPerluPinUji(int $idTenant): void
{
    $tenant = Tenant::query()->findOrFail($idTenant);
    $tenant->Pengaturan = array_merge($tenant->Pengaturan ?? [], ['BukaLaciPerluPin' => true]);
    $tenant->save();
}

describe('Cetak struk bagian 4: buka laci manual tanpa transaksi selalu dicatat (POS-17, §19.2)', function (): void {
    it('log diterima, idempoten per Uuid, tercatat audit laci.buka, dan tampil di detail shift', function (): void {
        $k = SiapkanShiftLaciUji($this);
        $item = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $log = BukaLaci::query()->sole();
        $detail = app(DetailShift::class)->Ambil($k['UuidShift'], null);

        expect($log->Uuid)->toBe($item['Uuid'])
            ->and($log->DibukaOleh)->toBe($k['Kasir']->Id)
            ->and($log->DisetujuiOleh)->toBeNull()
            ->and($log->PerluTinjauan)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'laci.buka')->count())->toBe(1)
            ->and($detail['BukaLaci'] ?? null)->toHaveCount(1)
            ->and($detail['BukaLaci'][0]['Alasan'])->toBe('Tukar uang kecil untuk kembalian')
            ->and($detail['BukaLaci'][0]['DibukaOleh'])->toBe($k['Kasir']->Nama);
    });

    it('PIN opsional: bila BukaLaciPerluPin aktif, tanpa penyetuju atau penyetuju tanpa izin tetap diterima dengan tinjauan', function (): void {
        $k = SiapkanShiftLaciUji($this);
        AturBukaLaciPerluPinUji($k['Tenant']->Id);

        $tanpa = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid);
        $kasirMenyetujui = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid, ['UuidPenyetuju' => $k['Kasir']->Uuid]);
        $disetujui = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid, ['UuidPenyetuju' => $k['Supervisor']->Uuid]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa, $kasirMenyetujui, $disetujui]))
            ->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $log = fn (array $i): BukaLaci => BukaLaci::query()->where('Uuid', $i['Uuid'])->sole();

        expect($log($tanpa)->PerluTinjauan)->toBeTrue()
            ->and($log($tanpa)->AlasanTinjauan)->toStartWith('PenyetujuTidakAda')
            ->and($log($kasirMenyetujui)->PerluTinjauan)->toBeTrue()
            ->and($log($kasirMenyetujui)->AlasanTinjauan)->toStartWith('PenyetujuTidakBerwenang')
            ->and($log($kasirMenyetujui)->DisetujuiOleh)->toBeNull()
            ->and($log($disetujui)->PerluTinjauan)->toBeFalse()
            ->and($log($disetujui)->DisetujuiOleh)->toBe($k['Supervisor']->Id);
    });

    it('PIN tidak wajib (bawaan): tanpa penyetuju tidak ditandai tinjauan. Shift tertutup ditandai ShiftTidakAktif', function (): void {
        $k = SiapkanShiftLaciUji($this);
        $awal = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$awal]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BukaLaci::query()->where('Uuid', $awal['Uuid'])->sole()->PerluTinjauan)->toBeFalse();

        Shift::query()->where('Uuid', $k['UuidShift'])->toBase()->update(['Status' => 'Tertutup']);
        $susulan = ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$susulan]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BukaLaci::query()->where('Uuid', $susulan['Uuid'])->sole()->AlasanTinjauan)->toStartWith('ShiftTidakAktif');
    });

    it('ditolak: alasan terlalu pendek, waktu di masa depan, pembuka bukan anggota outlet, shift tidak dikenal', function (): void {
        $k = SiapkanShiftLaciUji($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid, ['Alasan' => ' a ']),
            ItemBukaLaciUji($k['UuidShift'], $k['Kasir']->Uuid, ['DibukaPada' => now()->addHour()->utc()->toIso8601ZuluString()]),
            ItemBukaLaciUji($k['UuidShift'], BantuanKasir::Uuid()),
            ItemBukaLaciUji(BantuanKasir::Uuid(), $k['Kasir']->Uuid),
        ]))->toBe([
            ['Ditolak', 'AlasanWajib'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'KasirTidakDitemukan'],
            ['Ditolak', 'ShiftTidakDikenal'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BukaLaci::query()->count())->toBe(0);
    });

    it('isolasi tenant: perangkat tenant lain tidak bisa mencatat buka laci di shift tenant ini', function (): void {
        $a = SiapkanShiftLaciUji($this);
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [ItemBukaLaciUji($a['UuidShift'], $b['Kasir']->Uuid)]))
            ->toBe([['Ditolak', 'ShiftTidakDikenal']]);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(BukaLaci::query()->count())->toBe(0);
    });

    it('pengaturan: BukaLaciPerluPin bawaan mati, bisa diubah dari halaman pengaturan kasir dan ikut data-awal', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);

        $this->get('/kelola/kasir/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('BukaLaciPerluPin', false));
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '200000', 'ShiftBersama' => false, 'BukaLaciPerluPin' => 'ya'])
            ->assertSessionHasErrors('BukaLaciPerluPin');
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '200000', 'ShiftBersama' => false, 'BukaLaciPerluPin' => true])
            ->assertRedirect('/kelola/kasir/pengaturan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->bukaLaciPerluPin)->toBeTrue();

        // Bidang tidak dikirim = nilai tersimpan dipertahankan.
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '250000', 'ShiftBersama' => false])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->bukaLaciPerluPin)->toBeTrue();

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Pengaturan.BukaLaciPerluPin', true);
    });
});
