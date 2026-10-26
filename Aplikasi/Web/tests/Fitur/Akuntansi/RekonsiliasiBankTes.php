<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\StatusMutasiBank;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\MutasiBank;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * FIN-09 (v3.39) rekonsiliasi bank: impor rekening koran (judul tidak di baris pertama, Debit/Kredit, saldo, baris
 * saldo awal & total dilewati), duplikat tidak terimpor ulang, cocok otomatis hanya yang tidak ambigu, cocok manual
 * ke kandidat, abaikan dengan alasan, batal keputusan, ringkasan saldo koran vs buku, tidak menulis jurnal, izin.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

function RekeningKoranCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('mutasi-bca-oktober.csv', implode("\n", [
        'Informasi Rekening - Mutasi Rekening',
        'No. rekening : 0123456789',
        'Tanggal Transaksi,Keterangan,Debit,Kredit,Saldo',
        'Saldo Awal,,,,"10,000,000.00"',
        '01/10/2026,SETORAN TUNAI,,"2,500,000.00","12,500,000.00"',
        '03/10/2026,TRSF E-BANKING DB PEMBAYARAN PEMASOK,"1,000,000.00",,"11,500,000.00"',
        '05/10/2026,TRSF MASUK QRIS,,"500,000.00","12,000,000.00"',
        '06/10/2026,TRSF MASUK QRIS,,"500,000.00","12,500,000.00"',
        '10/10/2026,BIAYA ADM,"15,000.00",,"12,485,000.00"',
        'Total,,"1,015,000.00","3,500,000.00",',
    ]));
}

it('impor → cocok otomatis (yang tidak ambigu) → cocok manual, abaikan, batal; tanpa jurnal baru', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $bank = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    $kas = Akun::query()->where('KasBank', true)->where('Id', '!=', $bank->Id)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

    // Buku: setoran 2,5 jt (01/10), bayar pemasok 1 jt (02/10), dua QRIS 500 rb (05 & 06/10) — ambigu.
    foreach ([
        ['Masuk', '2026-10-01', '2500000'],
        ['Keluar', '2026-10-02', '1000000'],
        ['Masuk', '2026-10-05', '500000'],
        ['Masuk', '2026-10-06', '500000'],
    ] as [$jenis, $tanggal, $jumlah]) {
        $this->post('/kelola/akuntansi/kas-bank', [
            'Jenis' => 'Transfer', 'Tanggal' => $tanggal, 'Jumlah' => $jumlah, 'Keterangan' => "Uji {$jenis} {$tanggal}",
            'UuidAkunSumber' => $jenis === 'Masuk' ? $kas->Uuid : $bank->Uuid, 'UuidAkunTujuan' => $jenis === 'Masuk' ? $bank->Uuid : $kas->Uuid,
        ])->assertSessionHasNoErrors();
    }

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $jurnalSebelum = JurnalDetail::query()->count();
    $this->post("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}/impor", ['Berkas' => RekeningKoranCsv()])->assertSessionHas('Kilat', '5 mutasi baru diimpor, 0 sudah pernah diimpor. 2 mutasi dicocokkan otomatis.');
    $this->post("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}/impor", ['Berkas' => RekeningKoranCsv()])->assertSessionHas('Kilat', '0 mutasi baru diimpor, 5 sudah pernah diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(MutasiBank::query()->count())->toBe(5)
        ->and((string) MutasiBank::query()->where('Keterangan', 'SETORAN TUNAI')->sole()->Masuk)->toBe('2500000.00')
        ->and((string) MutasiBank::query()->where('Keterangan', 'BIAYA ADM')->sole()->Keluar)->toBe('15000.00');

    // Impor sudah mencocokkan dua mutasi yang jelas pasangannya; menjalankan ulang tidak menemukan yang baru.
    $this->post("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}/cocokkan-otomatis")->assertSessionHas('Kilat', 'Tidak ada mutasi yang bisa dicocokkan otomatis. Sisanya pilih manual.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(MutasiBank::query()->where('Status', StatusMutasiBank::Cocok->value)->pluck('Keterangan')->sort()->values()->all())
        ->toBe(['SETORAN TUNAI', 'TRSF E-BANKING DB PEMBAYARAN PEMASOK']);

    // QRIS 05/10 punya dua kandidat (05 & 06/10): dicocokkan manual ke jurnal tanggal 05.
    $qris = MutasiBank::query()->where('Tanggal', '2026-10-05')->sole();
    $this->getJson("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}?saring[Status]=BelumCocok")->assertOk()
        ->assertJsonPath('Data.0.Keterangan', 'BIAYA ADM')
        ->assertJsonCount(0, 'Data.0.Kandidat');
    $kandidat = $this->getJson("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}?saring[Tanggal]=2026-10-05..2026-10-05")->json('Data.0.Kandidat');
    expect($kandidat)->toHaveCount(2)->and($kandidat[0]['Tanggal'])->toBe('2026-10-05');
    $this->post("/kelola/akuntansi/rekonsiliasi/mutasi/{$qris->Uuid}", ['Keputusan' => 'Cocok', 'Jurnal' => $kandidat[0]['Uuid']])->assertSessionHasNoErrors();
    $this->post("/kelola/akuntansi/rekonsiliasi/mutasi/{$qris->Uuid}", ['Keputusan' => 'Cocok', 'Jurnal' => $kandidat[1]['Uuid']])->assertSessionHasErrors();

    $admin = MutasiBank::query()->where('Keterangan', 'BIAYA ADM')->sole();
    $this->post("/kelola/akuntansi/rekonsiliasi/mutasi/{$admin->Uuid}", ['Keputusan' => 'Abaikan', 'Alasan' => 'x'])->assertSessionHasErrors('Alasan');
    $this->post("/kelola/akuntansi/rekonsiliasi/mutasi/{$admin->Uuid}", ['Keputusan' => 'Abaikan', 'Alasan' => 'Dicatat gabungan akhir bulan'])->assertSessionHasNoErrors();
    $this->post("/kelola/akuntansi/rekonsiliasi/mutasi/{$admin->Uuid}", ['Keputusan' => 'Batal'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($admin->refresh()->Status)->toBe(StatusMutasiBank::BelumCocok)
        ->and(JurnalDetail::query()->count())->toBe($jurnalSebelum)
        ->and(LogAudit::query()->where('Peristiwa', 'mutasi-bank.impor')->count())->toBe(2);

    $this->get("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Akuntansi/Rekonsiliasi')
        ->where('Ringkasan.Cocok', 3)
        ->where('Ringkasan.BelumCocok', 2)
        ->where('Ringkasan.TanggalTerakhir', '2026-10-10')
        ->where('Ringkasan.SaldoRekeningKoran', '12485000.00')
        ->has('BukuBelumCocok', 1));
});

it('berkas tanpa kolom tanggal/nominal ditolak; akun bukan kas/bank 404; kasir tanpa akses; supervisor tanpa kelola', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $bank = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    $bukanBank = Akun::query()->where('KasBank', false)->orderBy('Kode')->firstOrFail();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

    $this->post("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}/impor", ['Berkas' => UploadedFile::fake()->createWithContent('a.csv', "Nama,Alamat\nA,B")])->assertSessionHasErrors('Berkas');
    $this->get("/kelola/akuntansi/rekonsiliasi/{$bukanBank->Uuid}")->assertNotFound();
    $this->get('/kelola/akuntansi/rekonsiliasi')->assertRedirect("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}");

    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->get("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}")->assertForbidden();

    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $this->post("/kelola/akuntansi/rekonsiliasi/{$bank->Uuid}/cocokkan-otomatis")->assertForbidden();
});
