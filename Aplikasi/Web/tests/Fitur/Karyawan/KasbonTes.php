<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Kasbon;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

/** Saldo akun (debit − kredit) dari semua jurnal. */
function SaldoAkunKasbon(int $idAkun): string
{
    return (string) JurnalDetail::query()->where('IdAkun', $idAkun)->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS s')->value('s');
}

describe('F-18 bagian 3 kasbon karyawan (J-18.1)', function (): void {
    it('catat → jurnal Dr Piutang Karyawan / Cr kas; pelunasan sebagian lalu lunas; saldo piutang kembali 0; audit', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $rina = Karyawan::query()->create(['Nama' => 'Rina Wulandari', 'IdOutlet' => $t['Outlet']->Id]);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $piutang = BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKaryawan);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $rina->Uuid, 'Tanggal' => '2026-10-21', 'Jumlah' => '500000', 'AkunKasBank' => $kas->Uuid])
            ->assertSessionHasErrors(['Tanggal' => 'Tanggal tidak boleh setelah hari ini.']);
        $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $rina->Uuid, 'Tanggal' => '2026-10-20', 'Jumlah' => '0', 'AkunKasBank' => $kas->Uuid])
            ->assertSessionHasErrors('Jumlah');
        $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $rina->Uuid, 'Tanggal' => '2026-10-20', 'Jumlah' => '500000', 'AkunKasBank' => $kas->Uuid, 'Keterangan' => 'Biaya sekolah anak'])
            ->assertRedirect('/kelola/karyawan/kasbon');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kasbon = Kasbon::query()->sole();
        $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::Kasbon->value)->sole();
        expect($kasbon->Sisa)->toBe('500000.00')
            ->and($kasbon->IdJurnal)->toBe($jurnal->Id)
            ->and($jurnal->TotalDebit)->toBe('500000.00')
            ->and(SaldoAkunKasbon($piutang))->toBe('500000.00')
            ->and(SaldoAkunKasbon($kas->Id))->toBe('-500000.00');

        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/pelunasan", ['Tanggal' => '2026-10-20', 'Jumlah' => '600000', 'AkunKasBank' => $kas->Uuid])
            ->assertSessionHasErrors(['Jumlah' => 'Jumlah melebihi sisa kasbon Rp 500.000.']);
        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/pelunasan", ['Tanggal' => '2026-10-20', 'Jumlah' => '200000', 'AkunKasBank' => $kas->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($kasbon->refresh()->Sisa)->toBe('300000.00')->and($kasbon->Status->value)->toBe('Aktif');
        // Kasbon yang sudah pernah dilunasi tidak bisa dibatalkan.
        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/batal", ['Alasan' => 'Salah catat'])->assertSessionHasErrors('Umum');

        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/pelunasan", ['Tanggal' => '2026-10-20', 'Jumlah' => '300000', 'AkunKasBank' => $kas->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($kasbon->refresh()->Status->value)->toBe('Lunas')
            ->and($kasbon->Sisa)->toBe('0.00')
            ->and(SaldoAkunKasbon($piutang))->toBe('0.00')
            ->and(SaldoAkunKasbon($kas->Id))->toBe('0.00')
            ->and((string) JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00')
            ->and(LogAudit::query()->whereIn('Peristiwa', ['kasbon.catat', 'kasbon.lunasi'])->count())->toBe(3);

        $this->getJson('/kelola/karyawan/kasbon?saring[Status]=Lunas')->assertOk()
            ->assertJsonPath('Data.0.Karyawan', 'Rina Wulandari')
            ->assertJsonPath('Data.0.LabelStatus', 'Lunas');
        $this->get('/kelola/karyawan/kasbon')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/Kasbon')
            ->where('TotalSisa', '0.00')
            ->where('Izin.Kelola', true));
    });

    it('batal membalik jurnal. Karyawan nonaktif ditolak; Kasir tidak berizin. Tenant lain tidak melihat', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $rina = Karyawan::query()->create(['Nama' => 'Rina Wulandari']);
        $nonaktif = Karyawan::query()->create(['Nama' => 'Budi Lama', 'Status' => StatusKaryawan::Nonaktif]);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $piutang = BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKaryawan);

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/karyawan/kasbon')->assertForbidden();
        $this->post('/kelola/karyawan/kasbon', [])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $nonaktif->Uuid, 'Tanggal' => '2026-10-20', 'Jumlah' => '100000', 'AkunKasBank' => $kas->Uuid])
            ->assertSessionHasErrors(['Karyawan' => 'Pilih karyawan yang aktif.']);
        $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $rina->Uuid, 'Tanggal' => '2026-10-20', 'Jumlah' => '150000', 'AkunKasBank' => $kas->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $kasbon = Kasbon::query()->sole();

        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/batal", ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/batal", ['Alasan' => 'Tidak jadi dipinjam'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($kasbon->refresh()->Status->value)->toBe('Dibatalkan')
            ->and(SaldoAkunKasbon($piutang))->toBe('0.00')
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::Kasbon->value)->count())->toBe(2);

        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->getJson('/kelola/karyawan/kasbon')->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->post("/kelola/karyawan/kasbon/{$kasbon->Uuid}/pelunasan", ['Tanggal' => '2026-10-20', 'Jumlah' => '1000', 'AkunKasBank' => $kas->Uuid])->assertNotFound();
    });
});
