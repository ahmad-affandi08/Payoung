<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Aksi\UbahPengaturanPersediaan;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanLaporan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Satu mutasi stok mentah di tenant konteks (mengunci metode HPP, H-4). */
function BuatRiwayatStokPengaturan(array $t): void
{
    $produk = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter'], '38500.00', $t['Pcs']);
    BantuanLaporan::CatatMutasi($produk, $t['Gudang'], '24.0000', '924000.00');
}

describe('F-05a pengaturan persediaan: metode HPP (BR-04.2) & stok minus (BR-05.2) (DesainF05a C.8, H-4)', function (): void {
    it('GET: props PropsPengaturanPersediaan dengan bawaan RataRata & stok minus tidak diizinkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $tenant = Tenant::query()->findOrFail($t['Tenant']->Id);
        $tenant->Pengaturan = array_diff_key($tenant->Pengaturan ?? [], ['MetodeHpp' => true, 'StokBolehMinus' => true]);
        $tenant->save();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)->get('/kelola/persediaan/pengaturan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Persediaan/Pengaturan')
                ->where('MetodeHpp', 'RataRata')
                ->where('StokBolehMinus', false)
                ->where('MetodeHppTerkunci', false)
                ->where('AlasanTerkunci', null)
                ->where('OpsiMetodeHpp', fn ($opsi) => collect($opsi)->pluck('Label', 'Nilai')->all() === ['RataRata' => 'Rata-rata tertimbang', 'Fifo' => 'FIFO']
                    && collect($opsi)->every(fn ($o) => is_string($o['Keterangan']) && $o['Keterangan'] !== '')));
    });

    it('PUT: mengubah metode HPP & stok minus sebelum ada riwayat stok. Audit nilai lama/baru. Kunci lain Pengaturan utuh', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $sebelum = Tenant::query()->findOrFail($t['Tenant']->Id)->Pengaturan ?? [];

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)
            ->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'Fifo', 'StokBolehMinus' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('kelola.persediaan.pengaturan'))
            ->assertSessionHas('Kilat', 'Pengaturan persediaan disimpan.');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $sesudah = Tenant::query()->findOrFail($t['Tenant']->Id)->Pengaturan ?? [];
        $pengaturan = app(PengaturanPersediaanTenant::class)->Ambil();
        $audit = LogAudit::query()->where('Peristiwa', 'persediaan.pengaturan.ubah')->sole();

        expect($pengaturan->metodeHpp)->toBe(MetodeHpp::Fifo)
            ->and($pengaturan->stokBolehMinus)->toBeTrue()
            ->and(array_diff_key($sesudah, ['MetodeHpp' => true, 'StokBolehMinus' => true]))->toBe(array_diff_key($sebelum, ['MetodeHpp' => true, 'StokBolehMinus' => true]))
            ->and($audit->NilaiLama)->toBe(['MetodeHpp' => 'RataRata', 'StokBolehMinus' => false])
            ->and($audit->NilaiBaru)->toBe(['MetodeHpp' => 'Fifo', 'StokBolehMinus' => true]);
    });

    it('tanpa perubahan = tidak ada yang ditulis (tanpa audit)', function (): void {
        BantuanPersediaan::SiapkanTenant();

        app(UbahPengaturanPersediaan::class)->Jalankan(MetodeHpp::RataRata, false);

        expect(LogAudit::query()->where('Peristiwa', 'persediaan.pengaturan.ubah')->count())->toBe(0);
    });

    it('H-4 MetodeHppTerkunci: setelah ada riwayat stok metode HPP tidak bisa diganti, stok minus tetap bisa diubah', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BuatRiwayatStokPengaturan($t);
        $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->get('/kelola/persediaan/pengaturan')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('MetodeHppTerkunci', true)
                ->where('AlasanTerkunci', 'Metode HPP tidak bisa diubah karena sudah ada riwayat stok.'));

        $masuk()->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'Fifo', 'StokBolehMinus' => true])
            ->assertSessionHasErrors(['MetodeHpp' => 'Metode HPP tidak bisa diubah karena sudah ada riwayat stok. Metode yang berlaku: Rata-rata tertimbang.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->stokBolehMinus)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'persediaan.pengaturan.ubah')->count())->toBe(0);

        $masuk()->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'RataRata', 'StokBolehMinus' => true])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pengaturan = app(PengaturanPersediaanTenant::class)->Ambil();
        expect($pengaturan->metodeHpp)->toBe(MetodeHpp::RataRata)
            ->and($pengaturan->stokBolehMinus)->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'persediaan.pengaturan.ubah')->count())->toBe(1);
    });

    it('kunci X baris Tenant diambil lebih dulu (L1) sebelum pemeriksaan riwayat stok', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $kueri = [];
        DB::listen(function (QueryExecuted $q) use (&$kueri): void {
            $kueri[] = strtolower($q->sql);
        });

        app(UbahPengaturanPersediaan::class)->Jalankan(MetodeHpp::Fifo, true);

        $indeksKunci = collect($kueri)->search(fn (string $sql): bool => str_contains($sql, '`tenant`') && str_ends_with($sql, 'for update'));
        $indeksMutasi = collect($kueri)->search(fn (string $sql): bool => str_contains($sql, '`mutasistok`'));

        expect($indeksKunci)->toBeInt()
            ->and($indeksMutasi)->toBeInt()
            ->and($indeksKunci)->toBeLessThan($indeksMutasi);
        unset($t);
    });

    it('isolasi tenant: riwayat stok tenant lain tidak mengunci metode HPP tenant ini', function (): void {
        $a = BantuanPersediaan::SiapkanTenant();
        BuatRiwayatStokPengaturan($a);
        $b = BantuanPersediaan::SiapkanTenant('Toko Kelontong Maju Mundur');

        app(UbahPengaturanPersediaan::class)->Jalankan(MetodeHpp::Fifo, false);

        expect(app(PengaturanPersediaanTenant::class)->Ambil()->metodeHpp)->toBe(MetodeHpp::Fifo);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(fn () => app(UbahPengaturanPersediaan::class)->Jalankan(MetodeHpp::Fifo, false))
            ->toThrow(PelanggaranAturanBisnis::class, 'Metode HPP tidak bisa diubah');
        unset($b);
    });

    it('validasi: metode HPP tak dikenal dan stok minus bukan boolean ditolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)
            ->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'Lifo', 'StokBolehMinus' => 'mungkin'])
            ->assertSessionHasErrors(['MetodeHpp' => 'Metode HPP tidak dikenal.', 'StokBolehMinus' => 'Pilih apakah stok boleh minus.']);
    });

    it('izin akuntansi.kelola: Akuntan boleh; Staf Gudang dan Kasir 403', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan)->get('/kelola/persediaan/pengaturan')->assertOk();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'Fifo', 'StokBolehMinus' => false])->assertSessionHasNoErrors();

        foreach ([PeranTenantBawaan::StafGudang, PeranTenantBawaan::Kasir] as $peran) {
            BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, $peran)->get('/kelola/persediaan/pengaturan')->assertForbidden();
            BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, $peran)
                ->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => 'RataRata', 'StokBolehMinus' => true])->assertForbidden();
        }

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->metodeHpp)->toBe(MetodeHpp::Fifo)
            ->and(app(PengaturanPersediaanTenant::class)->Ambil()->stokBolehMinus)->toBeFalse();
    });
});
