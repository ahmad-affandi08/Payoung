<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Kasbon;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit race (dokumen tanpa kunci idempotensi klien): kiriman identik dari pengguna yang sama dalam jendela pendek
 * dianggap klik ganda dan tidak mencatat dokumen uang dua kali. Dipakai pada kasbon, transaksi kas/bank, aset tetap,
 * penerimaan barang, dan belanja stok; diuji lewat kasbon.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: array<string, mixed>, 1: array<string, string>} */
function SiapkanKasbonUji(object $tes): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $rina = Karyawan::query()->create(['Nama' => 'Rina Wulandari', 'IdOutlet' => $t['Outlet']->Id]);
    $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($tes, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

    return [$t, ['Karyawan' => $rina->Uuid, 'Tanggal' => '2026-10-20', 'Jumlah' => '500000', 'AkunKasBank' => $kas->Uuid, 'Keterangan' => 'Biaya sekolah anak']];
}

it('kiriman identik kedua dalam jendela pendek ditolak dan tidak mencatat kasbon dua kali', function (): void {
    [$t, $isi] = SiapkanKasbonUji($this);

    $this->post('/kelola/karyawan/kasbon', $isi)->assertRedirect('/kelola/karyawan/kasbon');
    $this->post('/kelola/karyawan/kasbon', $isi)->assertSessionHasErrors('Umum');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Kasbon::query()->count())->toBe(1);
});

it('isian berbeda atau lewat jendela tetap bisa dicatat', function (): void {
    [$t, $isi] = SiapkanKasbonUji($this);

    $this->post('/kelola/karyawan/kasbon', $isi)->assertRedirect('/kelola/karyawan/kasbon');
    $this->post('/kelola/karyawan/kasbon', [...$isi, 'Jumlah' => '250000'])->assertRedirect('/kelola/karyawan/kasbon');

    Carbon::setTestNow(Carbon::now()->addSeconds(9));
    $this->post('/kelola/karyawan/kasbon', $isi)->assertRedirect('/kelola/karyawan/kasbon');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Kasbon::query()->count())->toBe(3);
});

it('kiriman yang gagal tidak menahan perbaikan dan kiriman ulangnya', function (): void {
    [$t, $isi] = SiapkanKasbonUji($this);
    $salah = [...$isi, 'Jumlah' => '0'];

    $this->post('/kelola/karyawan/kasbon', $salah)->assertSessionHasErrors('Jumlah');
    // Kiriman ulang yang sama persis tetap dinilai ulang (gagal validasi lagi), bukan ditolak sebagai klik ganda.
    $this->post('/kelola/karyawan/kasbon', $salah)->assertSessionHasErrors('Jumlah')->assertSessionDoesntHaveErrors('Umum');
    $this->post('/kelola/karyawan/kasbon', $isi)->assertRedirect('/kelola/karyawan/kasbon');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Kasbon::query()->count())->toBe(1);
});
