<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\Tenant\Aksi\HapusPermanenTenantUji;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Aksi\BuatTagihanLangganan;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * D-213 (P-07, BR-P07.9): hapus permanen tenant berpenanda Uji/Demo/Internal. Hanya Super Admin dengan 2FA baru; penanda,
 * slug, alasan, dan tidak adanya tagihan berbayar diperiksa; semua data tenant hilang (tabel ber-`IdTenant`, akun yang
 * hanya milik tenant ini, berkas), tenant lain dan jejak audit konsol tetap.
 */

beforeEach(function (): void {
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
});

/**
 * Tenant dengan data nyata (produk, pelanggan, anggota tim, berkas) dan penanda opsional.
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Staf: Pengguna}
 */
function SiapkanTenantHapusUji(string $nama, ?PenandaTenant $penanda = PenandaTenant::Uji): array
{
    $t = BantuanKatalog::SiapkanTenantProduk($nama);
    $id = $t['Tenant']->Id;
    $staf = BantuanOrganisasi::TambahAnggota($id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::AturKonteks($id);
    BantuanKatalog::BuatKategori('Minuman Uji');
    BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Uji']);

    Pelanggan::query()->create(['Nama' => 'Siti Uji', 'NoHp' => '6281355559999']);
    Storage::disk('local')->put("produk/{$id}/a.webp", 'isi');
    Storage::disk('local')->put("akuntansi/kas-bank/{$id}/bukti.pdf", 'isi');
    Tenant::query()->whereKey($id)->update(['Penanda' => $penanda?->value]);

    return ['Tenant' => $t['Tenant']->refresh(), 'Pemilik' => $t['Pemilik'], 'Staf' => $staf];
}

function HapusPermanenUji(TestCase $tes, Tenant $tenant, array $isian = []): TestResponse
{
    return $tes->delete(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/hapus-permanen"), $isian + [
        'Alasan' => 'Tenant percobaan fitur kasir, sudah tidak dipakai.', 'KonfirmasiSlug' => $tenant->Slug,
    ]);
}

/** Jumlah baris di semua tabel ber-`IdTenant` (kecuali audit konsol) untuk satu tenant. */
function SisaBarisTenantUji(int $idTenant): int
{
    $jumlah = 0;

    foreach (DB::select('SELECT TABLE_NAME AS Nama FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?', ['IdTenant']) as $tabel) {
        if (in_array($tabel->Nama, ['Tenant', ...HapusPermanenTenantUji::TABEL_DIPERTAHANKAN], true)) {
            continue;
        }

        $jumlah += DB::table((string) $tabel->Nama)->where('IdTenant', $idTenant)->count();
    }

    return $jumlah;
}

describe('D-213 hapus permanen tenant uji', function (): void {
    it('menghapus tenant berpenanda beserta semua datanya; tenant lain, akun bersama, dan audit konsol tetap', function (): void {
        $uji = SiapkanTenantHapusUji('Toko Uji Kasir');
        $lain = SiapkanTenantHapusUji('Warung Sungguhan', penanda: null);
        $id = $uji['Tenant']->Id;
        $slug = $uji['Tenant']->Slug;

        // Kasir juga anggota tenant lain: hanya dilepas dari tenant uji, akunnya tetap.
        TenantPengguna::query()->create([
            'IdTenant' => $lain['Tenant']->Id, 'IdPengguna' => $uji['Staf']->Id, 'Pemilik' => false,
            'IdPeran' => BantuanOrganisasi::Peran($lain['Tenant']->Id, PeranTenantBawaan::Kasir)->Id, 'SemuaOutlet' => true,
        ]);
        $baris = SisaBarisTenantUji($id);
        expect($baris)->toBeGreaterThan(5);

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        HapusPermanenUji($this, $uji['Tenant'])->assertSessionHasNoErrors()->assertRedirect('/tenant')->assertSessionHas('Kilat');

        expect(DB::table('Tenant')->where('Id', $id)->exists())->toBeFalse()
            ->and(SisaBarisTenantUji($id))->toBe(0)
            // Owner hanya milik tenant uji: akunnya hilang. Kasir bersama tetap ada.
            ->and(DB::table('Pengguna')->where('Id', $uji['Pemilik']->Id)->exists())->toBeFalse()
            ->and(DB::table('Pengguna')->where('Id', $uji['Staf']->Id)->exists())->toBeTrue()
            ->and(DB::table('TenantPengguna')->where('IdPengguna', $uji['Staf']->Id)->where('IdTenant', $lain['Tenant']->Id)->exists())->toBeTrue()
            // Tenant lain utuh.
            ->and(DB::table('Tenant')->where('Id', $lain['Tenant']->Id)->exists())->toBeTrue()
            ->and(DB::table('Pelanggan')->where('IdTenant', $lain['Tenant']->Id)->count())->toBe(1)
            ->and(DB::table('Produk')->where('IdTenant', $lain['Tenant']->Id)->count())->toBeGreaterThan(0)
            // Berkas tenant uji hilang (pribadi dan pembukuan).
            ->and(Storage::disk('local')->exists("produk/{$id}/a.webp"))->toBeFalse()
            ->and(Storage::disk('local')->exists("akuntansi/kas-bank/{$id}/bukti.pdf"))->toBeFalse()
            ->and(Storage::disk('local')->exists("produk/{$lain['Tenant']->Id}/a.webp"))->toBeTrue();

        $audit = LogAuditPengelola::query()->where('Aksi', 'tenant.hapus-permanen')->sole();
        expect($audit->IdTenant)->toBe($id)
            ->and($audit->NilaiLama['Slug'])->toBe($slug)
            ->and($audit->NilaiLama['Penanda'])->toBe('Uji')
            ->and($audit->NilaiBaru['Baris'])->toBeGreaterThan(5)
            ->and($audit->Alasan)->toContain('percobaan fitur kasir');
    });

    it('ketiga penanda (Uji, Demo, Internal) boleh dihapus', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        foreach ([PenandaTenant::Uji, PenandaTenant::Demo, PenandaTenant::Internal] as $penanda) {
            $t = BantuanTenantPengelola::BuatTenant("Toko {$penanda->value}", Str::lower($penanda->value).'@contoh.id');
            Tenant::query()->whereKey($t->Id)->update(['Penanda' => $penanda->value]);

            HapusPermanenUji($this, $t->refresh())->assertSessionHasNoErrors();
            expect(DB::table('Tenant')->where('Id', $t->Id)->exists())->toBeFalse();
        }
    });

    it('tenant sungguhan (tanpa penanda) tidak pernah bisa dihapus lewat jalur ini, meski slug benar', function (): void {
        $biasa = SiapkanTenantHapusUji('Warung Sungguhan', penanda: null);
        $baris = SisaBarisTenantUji($biasa['Tenant']->Id);

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        HapusPermanenUji($this, $biasa['Tenant'])->assertSessionHasErrors('Umum');

        expect(DB::table('Tenant')->where('Id', $biasa['Tenant']->Id)->exists())->toBeTrue()
            ->and(SisaBarisTenantUji($biasa['Tenant']->Id))->toBe($baris)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.hapus-permanen')->count())->toBe(0);
    });

    it('slug yang diketik harus sama dan alasan wajib; tidak ada yang terhapus bila keliru', function (): void {
        $uji = SiapkanTenantHapusUji('Toko Uji Kasir');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        HapusPermanenUji($this, $uji['Tenant'], ['KonfirmasiSlug' => 'slug-salah'])->assertSessionHasErrors('KonfirmasiSlug');
        HapusPermanenUji($this, $uji['Tenant'], ['Alasan' => 'singkat'])->assertSessionHasErrors('Alasan');
        HapusPermanenUji($this, $uji['Tenant'], ['KonfirmasiSlug' => ''])->assertSessionHasErrors('KonfirmasiSlug');

        expect(DB::table('Tenant')->where('Id', $uji['Tenant']->Id)->exists())->toBeTrue();
    });

    it('hanya Super Admin yang berizin; peran lain 403 dan data utuh', function (): void {
        $uji = SiapkanTenantHapusUji('Toko Uji Kasir');

        foreach ([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Dukungan] as $peran) {
            BantuanTenantPengelola::Masuk($this, $peran);
            HapusPermanenUji($this, $uji['Tenant'])->assertForbidden();
        }

        expect(DB::table('Tenant')->where('Id', $uji['Tenant']->Id)->exists())->toBeTrue();
    });

    it('tenant yang punya tagihan langganan berbayar ditolak walau berpenanda', function (): void {
        $uji = SiapkanTenantHapusUji('Toko Uji Berbayar');
        BantuanTagihan::AturTenant($uji['Tenant']);
        $tagihan = app(BuatTagihanLangganan::class)->Jalankan($uji['Pemilik']->Id, 'PRO', SiklusTagihan::Bulanan);
        TagihanLangganan::query()->whereKey($tagihan->Id)->update(['DibayarPada' => now(), 'Status' => 'Lunas']);

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        HapusPermanenUji($this, $uji['Tenant'])->assertSessionHasErrors('Umum');

        expect(DB::table('Tenant')->where('Id', $uji['Tenant']->Id)->exists())->toBeTrue();
    });

    it('aksi massal: yang berpenanda terhapus, yang sungguhan dilewati; frasa konfirmasi wajib', function (): void {
        $a = SiapkanTenantHapusUji('Toko Uji A');
        $b = SiapkanTenantHapusUji('Toko Uji B', PenandaTenant::Demo);
        $asli = SiapkanTenantHapusUji('Warung Sungguhan', penanda: null);
        $uuid = [$a['Tenant']->Uuid, $b['Tenant']->Uuid, $asli['Tenant']->Uuid];
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $url = BantuanPengelola::Url('/tenant/massal/hapus-permanen');
        $alasan = 'Membersihkan tenant percobaan lama.';

        $this->delete($url, ['Uuid' => $uuid, 'Alasan' => $alasan, 'Frasa' => 'hapus'])->assertSessionHasErrors('Frasa');
        expect(DB::table('Tenant')->whereIn('Uuid', $uuid)->count())->toBe(3);

        $this->delete($url, ['Uuid' => $uuid, 'Alasan' => $alasan, 'Frasa' => 'HAPUS PERMANEN'])->assertSessionHasNoErrors()->assertSessionHas('Kilat');

        expect(DB::table('Tenant')->whereIn('Uuid', [$a['Tenant']->Uuid, $b['Tenant']->Uuid])->count())->toBe(0)
            ->and(DB::table('Tenant')->where('Uuid', $asli['Tenant']->Uuid)->exists())->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.hapus-permanen')->count())->toBe(2);
    });

    it('aksi massal ditolak untuk peran tanpa izin', function (): void {
        $a = SiapkanTenantHapusUji('Toko Uji A');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        $this->delete(BantuanPengelola::Url('/tenant/massal/hapus-permanen'), ['Uuid' => [$a['Tenant']->Uuid], 'Alasan' => 'Membersihkan tenant lama.', 'Frasa' => 'HAPUS PERMANEN'])->assertForbidden();

        expect(DB::table('Tenant')->where('Id', $a['Tenant']->Id)->exists())->toBeTrue();
    });
});
