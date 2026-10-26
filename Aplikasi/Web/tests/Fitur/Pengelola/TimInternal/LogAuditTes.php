<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Layanan\KamusPeristiwaAudit;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

describe('LogAuditPengelola append-only (BR-P01.3)', function (): void {
    it('tidak bisa diubah', function (): void {
        $log = app(PencatatAuditPengelola::class)->Catat('uji.aksi');

        $log->Aksi = 'uji.diubah';
        $log->save();
    })->throws(LogicException::class, 'append-only');

    it('tidak bisa dihapus', function (): void {
        app(PencatatAuditPengelola::class)->Catat('uji.aksi')->delete();
    })->throws(LogicException::class, 'append-only');

    it('Super Admin melihat log terbaru di atas dengan filter aksi', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $audit = app(PencatatAuditPengelola::class);
        $audit->Catat('tim.anggota.undang', idPelaku: $superAdmin->Id);
        $audit->Catat('sesi.masuk', idPelaku: $superAdmin->Id);

        $this->actingAs($superAdmin, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->get(BantuanPengelola::Url('/log-audit?cari=tim.anggota'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/LogAudit/Daftar')
                ->has('Log.Data', 1)
                ->where('Log.Data.0.Aksi', 'tim.anggota.undang')
                ->where('Log.Data.0.Kalimat', KamusPeristiwaAudit::Label('tim.anggota.undang'))
                ->where('Log.Data.0.Pelaku', $superAdmin->Nama));
    });

    it('log menampilkan nama tenant, objek terbaca, dan perubahan dalam bahasa sehari-hari. Cari lewat kalimat', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        BantuanPendaftaran::SiapkanPrasyarat();
        Mail::fake();
        $tenant = BantuanOrganisasi::BuatTenant('Kopi Nusantara')['Tenant'];
        app(PencatatAuditPengelola::class)->Catat(
            'tim.anggota.undang',
            objek: $tenant,
            idTenant: $tenant->Id,
            nilaiLama: ['Status' => 'Baru'],
            nilaiBaru: ['Status' => 'Diproses'],
            alasan: 'Uji',
            idPelaku: $superAdmin->Id,
        );

        $this->actingAs($superAdmin, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->get(BantuanPengelola::Url('/log-audit?cari='.urlencode(KamusPeristiwaAudit::Label('tim.anggota.undang'))))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->has('Log.Data', 1)
                ->where('Log.Data.0.NamaTenant', 'Kopi Nusantara')
                ->where('Log.Data.0.Objek', 'Tenant #'.$tenant->Id)
                ->where('Log.Data.0.Perubahan', [['Bidang' => 'Status', 'Lama' => 'Baru', 'Baru' => 'Diproses']])
                ->where('Log.Data.0.Alasan', 'Uji'));
    });

    it('peran tanpa izin audit tidak bisa membuka log', function (): void {
        $analis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);

        $this->actingAs($analis, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->get(BantuanPengelola::Url('/log-audit'))
            ->assertForbidden();

        expect(LogAuditPengelola::query()->count())->toBe(0);
    });
});
