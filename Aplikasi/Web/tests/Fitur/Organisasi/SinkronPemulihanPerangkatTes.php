<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Aksi\CabutPerangkat;
use App\Domain\Organisasi\Enum\AlasanPemulihanSinkron;
use App\Domain\Organisasi\Layanan\PenyediaTindakanPerangkat;
use App\Domain\Organisasi\Model\ItemSinkronPemulihan;
use App\Domain\Organisasi\Model\Perangkat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit P0 F-01 (BR-02.3, §18): outbox offline tidak terdampar saat perangkat dicabut, dan item selalu dikreditkan ke
 * perangkat pembuatnya. Perangkat dicabut masih boleh `sinkron/kirim` selama masa pemulihan untuk item yang dibuat
 * sebelum `DicabutPada` (waktu dari ULID item); perangkat yang diaktifkan ulang mengirim outbox lama dengan
 * `UuidPerangkatAsal`. Semua item jalur pemulihan ditandai di Kotak Tindakan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function UuidPada(CarbonImmutable $waktu): string
{
    return strtoupper((string) Str::ulid($waktu));
}

function Cabut(Perangkat $perangkat): Perangkat
{
    BantuanOrganisasi::AturKonteks($perangkat->IdTenant);

    return app(CabutPerangkat::class)->Jalankan($perangkat);
}

function KonteksTindakanPemilik(array $k): DataKonteksTindakan
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, CarbonImmutable::now('Asia/Jakarta')->startOfDay());
}

describe('perangkat dicabut dengan outbox tertunda', function (): void {
    it('item sebelum dicabut diterima atas nama perangkat itu + tinjauan. Item sesudahnya ditolak. Endpoint lain tetap 403', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $dibuat = CarbonImmutable::now()->subMinutes(20);
        $sebelum = BantuanKasir::ItemBukaShift($k['Kasir'], uuid: UuidPada($dibuat));
        Cabut($k['Perangkat']);
        $sesudah = BantuanKasir::ItemBukaShift($k['Supervisor'], uuid: UuidPada(CarbonImmutable::now()->addSecond()));

        $respons = BantuanKasir::Kirim($this, $k['Token'], [$sebelum, $sesudah])->assertOk()->assertJsonPath('PerangkatDicabut', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(array_column($respons->json('Hasil'), 'Status'))->toBe(['Diterima', 'Ditolak'])
            ->and($respons->json('Hasil.1.Galat.Kode'))->toBe('DibuatSetelahDicabut')
            ->and(Shift::query()->where('Uuid', $sebelum['Uuid'])->value('IdPerangkat'))->toBe($k['Perangkat']->Id);

        $catatan = ItemSinkronPemulihan::query()->sole();
        expect($catatan->Uuid)->toBe($sebelum['Uuid'])
            ->and($catatan->Alasan)->toBe(AlasanPemulihanSinkron::PerangkatDicabut)
            ->and($catatan->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($catatan->IdPerangkatPengirim)->toBe($k['Perangkat']->Id)
            ->and($catatan->DibuatPadaKlien->timestamp)->toBe($dibuat->timestamp);

        // Kirim ulang (respons sebelumnya hilang) = Duplikat, tanpa catatan ganda.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$sebelum]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ItemSinkronPemulihan::query()->count())->toBe(1);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertForbidden()->assertJsonPath('Galat.Kode', 'PerangkatDicabut');
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertForbidden()->assertJsonPath('Galat.Kode', 'PerangkatDicabut');
    });

    it('lewat masa pemulihan, sinkron juga ditolak 403', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $item = BantuanKasir::ItemBukaShift($k['Kasir'], uuid: UuidPada(CarbonImmutable::now()->subMinute()));
        Cabut($k['Perangkat']);
        $this->travel(8)->days();

        BantuanKasir::Kirim($this, $k['Token'], [$item])->assertForbidden()->assertJsonPath('Galat.Kode', 'PerangkatDicabut');
    });

    it('perangkat aktif biasa: tidak ada catatan pemulihan dan PerangkatDicabut = false', function (): void {
        $k = BantuanKasir::Siapkan($this);

        BantuanKasir::Kirim($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'])])->assertOk()
            ->assertJsonPath('PerangkatDicabut', false)
            ->assertJsonPath('Hasil.0.Status', 'Diterima');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(ItemSinkronPemulihan::query()->count())->toBe(0);
    });
});

describe('aktivasi ulang: outbox lama dikirim perangkat baru', function (): void {
    it('item lama dikreditkan ke perangkat asal, bukan pengirim. Dicatat untuk ditinjau di Kotak Tindakan', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = $k['Perangkat'];
        $item = BantuanKasir::ItemBukaShift($k['Kasir'], uuid: UuidPada(CarbonImmutable::now()->subMinutes(15)));
        Cabut($lama);
        ['Perangkat' => $baru, 'Token' => $tokenBaru] = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Depan Baru');

        $respons = BantuanKasir::Kirim($this, $tokenBaru, [[...$item, 'UuidPerangkatAsal' => strtolower($lama->Uuid)]])->assertOk()
            ->assertJsonPath('PerangkatDicabut', false)
            ->assertJsonPath('Hasil.0.Status', 'Diterima');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $catatan = ItemSinkronPemulihan::query()->sole();
        expect($respons->json('Hasil.0.Uuid'))->toBe($item['Uuid'])
            ->and(Shift::query()->where('Uuid', $item['Uuid'])->value('IdPerangkat'))->toBe($lama->Id)
            ->and($catatan->IdPerangkat)->toBe($lama->Id)
            ->and($catatan->IdPerangkatPengirim)->toBe($baru->Id)
            ->and($catatan->Alasan)->toBe(AlasanPemulihanSinkron::PerangkatDicabut);

        $butir = app(PenyediaTindakanPerangkat::class)->Kumpulkan(KonteksTindakanPemilik($k))[0];
        expect($butir->jumlah)->toBe(1)
            ->and($butir->jenisDokumen)->toBe('ItemSinkronPemulihan')
            ->and($butir->rincian[0]->uuid)->toBe($item['Uuid'])
            ->and($butir->rincian[0]->judul)->toBe('Shift.Buka dari '.$lama->Nama)
            ->and(app(PenyediaTindakanPerangkat::class)->SaringDokumen('ItemSinkronPemulihan', [$item['Uuid'], 'X']))->toBe([$item['Uuid']]);
    });

    it('item yang dibuat perangkat asal setelah dicabut tetap ditolak walau dikirim perangkat baru', function (): void {
        $k = BantuanKasir::Siapkan($this);
        Cabut($k['Perangkat']);
        ['Token' => $tokenBaru] = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Depan Baru');
        $item = [...BantuanKasir::ItemBukaShift($k['Kasir'], uuid: UuidPada(CarbonImmutable::now()->addSecond())), 'UuidPerangkatAsal' => $k['Perangkat']->Uuid];

        expect(BantuanKasir::KirimRingkas($this, $tokenBaru, [$item]))->toBe([['Ditolak', 'DibuatSetelahDicabut']]);
    });

    it('perangkat asal dari tenant lain atau belum pernah aktif tidak dikenal (isolasi tenant)', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Kopi Senja Pemulihan');
        $lain = BantuanKasir::Siapkan($this, 'Toko Lain Pemulihan');
        ['Perangkat' => $belumAktif] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet']);
        $item = fn (string $asal): array => [...BantuanKasir::ItemBukaShift($k['Kasir'], uuid: UuidPada(CarbonImmutable::now()->subMinute())), 'UuidPerangkatAsal' => $asal];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item($lain['Perangkat']->Uuid), $item($belumAktif->Uuid)]))
            ->toBe([['Ditolak', 'PerangkatAsalTidakDikenal'], ['Ditolak', 'PerangkatAsalTidakDikenal']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Shift::query()->count())->toBe(0);
    });
});
