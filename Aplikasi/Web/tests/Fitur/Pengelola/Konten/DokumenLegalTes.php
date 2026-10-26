<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisDokumenLegal;
use App\Domain\Tenant\Enum\StatusDokumenLegal;
use App\Domain\Tenant\Kueri\DokumenLegalBerlaku;
use App\Domain\Tenant\Model\DokumenLegal;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

function MasukSebagaiLegal(TestCase $tes, ?PenggunaPengelola $pengguna = null): TestCase
{
    return $tes->actingAs($pengguna ?? BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal), 'pengelola')
        ->withSession(BantuanPengelola::SesiTerverifikasi());
}

function AmbilDokumenUji(JenisDokumenLegal $jenis, int $versi): DokumenLegal
{
    return DokumenLegal::query()->where('Jenis', $jenis->value)->where('Versi', $versi)->sole();
}

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianDokumenUji(JenisDokumenLegal $jenis, array $ubah = []): array
{
    return [
        'Jenis' => $jenis->value,
        'Judul' => 'Syarat & Ketentuan Layanan',
        'Isi' => "# Syarat & Ketentuan\n\n1. Layanan disediakan apa adanya.",
        'RingkasanPerubahan' => null,
        'Materiil' => false,
        'BerlakuMulai' => '2026-09-23',
        ...$ubah,
    ];
}

/** Membuat draf lalu menerbitkannya lewat HTTP sebagai Konten & Legal yang sedang masuk. */
function TerbitkanDokumenUji(TestCase $tes, JenisDokumenLegal $jenis, array $ubah = []): DokumenLegal
{
    $tes->post(BantuanPengelola::Url('/legal'), ['Jenis' => $jenis->value])->assertSessionHasNoErrors();
    $draf = DokumenLegal::query()->where('Jenis', $jenis->value)->where('Status', 'Draf')->sole();
    $tes->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji($jenis, $ubah))->assertSessionHasNoErrors();
    $tes->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();

    return $draf->refresh();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
});

describe('Draf & terbit dokumen legal (P-06)', function (): void {
    it('Konten & Legal membuat draf, menyunting, lalu menerbitkan. Tercatat di log audit', function (): void {
        MasukSebagaiLegal($this);

        $dokumen = TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);

        expect($dokumen->Status)->toBe(StatusDokumenLegal::Terbit)
            ->and($dokumen->Versi)->toBe(1)
            ->and($dokumen->Isi)->toContain('Layanan disediakan apa adanya')
            ->and(LogAuditPengelola::query()->whereIn('Aksi', ['legal.draf.buat', 'legal.draf.ubah', 'legal.terbitkan'])->count())->toBe(3);
    });

    it('BR-P06.1: versi terbit tidak bisa diubah atau dihapus', function (): void {
        MasukSebagaiLegal($this);
        $dokumen = TerbitkanDokumenUji($this, JenisDokumenLegal::KebijakanPrivasi);

        $this->put(BantuanPengelola::Url("/legal/{$dokumen->Uuid}"), IsianDokumenUji(JenisDokumenLegal::KebijakanPrivasi, ['Judul' => 'Ubah']))
            ->assertSessionHasErrors('Umum');
        $this->delete(BantuanPengelola::Url("/legal/{$dokumen->Uuid}"))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url("/legal/{$dokumen->Uuid}/terbitkan"))->assertSessionHasErrors('Umum');

        expect(fn () => $dokumen->update(['Judul' => 'Langsung']))->toThrow(LogicException::class)
            ->and(DokumenLegal::query()->count())->toBe(1);
    });

    it('BR-P06.4: satu draf per jenis. Draf baru menyalin isi versi terakhir. Draf bisa dihapus', function (): void {
        MasukSebagaiLegal($this);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);

        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan'])->assertSessionHasErrors('Jenis');

        $draf = AmbilDokumenUji(JenisDokumenLegal::SyaratKetentuan, 2);
        expect($draf->Isi)->toBe(AmbilDokumenUji(JenisDokumenLegal::SyaratKetentuan, 1)->Isi);

        $this->delete(BantuanPengelola::Url("/legal/{$draf->Uuid}"))->assertRedirect(BantuanPengelola::Url('/legal'));
        expect(DokumenLegal::query()->count())->toBe(1);
    });

    it('jenis dokumen draf tidak bisa diganti', function (): void {
        MasukSebagaiLegal($this);
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'Sla'])->assertSessionHasNoErrors();
        $draf = DokumenLegal::query()->sole();

        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::KontrakMitra))->assertSessionHasErrors('Jenis');
    });
});

describe('Tanggal berlaku & pengumuman (BR-P06.3)', function (): void {
    it('tanggal berlaku tidak boleh lewat dan harus setelah versi terbit sebelumnya', function (): void {
        MasukSebagaiLegal($this);
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan']);
        $draf = DokumenLegal::query()->sole();
        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-09-22']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasErrors('BerlakuMulai');

        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-10-01']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();

        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan']);
        $draf2 = AmbilDokumenUji(JenisDokumenLegal::SyaratKetentuan, 2);
        $this->put(BantuanPengelola::Url("/legal/{$draf2->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-10-01']));
        $this->post(BantuanPengelola::Url("/legal/{$draf2->Uuid}/terbitkan"))->assertSessionHasErrors('BerlakuMulai');
    });

    it('perubahan materiil yang menggantikan versi lama wajib berlaku minimal 30 hari setelah terbit', function (): void {
        MasukSebagaiLegal($this);
        TerbitkanDokumenUji($this, JenisDokumenLegal::KebijakanPrivasi, ['Materiil' => true]);

        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'KebijakanPrivasi']);
        $draf = AmbilDokumenUji(JenisDokumenLegal::KebijakanPrivasi, 2);
        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::KebijakanPrivasi, ['Materiil' => true, 'BerlakuMulai' => '2026-10-22']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasErrors('BerlakuMulai');

        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::KebijakanPrivasi, ['Materiil' => true, 'BerlakuMulai' => '2026-10-23']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();
    });

    it('tanggal "hari ini" mengikuti WIB, bukan UTC', function (): void {
        // 02:00 WIB tanggal 23 = 19:00 UTC tanggal 22.
        $this->travelTo(Carbon::parse('2026-09-23 02:00:00', 'Asia/Jakarta'));
        MasukSebagaiLegal($this);
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan']);
        $draf = DokumenLegal::query()->sole();

        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-09-22']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasErrors('BerlakuMulai');
        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-09-23']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();
    });

    it('perubahan tidak materiil boleh berlaku keesokan harinya', function (): void {
        MasukSebagaiLegal($this);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);

        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan']);
        $draf = AmbilDokumenUji(JenisDokumenLegal::SyaratKetentuan, 2);
        $this->put(BantuanPengelola::Url("/legal/{$draf->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-09-24', 'RingkasanPerubahan' => 'Perbaikan salah ketik']));
        $this->post(BantuanPengelola::Url("/legal/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();
    });
});

describe('Versi berlaku & prasyarat registrasi (BR-P06.2, BR-P06.4)', function (): void {
    it('registrasi baru siap setelah S&K, Kebijakan Privasi, dan PPD berlaku. Versi terjadwal belum dihitung', function (): void {
        $kueri = app(DokumenLegalBerlaku::class);
        expect($kueri->AmbilKekuranganRegistrasi(now()))->toBe([JenisDokumenLegal::SyaratKetentuan, JenisDokumenLegal::KebijakanPrivasi, JenisDokumenLegal::PerjanjianPemrosesanData]);

        MasukSebagaiLegal($this);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);
        TerbitkanDokumenUji($this, JenisDokumenLegal::PerjanjianPemrosesanData);
        TerbitkanDokumenUji($this, JenisDokumenLegal::KebijakanPrivasi, ['BerlakuMulai' => '2026-09-25']);

        expect($kueri->AmbilKekuranganRegistrasi(now()))->toBe([JenisDokumenLegal::KebijakanPrivasi])
            ->and($kueri->AmbilKekuranganRegistrasi(Carbon::parse('2026-09-25 00:30:00', 'Asia/Jakarta')))->toBe([]);
    });

    it('status tampilan Berlaku, Terjadwal, Digantikan, dan Draf dihitung dari tanggal', function (): void {
        MasukSebagaiLegal($this);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-09-24']);
        TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan, ['BerlakuMulai' => '2026-12-01']);
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'SyaratKetentuan']);

        $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
        MasukSebagaiLegal($this);
        $this->get(BantuanPengelola::Url('/legal'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Legal/Daftar')
                ->where('Dokumen.0.Jenis', 'SyaratKetentuan')
                ->where('Dokumen.0.Versi.0.StatusTampilan', 'Draf')
                ->where('Dokumen.0.Versi.1.StatusTampilan', 'Terjadwal')
                ->where('Dokumen.0.Versi.2.StatusTampilan', 'Berlaku')
                ->where('Dokumen.0.Versi.3.StatusTampilan', 'Digantikan'));
        expect(app(DokumenLegalBerlaku::class)->Cari(JenisDokumenLegal::SyaratKetentuan, now())?->Versi)->toBe(2);
    });
});

describe('Izin dokumen legal', function (): void {
    it('semua peran boleh membaca. Hanya Konten & Legal dan Super Admin yang mengubah', function (PeranPengelolaBawaan $peran): void {
        MasukSebagaiLegal($this);
        $dokumen = TerbitkanDokumenUji($this, JenisDokumenLegal::SyaratKetentuan);

        MasukSebagaiLegal($this, BantuanPengelola::BuatAnggota($peran));
        $this->get(BantuanPengelola::Url('/legal'))->assertOk();
        $this->get(BantuanPengelola::Url("/legal/{$dokumen->Uuid}"))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Pengelola/Legal/Dokumen')->where('Dokumen.Versi', 1));
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'Sla'])->assertForbidden();
        $this->post(BantuanPengelola::Url("/legal/{$dokumen->Uuid}/terbitkan"))->assertForbidden();
        $this->put(BantuanPengelola::Url("/legal/{$dokumen->Uuid}"), IsianDokumenUji(JenisDokumenLegal::SyaratKetentuan))->assertForbidden();
        $this->delete(BantuanPengelola::Url("/legal/{$dokumen->Uuid}"))->assertForbidden();
    })->with([
        PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Dukungan,
        PeranPengelolaBawaan::Analis, PeranPengelolaBawaan::MitraPenjualan,
    ]);

    it('draf hanya terlihat oleh penyusun; Super Admin boleh menyusun', function (): void {
        MasukSebagaiLegal($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(BantuanPengelola::Url('/legal'), ['Jenis' => 'Sla'])->assertSessionHasNoErrors();
        $draf = DokumenLegal::query()->sole();
        $this->get(BantuanPengelola::Url("/legal/{$draf->Uuid}"))->assertOk();

        MasukSebagaiLegal($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));
        $this->get(BantuanPengelola::Url("/legal/{$draf->Uuid}"))->assertNotFound();
        $this->get(BantuanPengelola::Url('/legal'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Dokumen.3.Jenis', 'Sla')->has('Dokumen.3.Versi', 0));
    });
});

describe('Draf dokumen legal bawaan (P-06)', function (): void {
    it('membuat draf tiga dokumen wajib dari naskah bawaan, tidak menerbitkan, dan idempoten', function (): void {
        $this->artisan('legal:siapkan-bawaan')->assertSuccessful();

        foreach (JenisDokumenLegal::AmbilWajibRegistrasi() as $jenis) {
            $dokumen = DokumenLegal::query()->where('Jenis', $jenis->value)->sole();
            expect($dokumen->Status)->toBe(StatusDokumenLegal::Draf)
                ->and($dokumen->Versi)->toBe(1)
                ->and(mb_strlen($dokumen->Isi))->toBeGreaterThan(2000)
                ->and($dokumen->Isi)->toContain('# 1.');
        }

        expect(app(DokumenLegalBerlaku::class)->Cari(JenisDokumenLegal::KebijakanPrivasi, now()))->toBeNull();

        $this->artisan('legal:siapkan-bawaan')->assertSuccessful();
        expect(DokumenLegal::query()->count())->toBe(3);
    });

    it('tidak menyentuh jenis yang sudah punya versi', function (): void {
        DokumenLegal::query()->create([
            'Jenis' => JenisDokumenLegal::KebijakanPrivasi, 'Versi' => 1, 'Status' => StatusDokumenLegal::Terbit,
            'Judul' => 'Kebijakan Privasi', 'Isi' => 'Isi lama.', 'Materiil' => true, 'BerlakuMulai' => '2026-01-01',
        ]);

        $this->artisan('legal:siapkan-bawaan')->assertSuccessful();

        expect(DokumenLegal::query()->where('Jenis', JenisDokumenLegal::KebijakanPrivasi->value)->count())->toBe(1)
            ->and(DokumenLegal::query()->where('Jenis', JenisDokumenLegal::KebijakanPrivasi->value)->value('Isi'))->toBe('Isi lama.')
            ->and(DokumenLegal::query()->count())->toBe(3);
    });
});

describe('Draf pembaruan dokumen legal (P-06)', function (): void {
    it('membuat draf versi berikutnya bila isi terbit berbeda dari naskah, tanpa menyentuh versi terbit', function (): void {
        foreach (JenisDokumenLegal::AmbilWajibRegistrasi() as $jenis) {
            DokumenLegal::query()->create([
                'Jenis' => $jenis, 'Versi' => 1, 'Status' => StatusDokumenLegal::Terbit, 'Judul' => $jenis->AmbilLabel(),
                'Isi' => '# Naskah lama PAYOU', 'Materiil' => true, 'BerlakuMulai' => '2026-01-01',
            ]);
        }

        $this->artisan('legal:siapkan-pembaruan')->assertSuccessful();

        foreach (JenisDokumenLegal::AmbilWajibRegistrasi() as $jenis) {
            $versi1 = DokumenLegal::query()->where('Jenis', $jenis->value)->where('Versi', 1)->sole();
            $draf = DokumenLegal::query()->where('Jenis', $jenis->value)->where('Versi', 2)->sole();

            expect($versi1->Status)->toBe(StatusDokumenLegal::Terbit)->and($versi1->Isi)->toBe('# Naskah lama PAYOU')
                ->and($draf->Status)->toBe(StatusDokumenLegal::Draf)
                ->and($draf->Isi)->not->toContain('PAYOU')->and($draf->Isi)->toContain('# 1.')
                // Belum ada pengguna yang menyetujui versi 1: tidak ada yang terdampak, jadi tidak materiil.
                ->and($draf->Materiil)->toBeFalse();
        }

        // Idempoten: draf sudah ada.
        $this->artisan('legal:siapkan-pembaruan')->assertSuccessful();
        expect(DokumenLegal::query()->count())->toBe(6);
    });

    it('tidak membuat apa pun bila isi terbit sudah sama dengan naskah', function (): void {
        foreach (JenisDokumenLegal::AmbilWajibRegistrasi() as $jenis) {
            DokumenLegal::query()->create([
                'Jenis' => $jenis, 'Versi' => 1, 'Status' => StatusDokumenLegal::Terbit, 'Judul' => $jenis->AmbilLabel(),
                'Isi' => trim((string) file_get_contents(base_path("database/data/legal/{$jenis->value}.md"))),
                'Materiil' => true, 'BerlakuMulai' => '2026-01-01',
            ]);
        }

        $this->artisan('legal:siapkan-pembaruan')->assertSuccessful();

        expect(DokumenLegal::query()->count())->toBe(3);
    });

    it('perubahan menjadi materiil dan berlaku 30 hari lagi bila sudah ada pengguna yang menyetujui versi lama', function (): void {
        BantuanPendaftaran::SiapkanPrasyarat();
        BantuanTenantPengelola::BuatTenant();

        $this->artisan('legal:siapkan-pembaruan')->assertSuccessful();

        $draf = DokumenLegal::query()->where('Jenis', JenisDokumenLegal::SyaratKetentuan->value)->where('Versi', 2)->sole();
        expect($draf->Materiil)->toBeTrue()
            ->and($draf->BerlakuMulai->toDateString())->toBe(now('Asia/Jakarta')->addDays(30)->toDateString());
    });
});

it('halaman publik dokumen legal menyertakan tab ke dokumen legal lain yang berlaku', function (): void {
    foreach ([JenisDokumenLegal::SyaratKetentuan, JenisDokumenLegal::KebijakanPrivasi] as $jenis) {
        DokumenLegal::query()->create([
            'Jenis' => $jenis, 'Versi' => 1, 'Status' => StatusDokumenLegal::Terbit, 'Judul' => $jenis->AmbilLabel(),
            'Isi' => "# 1. Pasal\n\nIsi.", 'Materiil' => true, 'BerlakuMulai' => '2026-01-01',
        ]);
    }

    $this->get('/legal/kebijakan-privasi')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Situs/DokumenLegal')
        ->has('Daftar', 2)
        ->where('Daftar.1.Aktif', true));
});
