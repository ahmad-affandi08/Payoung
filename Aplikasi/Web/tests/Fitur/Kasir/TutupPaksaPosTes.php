<?php

declare(strict_types=1);

use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Model\Outlet;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-11 shift lama perangkat yang menahan shift baru: lihat & tutup paksa dari aplikasi POS', function (): void {
    it('GET shift/terbuka mengembalikan shift aktif perangkat ini. Setelah ditutup paksa supervisor, shift baru bisa dibuka', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = BantuanKasir::ItemBukaShift($k['Kasir']);
        $baru = BantuanKasir::ItemBukaShift($k['Kasir']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lama, $baru]))->toBe([['Diterima', null], ['Ditolak', 'ShiftSudahTerbuka']]);

        $daftar = $this->withToken($k['Token'])->getJson('/api/pos/v1/shift/terbuka')->assertOk()->json('Shift');
        expect($daftar)->toHaveCount(1)
            ->and($daftar[0]['Uuid'])->toBe($lama['Uuid'])
            ->and($daftar[0]['Status'])->toBe('Terbuka')
            ->and($daftar[0]['NamaKasir'])->toBe($k['Kasir']->Nama);

        $this->withToken($k['Token'])->postJson("/api/pos/v1/shift/{$lama['Uuid']}/tutup-paksa", [
            'UuidPenyetuju' => $k['Supervisor']->Uuid,
            'Alasan' => 'Sisa uji coba, tidak pernah ditutup',
        ])->assertOk()->assertJsonPath('Status', 'Tertutup');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->where('Uuid', $lama['Uuid'])->firstOrFail();
        expect($shift->Status)->toBe(StatusShift::Tertutup)
            ->and($shift->PerluTinjauan)->toBeTrue()
            ->and($shift->AlasanSelisih)->toContain('Sisa uji coba')->toContain($k['Supervisor']->Nama)
            ->and($shift->DitutupOleh)->toBe($k['Supervisor']->Id);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$baru]))->toBe([['Diterima', null]]);
        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/shift/terbuka')->assertOk()->json('Shift.0.Uuid'))->toBe($baru['Uuid']);
    });

    it('penyetuju tanpa izin shift.selisih.setujui ditolak 403. Alasan terlalu pendek 422. Shift tidak ada atau milik perangkat lain 404', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = BantuanKasir::ItemBukaShift($k['Kasir']);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$lama]);
        $tutup = fn (string $uuid, string $penyetuju, string $alasan = 'Sisa uji coba lama') => $this->withToken($k['Token'])
            ->postJson("/api/pos/v1/shift/{$uuid}/tutup-paksa", ['UuidPenyetuju' => $penyetuju, 'Alasan' => $alasan]);

        $tutup($lama['Uuid'], $k['Kasir']->Uuid)->assertForbidden()->assertJsonPath('Galat.Kode', 'PenyetujuTidakBerwenang');
        $tutup($lama['Uuid'], $k['Supervisor']->Uuid, 'ab')->assertUnprocessable();
        $tutup('01K9ZZZZZZZZZZZZZZZZZZZZZZ', $k['Supervisor']->Uuid)->assertNotFound()->assertJsonPath('Galat.Kode', 'ShiftTidakDikenal');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $outletLain = Outlet::query()->create(['IdMerek' => $k['Outlet']->IdMerek, 'Kode' => 'CBG4', 'Nama' => 'Cabang Gemolong']);
        $perangkatLain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletLain);
        $this->withToken($perangkatLain['Token'])->postJson("/api/pos/v1/shift/{$lama['Uuid']}/tutup-paksa", ['UuidPenyetuju' => $k['Supervisor']->Uuid, 'Alasan' => 'Coba dari perangkat lain'])
            ->assertNotFound();
        expect(Shift::query()->where('Uuid', $lama['Uuid'])->value('Status'))->toBe(StatusShift::Terbuka);
        expect($this->withToken($perangkatLain['Token'])->getJson('/api/pos/v1/shift/terbuka')->assertOk()->json('Shift'))->toBe([]);
    });

    it('shift yang sudah tertutup tidak bisa ditutup paksa lagi', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = BantuanKasir::ItemBukaShift($k['Kasir']);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$lama]);
        $badan = ['UuidPenyetuju' => $k['Supervisor']->Uuid, 'Alasan' => 'Sisa uji coba lama'];

        $this->withToken($k['Token'])->postJson("/api/pos/v1/shift/{$lama['Uuid']}/tutup-paksa", $badan)->assertOk();
        $this->withToken($k['Token'])->postJson("/api/pos/v1/shift/{$lama['Uuid']}/tutup-paksa", $badan)->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ShiftTidakAktif');
    });
});
