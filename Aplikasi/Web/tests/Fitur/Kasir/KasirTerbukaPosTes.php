<?php

declare(strict_types=1);

use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-06 BR-06.1 satu kasir satu shift terbuka per outlet: cek sebelum membuka shift (online)', function (): void {
    it('perangkat B melihat shift terbuka kasir yang sama di perangkat A. Perangkat A sendiri tidak melihatnya', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $buka = BantuanKasir::ItemBukaShift($k['Kasir']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$buka]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $b = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Belakang');
        $cek = fn (string $token, string $uuidKasir) => $this->withToken($token)->getJson('/api/pos/v1/shift/kasir-terbuka?UuidPengguna='.$uuidKasir);

        $dariB = $cek($b['Token'], $k['Kasir']->Uuid)->assertOk()->json('Shift');
        expect($dariB)->toHaveCount(1)
            ->and($dariB[0]['Uuid'])->toBe($buka['Uuid'])
            ->and($dariB[0]['NamaPerangkat'])->toBe($k['Perangkat']->Nama)
            ->and($dariB[0])->toHaveKey('DibukaPada');

        expect($cek($k['Token'], $k['Kasir']->Uuid)->assertOk()->json('Shift'))->toBe([]);
    });

    it('kasir lain, shift sudah tertutup, shift bersama, dan pengguna tak dikenal tidak dihitung sebagai bentrok', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $buka = BantuanKasir::ItemBukaShift($k['Kasir']);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$buka]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $b = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Belakang');
        $cek = fn (string $uuidKasir) => $this->withToken($b['Token'])->getJson('/api/pos/v1/shift/kasir-terbuka?UuidPengguna='.$uuidKasir)->assertOk()->json('Shift');

        // Kasir lain (supervisor) tidak punya shift terbuka.
        expect($cek($k['Supervisor']->Uuid))->toBe([]);
        // Uuid yang tidak ada di tenant.
        expect($cek('01K9ZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([]);

        // Shift bersama (BR-06.2) memang boleh berdampingan dengan shift kasir lain.
        Shift::query()->where('Uuid', $buka['Uuid'])->update(['Bersama' => true]);
        expect($cek($k['Kasir']->Uuid))->toBe([]);

        // Sudah ditutup = tidak menahan.
        Shift::query()->where('Uuid', $buka['Uuid'])->update(['Bersama' => false, 'Status' => StatusShift::Tertutup->value]);
        expect($cek($k['Kasir']->Uuid))->toBe([]);

        // Dibuka ulang = masih menahan.
        Shift::query()->where('Uuid', $buka['Uuid'])->update(['Status' => StatusShift::DibukaUlang->value]);
        expect($cek($k['Kasir']->Uuid))->toHaveCount(1);
    });

    it('terbatas pada outlet perangkat pemanggil dan tenant yang sama; UuidPengguna wajib', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'])]);

        // Perangkat tenant lain tidak melihat shift tenant ini.
        $lain = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        $this->withToken($lain['Token'])->getJson('/api/pos/v1/shift/kasir-terbuka?UuidPengguna='.$k['Kasir']->Uuid)->assertOk()->assertJsonPath('Shift', []);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/shift/kasir-terbuka')->assertUnprocessable();
        $this->flushHeaders()->getJson('/api/pos/v1/shift/kasir-terbuka?UuidPengguna='.$k['Kasir']->Uuid)->assertUnauthorized();
    });

    it('server tetap menerima shift ganda yang datang saat offline dan menandainya PerluTinjauan (perilaku lama tidak berubah)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'])]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $b = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Belakang');
        $kedua = BantuanKasir::ItemBukaShift($k['Kasir']);
        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [$kedua]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->where('Uuid', $kedua['Uuid'])->firstOrFail();
        expect($shift->PerluTinjauan)->toBeTrue()->and($shift->AlasanTinjauan)->toContain('BR-06.1');
    });
});
