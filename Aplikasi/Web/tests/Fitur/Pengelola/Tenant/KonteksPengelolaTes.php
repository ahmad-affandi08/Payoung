<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Tenant\TenantBelumDitetapkan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('KonteksPengelola::JalankanLintasTenant (CLAUDE.md #11, §13.8)', function (): void {
    it('satu tenant: scope MilikTenant tetap aktif untuk tenant itu, konteks lama dipulihkan, akses diaudit', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Kopi Nusantara');
        $b = BantuanTenantPengelola::BuatTenant('Bakso Pak Kumis');
        $konteksTenant = app(KonteksTenant::class);
        $konteksTenant->Atur($a->Id);

        $idTenantOutlet = app(KonteksPengelola::class)->JalankanLintasTenant(
            'Uji baca outlet tenant B',
            fn (): array => Outlet::query()->pluck('IdTenant')->all(),
            $b->Id,
        );

        expect($idTenantOutlet)->toBe([$b->Id])
            ->and($konteksTenant->Ambil())->toBe($a->Id);

        $log = LogAuditPengelola::query()->where('Aksi', KonteksPengelola::AKSI_AUDIT)->sole();
        expect($log->IdTenant)->toBe($b->Id)
            ->and($log->Alasan)->toBe('Uji baca outlet tenant B')
            ->and($log->NilaiBaru)->toBe(['Cakupan' => 'SatuTenant']);
    });

    it('konteks dipulihkan walau closure melempar masalah', function (): void {
        $a = BantuanTenantPengelola::BuatTenant();

        expect(fn () => app(KonteksPengelola::class)->JalankanLintasTenant('Uji masalah', fn () => throw new RuntimeException('gagal'), $a->Id))
            ->toThrow(RuntimeException::class)
            ->and(app(KonteksTenant::class)->Ambil())->toBeNull()
            ->and(fn () => Outlet::query()->count())->toThrow(TenantBelumDitetapkan::class);
    });

    it('lintas semua tenant hanya lewat KueriLintas di dalam JalankanLintasTenant', function (): void {
        BantuanTenantPengelola::BuatTenant('Kopi Nusantara');
        BantuanTenantPengelola::BuatTenant('Bakso Pak Kumis');
        $konteks = app(KonteksPengelola::class);

        $jumlah = $konteks->JalankanLintasTenant(
            'Hitung outlet seluruh platform',
            fn (KonteksPengelola $lintas): int => $lintas->KueriLintas(Outlet::class)->count(),
        );

        expect($jumlah)->toBe(2)
            ->and(fn () => $konteks->KueriLintas(Outlet::class))->toThrow(LogicException::class)
            ->and(LogAuditPengelola::query()->where('Aksi', KonteksPengelola::AKSI_AUDIT)->sole()->IdTenant)->toBeNull();
    });

    it('wajib beralasan', function (): void {
        expect(fn () => app(KonteksPengelola::class)->JalankanLintasTenant('  ', fn () => 1))->toThrow(InvalidArgumentException::class)
            ->and(LogAuditPengelola::query()->count())->toBe(0);
    });
});

describe('Izin P-07 per peran (§19.3)', function (): void {
    it('pemetaan izin tenant sesuai tabel peran internal', function (): void {
        $izinTenant = fn (PeranPengelolaBawaan $peran): array => array_values(array_filter(
            array_map(fn (IzinPengelola $izin) => $izin->value, $peran->AmbilIzin()),
            fn (string $kunci) => str_starts_with($kunci, 'tenant.'),
        ));

        expect($izinTenant(PeranPengelolaBawaan::SuperAdmin))->toEqualCanonicalizing([
            'tenant.lihat', 'tenant.catatan.tulis', 'tenant.trial.perpanjang', 'tenant.override.kelola',
            'tenant.tangguhkan', 'tenant.aktifkan', 'tenant.penanda.ubah', 'tenant.penghapusan-data.kelola',
        ])
            ->and($izinTenant(PeranPengelolaBawaan::Dukungan))->toEqualCanonicalizing(['tenant.lihat', 'tenant.catatan.tulis', 'tenant.trial.perpanjang', 'tenant.override.kelola'])
            ->and($izinTenant(PeranPengelolaBawaan::MitraPenjualan))->toEqualCanonicalizing(['tenant.lihat', 'tenant.catatan.tulis', 'tenant.trial.perpanjang'])
            ->and($izinTenant(PeranPengelolaBawaan::Keuangan))->toEqualCanonicalizing(['tenant.lihat', 'tenant.catatan.tulis', 'tenant.aktifkan'])
            ->and($izinTenant(PeranPengelolaBawaan::Teknis))->toEqualCanonicalizing(['tenant.lihat', 'tenant.catatan.tulis'])
            ->and($izinTenant(PeranPengelolaBawaan::KontenLegal))->toBe([])
            ->and($izinTenant(PeranPengelolaBawaan::Analis))->toBe([]);

        foreach (PeranPengelolaBawaan::cases() as $peran) {
            $kunci = array_map(fn (IzinPengelola $izin) => $izin->value, $peran->AmbilIzin());
            expect($kunci)->toBe(array_values(array_unique($kunci)));
        }
    });
});
