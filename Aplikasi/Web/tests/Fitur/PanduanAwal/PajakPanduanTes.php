<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota();
    Mail::fake();
});

/**
 * Tenant kafe dengan outlet di Surakarta dan template FNB-CAF diterapkan.
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Outlet: Outlet}
 */
function SiapkanKafeSoloUji(bool $denganKota = true): array
{
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    $data = BantuanPanduanAwal::BuatTenant('Kopi Nusantara Laweyan');

    if ($denganKota) {
        Outlet::query()->whereKey($data['Outlet']->Id)->update(['KodeKota' => '33.72', 'ProfilPajak' => json_encode(['Pkp' => false, 'Nitku' => '0012345678901234000001', 'PungutPbjt' => false])]);
    }

    BantuanPanduanAwal::Terapkan($data['Outlet']->fresh() ?? $data['Outlet'], 'FNB-CAF');

    return [...$data, 'Outlet' => $data['Outlet']->fresh() ?? $data['Outlet']];
}

describe('F-01 langkah 3: usulan & konfirmasi pajak (tarif dari TarifPajak, CLAUDE.md #12)', function (): void {
    it('mengusulkan PBJT, service charge template, dan tarif PBJT kota dari master. Tarif berubah → prop berubah', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = SiapkanKafeSoloUji();
        $tarif = BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/pajak')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Pajak')
                ->where('Pkp', false)
                ->where('Kota', ['Kode' => '33.72', 'Nama' => 'Kota Surakarta'])
                ->where('Nilai', ['PungutPbjt' => true, 'BiayaLayananAktif' => true, 'PersenBiayaLayanan' => '5.00', 'HargaTermasukPajak' => false])
                ->where('SudahDikonfirmasi', false)
                ->where('TarifPbjt.Tarif', '10.000000')
                ->where('TarifPbjt.BiayaLayananMasukDpp', true)
                ->where('TarifPbjt.NomorDasarHukum', 'Perda 33.72')
                ->where('TarifPpn.Tarif', '12.000000')
                ->where('TarifPpn.PengaliDppPembilang', 11)
                ->where('TarifPpn.PengaliDppPenyebut', 12)
                ->where('KelompokPajak.0.Nama', 'Makan & minum')
                ->where('KelompokPajak.0.Pajak.0.KodeJenisPajak', 'PbjtMakananMinuman')
                ->where('KelompokPajak.0.Pajak.0.LabelDasarPengenaan', 'Subtotal + biaya layanan')
                ->where('AlasanUsulan', fn ($alasan) => collect($alasan)->contains('Template Kafe / kedai kopi memakai PBJT makanan & minuman.')));

        // Tarif baru terbit untuk kota yang sama: prop mengikuti master, bukan angka di kode.
        TarifPajak::query()->whereKey($tarif->Id)->update(['BerlakuSampai' => now('Asia/Jakarta')->subDay()->toDateString()]);
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '8.500000');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/pajak')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('TarifPbjt.Tarif', '8.500000'));
    });

    it('kota tanpa tarif PBJT di master: TarifPbjt null dengan alasan peringatan, dan tetap bisa disimpan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = SiapkanKafeSoloUji();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/pajak')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('TarifPbjt', null)
                ->where('TarifPpn', null)
                ->where('AlasanUsulan', fn ($alasan) => collect($alasan)->contains('Tarif PBJT Kota Surakarta belum tersedia. Anda tetap bisa menyimpan; PBJT belum dihitung sampai tarifnya tersedia.')));

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => true, 'BiayaLayananAktif' => false, 'PersenBiayaLayanan' => null, 'HargaTermasukPajak' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/panduan-awal/produk');
    });

    it('PBJT tanpa kota outlet ditolak dengan masalah PungutPbjt', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = SiapkanKafeSoloUji(denganKota: false);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => true, 'BiayaLayananAktif' => false, 'PersenBiayaLayanan' => null, 'HargaTermasukPajak' => false])
            ->assertSessionHasErrors(['PungutPbjt' => 'Isi kota outlet di langkah Profil usaha dulu. Tarif PBJT mengikuti kota.']);
    });

    it('service charge di atas 10 persen, negatif, atau berformat ribuan ditolak', function (string $persen): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = SiapkanKafeSoloUji();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => true, 'BiayaLayananAktif' => true, 'PersenBiayaLayanan' => $persen, 'HargaTermasukPajak' => false])
            ->assertSessionHasErrors('PersenBiayaLayanan');
    })->with(['10.5', '12', '-5', '5,5', '']);

    it('menyimpan dengan menggabungkan ProfilPajak (NITKU tetap), menandai langkah, mencatat audit, lalu menampilkan nilai tersimpan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = SiapkanKafeSoloUji();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => '1', 'BiayaLayananAktif' => '1', 'PersenBiayaLayanan' => '7.5', 'HargaTermasukPajak' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Pengaturan pajak disimpan.');

        $profil = Outlet::query()->findOrFail($outlet->Id)->ProfilPajak;
        expect($profil['Nitku'])->toBe('0012345678901234000001')
            ->and($profil['Pkp'])->toBeFalse()
            ->and($profil['PungutPbjt'])->toBeTrue()
            ->and($profil['BiayaLayanan'])->toEqual(['Aktif' => true, 'Persen' => '7.50'])
            ->and($profil['HargaTermasukPajak'])->toBeTrue()
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.pajak.ubah')->count())->toBe(1);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/pajak')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('SudahDikonfirmasi', true)
                ->where('Nilai', ['PungutPbjt' => true, 'BiayaLayananAktif' => true, 'PersenBiayaLayanan' => '7.50', 'HargaTermasukPajak' => true])
                ->where('Progres.Langkah.2.Status', 'Selesai'));
    });
});
