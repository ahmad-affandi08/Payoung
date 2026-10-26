<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * FnB reservasi meja + uang muka (§9.1, D-145): pemesanan dengan cek kapasitas & bentrok per meja, status, DP sebagai
 * kewajiban `UangMukaPelanggan` (dikembalikan/hangus), izin, dan isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Selasa 13 Oktober 2026 pukul 09.30 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
});

/** @return array<string, mixed> */
function SiapkanKedai(TestCase $tes): array
{
    $k = BantuanPesananTerbuka::SiapkanRestoran($tes);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);

    return $k;
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianReservasiMeja(array $k, array $ubah = []): array
{
    return array_replace([
        'Outlet' => $k['Outlet']->Uuid,
        'UuidMeja' => $k['Meja']->Uuid,
        'NamaPemesan' => 'Bapak Hendra Wijaya',
        'NoHp' => '0812-3456-7890',
        'JumlahTamu' => 4,
        'Tanggal' => '2026-10-13',
        'Jam' => '19:00',
        'DurasiMenit' => 120,
        'Catatan' => 'Ulang tahun, minta kursi anak',
    ], $ubah);
}

/** @param array<string, mixed> $k */
function AkunKasUji(): string
{
    return app(DaftarAkunPilihan::class)->AmbilKasBank()[0]['Uuid'];
}

it('buat reservasi: meja terkunci, nomor RM, riwayat & audit. Kapasitas kurang dan bentrok ditolak', function (): void {
    $k = SiapkanKedai($this);

    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Reservasi RM/2026/10/0001 dicatat.');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();
    expect($r->Status)->toBe(StatusReservasiMeja::Dikonfirmasi)
        ->and($r->IdMeja)->toBe($k['Meja']->Id)
        ->and($r->NoHp)->toBe('6281234567890')
        ->and($r->MulaiPada->toIso8601ZuluString())->toBe('2026-10-13T12:00:00Z')
        ->and($r->JumlahDp)->toBe('0.00')
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'ReservasiMeja')->where('IdDokumen', $r->Id)->pluck('StatusKe')->all())->toBe(['Dikonfirmasi'])
        ->and(LogAudit::query()->where('Peristiwa', 'reservasi-meja.buat')->count())->toBe(1);

    // Meja 9 berkapasitas 2.
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => $k['Meja9']->Uuid, 'JumlahTamu' => 3]))->assertSessionHasErrors('UuidMeja');
    // Beririsan dengan 19.00–21.00: 20.30 bentrok, 21.00 pas setelah selesai boleh.
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['Jam' => '20:30', 'NamaPemesan' => 'Ibu Sari']))->assertSessionHasErrors('UuidMeja');
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['Jam' => '17:00', 'DurasiMenit' => 120, 'NamaPemesan' => 'Ibu Sari']))->assertSessionHasNoErrors();
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['Jam' => '21:00', 'NamaPemesan' => 'Pak Joko']))->assertSessionHasNoErrors();
    // Meja lain pada jam yang sama tidak bentrok.
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => $k['Meja9']->Uuid, 'JumlahTamu' => 2, 'NamaPemesan' => 'Mbak Rina']))->assertSessionHasNoErrors();
    // Tanpa meja (ditentukan nanti) tidak mengunci apa pun.
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => '', 'NamaPemesan' => 'Mbak Dewi']))->assertSessionHasNoErrors();

    // Ubah diri sendiri tidak bentrok dengan dirinya; waktu lampau, HP, dan tamu tidak valid ditolak.
    $this->put("/kelola/reservasi-meja/{$r->Uuid}", IsianReservasiMeja($k, ['Jam' => '19:30', 'DurasiMenit' => 90]))->assertSessionHasNoErrors();
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['Jam' => '08:00', 'NamaPemesan' => 'Terlambat']))->assertSessionHasErrors('Jam');
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['NoHp' => '12345']))->assertSessionHasErrors('NoHp');
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['JumlahTamu' => 0]))->assertSessionHasErrors('JumlahTamu');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(ReservasiMeja::query()->count())->toBe(5)
        ->and(LogAudit::query()->where('Peristiwa', 'reservasi-meja.ubah')->count())->toBe(1);

    $this->get('/kelola/reservasi-meja')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Reservasi/DaftarMeja')
        ->where('HariIni', '2026-10-13')
        ->has('PilihanMeja', 2)
        ->has('AkunKasBank'));
    $json = $this->getJson('/kelola/reservasi-meja?cari=sari')->assertOk();
    expect($json->json('Meta.Total'))->toBe(1)->and($json->json('Data.0.NamaPemesan'))->toBe('Ibu Sari');
});

it('uang muka dibukukan sebagai kewajiban. Satu DP per reservasi. Dikembalikan atau hangus menutup saldo', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => $k['Meja9']->Uuid, 'JumlahTamu' => 2, 'NamaPemesan' => 'Mbak Rina']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    [$satu, $dua] = ReservasiMeja::query()->orderBy('Id')->get()->all();
    $idTenant = $k['Tenant']->Id;

    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka", ['JumlahDp' => '0', 'UuidAkun' => AkunKasUji()])->assertSessionHasErrors('JumlahDp');
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka", ['JumlahDp' => '100000', 'UuidAkun' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertSessionHasErrors('UuidAkun');
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka", ['JumlahDp' => '100000', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka", ['JumlahDp' => '50000', 'UuidAkun' => AkunKasUji()])->assertSessionHasErrors('JumlahDp');
    $this->post("/kelola/reservasi-meja/{$dua->Uuid}/uang-muka", ['JumlahDp' => '75000.50', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($idTenant);
    expect($satu->refresh()->JumlahDp)->toBe('100000.00')
        ->and($satu->IdJurnalDp)->not->toBeNull()
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('-175000.50')
        ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);

    // Batal dengan DP wajib menyebut nasibnya; dikembalikan = Dr Uang Muka, Cr kas.
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/status", ['Status' => 'Dibatalkan', 'Alasan' => 'Tamu sakit'])->assertSessionHasErrors('CaraDp');
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/status", ['Status' => 'Dibatalkan', 'Alasan' => 'Tamu sakit', 'CaraDp' => 'Dikembalikan', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();
    // Tidak datang: DP hangus jadi pendapatan lain; hanya setelah jam mulai lewat.
    $this->post("/kelola/reservasi-meja/{$dua->Uuid}/status", ['Status' => 'TidakDatang', 'CaraDp' => 'Hangus'])->assertSessionHasErrors('Status');
    $this->travelTo(CarbonImmutable::parse('2026-10-13 21:30', 'Asia/Jakarta'));
    $this->post("/kelola/reservasi-meja/{$dua->Uuid}/status", ['Status' => 'TidakDatang'])->assertSessionHasErrors('CaraDp');
    $this->post("/kelola/reservasi-meja/{$dua->Uuid}/status", ['Status' => 'TidakDatang', 'CaraDp' => 'Hangus'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($idTenant);
    $satu->refresh();
    $dua->refresh();
    expect($satu->Status)->toBe(StatusReservasiMeja::Dibatalkan)
        ->and($satu->DpDikembalikan)->toBe('100000.00')
        ->and($satu->AmbilSisaUangMuka()->KeString())->toBe('0.00')
        ->and($dua->Status)->toBe(StatusReservasiMeja::TidakDatang)
        ->and($dua->DpHangus)->toBe('75000.50')
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('0.00')
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::PendapatanLain))->toBe('-75000.50')
        ->and(LogAudit::query()->where('Peristiwa', 'reservasi-meja.dp-selesai')->count())->toBe(2)
        ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);

    // Sudah berakhir: tidak bisa diubah lagi, DP baru ditolak, penyelesaian ulang ditolak.
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka", ['JumlahDp' => '10000', 'UuidAkun' => AkunKasUji()])->assertSessionHasErrors('Status');
    $this->post("/kelola/reservasi-meja/{$satu->Uuid}/uang-muka/selesai", ['Cara' => 'Hangus', 'Alasan' => 'Coba lagi dua kali'])->assertSessionHasErrors('JumlahDp');
    $this->put("/kelola/reservasi-meja/{$satu->Uuid}", IsianReservasiMeja($k, ['Jam' => '20:00']))->assertSessionHasErrors('Status');
});

it('status: Sudah datang butuh meja; Batal butuh alasan. Transisi tak sah ditolak', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => '']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();

    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'Hadir'])->assertSessionHasErrors('UuidMeja');
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'Dibatalkan'])->assertSessionHasErrors('Alasan');
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'Selesai'])->assertSessionHasErrors('Status');
    $this->put("/kelola/reservasi-meja/{$r->Uuid}", IsianReservasiMeja($k))->assertSessionHasNoErrors();
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'Hadir'])->assertSessionHasNoErrors();
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'TidakDatang'])->assertSessionHasErrors('Status');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($r->refresh()->Status)->toBe(StatusReservasiMeja::Hadir)
        ->and($r->HadirPada)->not->toBeNull()
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'ReservasiMeja')->where('IdDokumen', $r->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Dikonfirmasi', 'Hadir']);
});

it('izin: kasir 403, supervisor boleh. Reservasi tenant lain 404', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();

    $akuntan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan);
    BantuanOrganisasi::Masuk($this, $akuntan, $k['Tenant']->Id)->get('/kelola/reservasi-meja')->assertForbidden();
    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id)->get('/kelola/reservasi-meja')->assertOk();

    $lain = SiapkanKedai($this);
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/status", ['Status' => 'Hadir'])->assertNotFound();
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/uang-muka", ['JumlahDp' => '10000', 'UuidAkun' => AkunKasUji()])->assertNotFound();
    expect($lain['Tenant']->Id)->not->toBe($k['Tenant']->Id);
});

/**
 * Penjualan 2 kopi (Rp 50.000) yang memakai uang muka [dp] lewat metode Uang Muka, sisanya tunai.
 *
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemBayarReservasiMeja(array $k, ReservasiMeja|string $reservasi, string $dp = '30000.00'): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '2', 'Harga' => '25000.00']],
        'Pembayaran' => [
            ['Metode' => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::UangMuka->value)->sole(), 'Jumlah' => $dp],
            ['Metode' => $k['Tunai'], 'Jumlah' => null],
        ],
    ], ['UuidReservasiMeja' => $reservasi instanceof ReservasiMeja ? $reservasi->Uuid : $reservasi]);
}

it('kasir: antrian, tamu datang, bayar memotong uang muka & menyelesaikan reservasi. Void mengembalikan DP', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();
    $idTenant = $k['Tenant']->Id;

    // Tanpa DP tidak ada metode Uang Muka yang dibuat.
    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi-meja')->assertOk()->assertJsonPath('MetodeUangMuka', null);
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/uang-muka", ['JumlahDp' => '30000', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();

    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi-meja')->assertOk()
        ->assertJsonCount(1, 'Reservasi')
        ->assertJsonPath('Reservasi.0.Nomor', 'RM/2026/10/0001')
        ->assertJsonPath('Reservasi.0.UuidMeja', $k['Meja']->Uuid)
        ->assertJsonPath('Reservasi.0.NamaMeja', '7')
        ->assertJsonPath('Reservasi.0.NoHp', '0812-3456-7890')
        ->assertJsonPath('Reservasi.0.UangMuka.Sisa', '30000.00')
        ->assertJsonPath('MetodeUangMuka.Nama', 'Uang muka (DP)');
    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi-meja?tanggal=2026-10-14')->assertOk()->assertJsonCount(0, 'Reservasi');

    $url = "/api/pos/v1/reservasi-meja/{$r->Uuid}/hadir";
    $this->withToken($k['Token'])->postJson($url, ['UuidPengguna' => $k['Kasir']->Uuid])->assertOk()->assertJsonPath('Reservasi.Status', 'Hadir');
    $this->withToken($k['Token'])->postJson($url, ['UuidPengguna' => $k['Kasir']->Uuid])->assertOk()->assertJsonPath('Reservasi.Status', 'Hadir');

    $item = ItemBayarReservasiMeja($k, $r);
    expect(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('-30000.00')
        ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $r->refresh();
    expect($r->Status)->toBe(StatusReservasiMeja::Selesai)
        ->and($r->DpTerpakai)->toBe('30000.00')
        ->and($r->IdPenjualan)->toBe($penjualan->Id)
        ->and($r->AmbilSisaUangMuka()->KeString())->toBe('0.00')
        ->and($penjualan->PerluTinjauan)->toBeFalse()
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('0.00')
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::Penjualan))->toBe('-50000.00')
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'ReservasiMeja')->where('IdDokumen', $r->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Dikonfirmasi', 'Hadir', 'Selesai'])
        ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);

    // Kirim ulang tidak mengubah apa pun (idempoten).
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

    // Void: DP kembali jadi sisa reservasi (kewajiban pulih), reservasi dibuka lagi.
    $void = BantuanPenjualan::ItemVoid($k, $penjualan);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    $r->refresh();
    expect($r->Status)->toBe(StatusReservasiMeja::Hadir)
        ->and($r->DpTerpakai)->toBe('0.00')
        ->and($r->IdPenjualan)->toBeNull()
        ->and($r->AmbilSisaUangMuka()->KeString())->toBe('30000.00')
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('-30000.00')
        ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);
});

it('kasir: uang muka melebihi sisa, reservasi tak dikenal/berakhir = penjualan tetap diterima + tinjauan, tanpa rujukan ditolak', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($idTenant = $k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/uang-muka", ['JumlahDp' => '30000', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();
    // Metode Uang Muka dibuat saat antrian dibuka.
    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi-meja')->assertOk();
    BantuanOrganisasi::AturKonteks($idTenant);

    $lebih = ItemBayarReservasiMeja($k, $r, '40000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lebih]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    expect(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->AlasanTinjauan)->toContain('UangMukaBermasalah: uang muka dipakai Rp 40.000 melebihi sisa');

    // Sudah ditagih penjualan di atas.
    $lagi = ItemBayarReservasiMeja($k, $r, '10000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lagi]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    expect(Penjualan::query()->where('Uuid', $lagi['Uuid'])->sole()->AlasanTinjauan)->toContain('sudah ditagihkan lewat penjualan lain');

    $asing = ItemBayarReservasiMeja($k, (string) Str::ulid(), '10000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$asing]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    expect(Penjualan::query()->where('Uuid', $asing['Uuid'])->sole()->AlasanTinjauan)->toContain('reservasi meja tidak ditemukan di outlet ini');

    $tanpa = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']],
        'Pembayaran' => [
            ['Metode' => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::UangMuka->value)->sole(), 'Jumlah' => '10000.00'],
            ['Metode' => $k['Tunai'], 'Jumlah' => null],
        ],
    ]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]))->toBe([['Ditolak', 'UangMukaTanpaPesanan']]);
});

it('kasir: tamu datang butuh meja. Kasir menempatkan tamu di meja yang cukup dan bebas, bahkan setelah jam lewat', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k, ['UuidMeja' => '', 'JumlahTamu' => 3]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();
    $url = "/api/pos/v1/reservasi-meja/{$r->Uuid}/hadir";

    $this->withToken($k['Token'])->postJson($url, ['UuidPengguna' => $k['Kasir']->Uuid])->assertStatus(422)->assertJsonPath('Galat.Kode', 'MejaBelumDipilih');
    // Meja 9 hanya 2 kursi.
    $this->withToken($k['Token'])->postJson($url, ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidMeja' => $k['Meja9']->Uuid])->assertStatus(422)->assertJsonPath('Galat.Kode', 'KapasitasKurang');

    $this->travelTo(CarbonImmutable::parse('2026-10-13 19:40', 'Asia/Jakarta'));
    $this->withToken($k['Token'])->postJson($url, ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidMeja' => $k['Meja']->Uuid])->assertOk()
        ->assertJsonPath('Reservasi.Status', 'Hadir')
        ->assertJsonPath('Reservasi.NamaMeja', '7');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($r->refresh()->IdMeja)->toBe($k['Meja']->Id);
});

it('kasir: pengguna tanpa izin 403. Reservasi outlet lain 404', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();

    $this->withToken($k['Token'])->postJson("/api/pos/v1/reservasi-meja/{$r->Uuid}/hadir", ['UuidPengguna' => (string) Str::ulid()])->assertForbidden();
    $this->withToken($k['Token'])->postJson('/api/pos/v1/reservasi-meja/'.Str::ulid().'/hadir', ['UuidPengguna' => $k['Kasir']->Uuid])->assertNotFound();
    $lain = BantuanPenjualan::Siapkan($this, 'Kedai Lain Boyolali');
    $this->withToken($lain['Token'])->postJson("/api/pos/v1/reservasi-meja/{$r->Uuid}/hadir", ['UuidPengguna' => $lain['Kasir']->Uuid])->assertNotFound();
});

it('DP lebih besar dari tagihan: sisanya jadi butir Kotak Tindakan sampai dikembalikan atau dihanguskan', function (): void {
    $k = SiapkanKedai($this);
    $this->post('/kelola/reservasi-meja', IsianReservasiMeja($k))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($idTenant = $k['Tenant']->Id);
    $r = ReservasiMeja::query()->sole();
    $this->post("/kelola/reservasi-meja/{$r->Uuid}/uang-muka", ['JumlahDp' => '100000', 'UuidAkun' => AkunKasUji()])->assertSessionHasNoErrors();
    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi-meja')->assertOk();
    BantuanOrganisasi::AturKonteks($idTenant);

    $item = ItemBayarReservasiMeja($k, $r, '50000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($idTenant);
    $r->refresh();
    expect($r->Status)->toBe(StatusReservasiMeja::Selesai)
        ->and($r->AmbilSisaUangMuka()->KeString())->toBe('50000.00')
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('-50000.00');

    $JumlahButir = function () use ($k): int {
        $jumlah = 0;
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get('/kelola/tindakan')->assertInertia(function (AssertableInertia $h) use (&$jumlah) {
            $jumlah = (int) (collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'reservasi-meja.uang-muka-menggantung')['Jumlah'] ?? 0);

            return $h;
        });

        return $jumlah;
    };
    expect($JumlahButir())->toBe(1);

    $this->post("/kelola/reservasi-meja/{$r->Uuid}/uang-muka/selesai", ['Cara' => 'Dikembalikan', 'UuidAkun' => AkunKasUji(), 'Alasan' => 'Tamu minta kembali'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($idTenant);
    expect($JumlahButir())->toBe(0)
        ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::UangMukaPelanggan))->toBe('0.00')
        ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);
});
