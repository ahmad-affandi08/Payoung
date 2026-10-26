<?php

declare(strict_types=1);

use App\Domain\Pengelola\Tenant\Model\CatatanTenant;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('P-07 catatan internal (BR-P07.9)', function (): void {
    it('semua peran yang bekerja dengan tenant bisa menulis catatan. Tampil di 360° dan riwayat', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $anggota = BantuanTenantPengelola::Masuk($this, $peran);

        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/catatan"), ['Isi' => "Owner minta dihubungi via WA sore hari.\nSudah coba telepon 2x."])
            ->assertSessionHasNoErrors();

        $catatan = CatatanTenant::query()->sole();
        expect($catatan->IdTenant)->toBe($tenant->Id)
            ->and($catatan->DibuatOleh)->toBe($anggota->Id)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.catatan.tulis')->sole()->IdTenant)->toBe($tenant->Id);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Tenant.Catatan.0.Isi', "Owner minta dihubungi via WA sore hari.\nSudah coba telepon 2x.")
                ->where('Tenant.Catatan.0.Penulis', $anggota->Nama)
                ->where('Tenant.Riwayat.0.Aksi', 'tenant.catatan.tulis'));
    })->with([
        PeranPengelolaBawaan::SuperAdmin,
        PeranPengelolaBawaan::Keuangan,
        PeranPengelolaBawaan::Dukungan,
        PeranPengelolaBawaan::Teknis,
        PeranPengelolaBawaan::MitraPenjualan,
    ]);

    it('catatan append-only dan wajib berisi', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/catatan"), ['Isi' => ''])->assertSessionHasErrors('Isi');
        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/catatan"), ['Isi' => 'Catatan pertama'])->assertSessionHasNoErrors();

        $catatan = CatatanTenant::query()->sole();
        expect(fn () => $catatan->update(['Isi' => 'diubah']))->toThrow(LogicException::class)
            ->and(fn () => $catatan->delete())->toThrow(LogicException::class);
    });
});

describe('P-07 penanda tenant (BR-P07.8)', function (): void {
    it('Super Admin menandai tenant Demo lalu menghapus penanda. Tercatat dengan nilai lama/baru', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => 'Demo', 'Alasan' => 'Akun demo untuk pameran franchise.'])
            ->assertSessionHasNoErrors();
        expect($tenant->refresh()->Penanda)->toBe(PenandaTenant::Demo);

        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => 'Demo', 'Alasan' => 'Klik dua kali tanpa sengaja.'])
            ->assertSessionHasErrors('Penanda');

        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => '', 'Alasan' => 'Pameran selesai, jadi pelanggan biasa.'])
            ->assertSessionHasNoErrors();
        expect($tenant->refresh()->Penanda)->toBeNull();

        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.penanda.ubah')->orderBy('Id')->get();
        expect($log)->toHaveCount(2)
            ->and($log[0]->NilaiLama)->toBe(['Penanda' => null])
            ->and($log[0]->NilaiBaru)->toBe(['Penanda' => 'Demo'])
            ->and($log[1]->IdTenant)->toBe($tenant->Id);
    });

    it('menolak penanda tak dikenal atau tanpa alasan', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => 'Vip', 'Alasan' => 'Pelanggan penting.'])
            ->assertSessionHasErrors('Penanda');
        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => 'Uji', 'Alasan' => ''])
            ->assertSessionHasErrors('Alasan');
        expect($tenant->refresh()->Penanda)->toBeNull();
    });

    it('§19.3: selain Super Admin tidak boleh mengubah penanda', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        $this->put(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/penanda"), ['Penanda' => 'Uji', 'Alasan' => 'Akun uji tim QA internal.'])
            ->assertForbidden();
    })->with([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Dukungan]);
});
