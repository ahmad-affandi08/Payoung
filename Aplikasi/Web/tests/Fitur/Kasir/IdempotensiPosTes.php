<?php

declare(strict_types=1);

use App\Domain\Bersama\Idempotensi\Model\KunciIdempotensi;
use App\Domain\Kasir\Model\Shift;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit F-12 (aturan emas #13): `Idempotency-Key` di mutasi API POS ditegakkan server: permintaan sama diputar ulang,
 * kunci sama dengan isi berbeda 409, kunci per perangkat (perangkat lain boleh memakai kunci yang sama), tanpa header
 * tetap diproses (kompatibel mundur).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('kunci sama + isi sama = respons diputar ulang tanpa memproses ulang. Isi berbeda = 409. Format salah = 400', function (): void {
    $k = BantuanKasir::Siapkan($this);
    $item = BantuanKasir::ItemBukaShift($k['Kasir']);
    $kirim = fn (array $isi, string $kunci) => $this->withToken($k['Token'])->withHeader('Idempotency-Key', $kunci)->postJson('/api/pos/v1/sinkron/kirim', $isi);

    $pertama = $kirim(['Item' => [$item]], 'sinkron-01K5AAAA')->assertOk()->assertJsonPath('Hasil.0.Status', 'Diterima');
    expect($pertama->headers->has('Idempotency-Replayed'))->toBeFalse();

    $kedua = $kirim(['Item' => [$item]], 'sinkron-01K5AAAA')->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($kedua->json('Hasil'))->toBe($pertama->json('Hasil'));

    $kirim(['Item' => [BantuanKasir::ItemBukaShift($k['Supervisor'])]], 'sinkron-01K5AAAA')->assertStatus(409)->assertJsonPath('Galat.Kode', 'KunciIdempotensiBentrok');
    $kirim(['Item' => [$item]], 'pendek')->assertStatus(400)->assertJsonPath('Galat.Kode', 'KunciIdempotensiTidakValid');

    // Tanpa header: diproses biasa (item sudah ada = Duplikat).
    $this->flushHeaders();
    BantuanKasir::Kirim($this, $k['Token'], [$item])->assertOk()->assertJsonPath('Hasil.0.Status', 'Duplikat');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Shift::query()->count())->toBe(1)
        ->and(KunciIdempotensi::query()->count())->toBe(1)
        ->and(KunciIdempotensi::query()->sole()->IdPerangkat)->toBe($k['Perangkat']->Id);
});

it('kunci berlaku per perangkat dan kedaluwarsa setelah 24 jam', function (): void {
    $k = BantuanKasir::Siapkan($this);
    ['Token' => $tokenLain] = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Belakang');
    $item = BantuanKasir::ItemBukaShift($k['Kasir']);
    $itemLain = BantuanKasir::ItemBukaShift($k['Supervisor']);

    $this->withToken($k['Token'])->withHeader('Idempotency-Key', 'kunci-sama-123')->postJson('/api/pos/v1/sinkron/kirim', ['Item' => [$item]])->assertOk();
    $this->withToken($tokenLain)->withHeader('Idempotency-Key', 'kunci-sama-123')->postJson('/api/pos/v1/sinkron/kirim', ['Item' => [$itemLain]])
        ->assertOk()->assertHeaderMissing('Idempotency-Replayed');

    $this->travel(25)->hours();
    $this->withToken($k['Token'])->withHeader('Idempotency-Key', 'kunci-sama-123')->postJson('/api/pos/v1/sinkron/kirim', ['Item' => [$itemLain]])
        ->assertOk()->assertHeaderMissing('Idempotency-Replayed');
});
