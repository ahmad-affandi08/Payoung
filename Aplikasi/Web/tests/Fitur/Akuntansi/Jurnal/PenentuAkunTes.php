<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\KesiapanPeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a PenentuAkun, KesiapanPeranAkun, PenjagaKunciPeriode (DesainF05a C.5)', function (): void {
    it('BR-P03.3: template sektor memetakan semua peran persediaan. Kesiapan Siap', function (): void {
        BantuanPersediaan::SiapkanTenant();

        expect(app(KesiapanPeranAkun::class)->Periksa([
            PeranAkun::PersediaanBarangDagang, PeranAkun::PersediaanBahanBaku, PeranAkun::EkuitasSaldoAwal, PeranAkun::SelisihHpp,
        ]))->toBe(['Siap' => true, 'PeranBelumDipetakan' => []]);
    });

    it('kesiapan melaporkan peran belum dipetakan atau bertipe salah, tanpa duplikat, urut masukan', function (): void {
        BantuanPersediaan::SiapkanTenant();
        PemetaanAkun::query()->where('Kunci', PeranAkun::SelisihHpp->value)->delete();
        $beban = BantuanJurnal::AkunLain(TipeAkun::Beban, 0);
        PemetaanAkun::query()->where('Kunci', PeranAkun::EkuitasSaldoAwal->value)->update(['IdAkun' => $beban->Id]);

        expect(app(KesiapanPeranAkun::class)->Periksa([
            PeranAkun::SelisihHpp, PeranAkun::PersediaanBarangDagang, PeranAkun::EkuitasSaldoAwal, PeranAkun::SelisihHpp,
        ]))->toBe(['Siap' => false, 'PeranBelumDipetakan' => [
            ['Kunci' => 'SelisihHpp', 'Label' => 'Selisih HPP / penyesuaian persediaan'],
            ['Kunci' => 'EkuitasSaldoAwal', 'Label' => 'Ekuitas saldo awal'],
        ]]);
    });

    it('BR-02.4: pemetaan outlet dipakai untuk outlet itu, tanpa pemetaan tenant, outlet lain belum siap', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $idTenant = BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet);
        $akunSolo = BantuanJurnal::AkunLain(TipeAkun::Aset, $idTenant);
        PemetaanAkun::query()->create(['Kunci' => PeranAkun::KasOutlet->value, 'IdAkun' => $akunSolo->Id, 'IdOutlet' => $solo->Id]);
        $penentu = app(PenentuAkun::class);

        expect($penentu->AmbilIdAkun(PeranAkun::KasOutlet, $solo->Id))->toBe($akunSolo->Id)
            ->and($penentu->AmbilIdAkun(PeranAkun::KasOutlet, $t['Outlet']->Id))->toBe($idTenant)
            ->and($penentu->AmbilIdAkun(PeranAkun::KasOutlet, null))->toBe($idTenant);

        PemetaanAkun::query()->where('Kunci', PeranAkun::KasOutlet->value)->whereNull('IdOutlet')->delete();

        expect($penentu->AmbilIdAkun(PeranAkun::KasOutlet, $solo->Id))->toBe($akunSolo->Id)
            ->and(app(KesiapanPeranAkun::class)->Periksa([PeranAkun::KasOutlet], $solo->Id)['Siap'])->toBeTrue()
            ->and(app(KesiapanPeranAkun::class)->Periksa([PeranAkun::KasOutlet], $t['Outlet']->Id)['Siap'])->toBeFalse()
            ->and(app(KesiapanPeranAkun::class)->Periksa([PeranAkun::KasOutlet])['Siap'])->toBeFalse()
            ->and(fn () => $penentu->AmbilIdAkun(PeranAkun::KasOutlet, $t['Outlet']->Id))->toThrow(PelanggaranAturanBisnis::class, 'Akun untuk Kas outlet belum dipetakan.');
    });

    it('BR-P03.4: pemetaan berkunci lama (Waste) tetap dibaca untuk SusutPersediaan. Kunci baru menang', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $baru = BantuanJurnal::IdAkunPeran(PeranAkun::SusutPersediaan);
        $lama = BantuanJurnal::AkunLain(TipeAkun::Hpp, $baru);
        PemetaanAkun::query()->create(['Kunci' => 'Waste', 'IdAkun' => $lama->Id, 'IdOutlet' => null]);

        expect(app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::SusutPersediaan, null))->toBe($baru);

        PemetaanAkun::query()->where('Kunci', PeranAkun::SusutPersediaan->value)->delete();
        expect(app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::SusutPersediaan, null))->toBe($lama->Id);
    });

    it('isolasi tenant: pemetaan tenant lain tidak dipakai', function (): void {
        BantuanPersediaan::SiapkanTenant('Toko Pertanian Subur Makmur');
        $idA = BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang);
        BantuanPersediaan::SiapkanTenant('Toko Sepatu Langkah Pasti');
        $idB = BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang);
        PemetaanAkun::query()->where('Kunci', PeranAkun::PersediaanBarangDagang->value)->delete();

        expect($idA)->not->toBe($idB)
            ->and(app(PenentuAkun::class)->CariIdAkun(PeranAkun::PersediaanBarangDagang, null))->toBeNull();
    });

    it('PenjagaKunciPeriode: hanya periode yang dikunci tenant ini yang menolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Toko Buku Cerdas Ceria');
        BantuanPersediaan::KunciPeriode('2026-07', $t['Pemilik']->Id);
        $penjaga = app(PenjagaKunciPeriode::class);

        expect($penjaga->CekTerkunci('2026-07'))->toBeTrue()
            ->and($penjaga->CekTerkunci('2026-08'))->toBeFalse()
            ->and(fn () => $penjaga->PastikanTerbuka(CarbonImmutable::parse('2026-07-01')))->toThrow(PelanggaranAturanBisnis::class, 'Periode Juli 2026 sudah dikunci.');

        $penjaga->PastikanTerbuka(CarbonImmutable::parse('2026-08-01'));

        BantuanPersediaan::SiapkanTenant('Toko Buku Kedua');
        expect($penjaga->CekTerkunci('2026-07'))->toBeFalse();
    });
});
