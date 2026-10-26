<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Enum\StatusPesananSendiri;
use App\Domain\Penjualan\Model\PesananSendiri;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-17 Self-Order QR Meja di POS: daftar pesanan menunggu per outlet perangkat (terlama dulu), terima (dengan
 * UuidPesananTerbuka dari perangkat) & tolak beralasan oleh pelaku ber-izin `penjualan.buat` atau
 * `pesanan.meja.catat`, idempotensi menurut status, 409 SudahDiproses di perangkat kedua, kedaluwarsa 30 menit,
 * audit, dan isolasi outlet & tenant.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/**
 * @param  array<string, mixed>  $k
 * @return array<string, mixed>
 */
function KirimPesananTamu(TestCase $tes, array $k, string $alamat = 'Alamat', ?string $nama = 'Bu Ratna'): array
{
    $kiriman = BantuanPesanSendiri::Kiriman([[$k['Kopi'], 2, [$k['GulaSedikit'], $k['Boba']], 'Es dipisah'], [$k['Nasi'], 1]], nama: $nama, catatan: 'Meja dekat jendela');
    $tes->postJson("{$k[$alamat]}/pesan", $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $kiriman;
}

/**
 * @param  array<string, mixed>  $data
 */
function ProsesPesananSendiri(TestCase $tes, string $token, string $uuid, string $aksi, array $data): TestResponse
{
    return $tes->withToken($token)->postJson("/api/pos/v1/pesan-sendiri/{$uuid}/{$aksi}", $data);
}

describe('F-17 pesan sendiri di POS', function (): void {
    it('daftar: hanya MenungguKonfirmasi outlet perangkat, terlama dulu, lengkap dengan baris & meja', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $pertama = KirimPesananTamu($this, $k);
        $this->travel(1)->minutes();
        $kedua = KirimPesananTamu($this, $k, 'Alamat9', null);
        $ditolak = KirimPesananTamu($this, $k);
        ProsesPesananSendiri($this, $k['Token'], $ditolak['Uuid'], 'tolak', ['UuidPengguna' => $k['Kasir']->Uuid, 'Alasan' => 'Menu habis'])->assertOk();

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertOk()
            ->assertJsonCount(2, 'Pesanan')
            ->assertJsonPath('Pesanan.0.Uuid', $pertama['Uuid'])
            ->assertJsonPath('Pesanan.0.UuidMeja', $k['Meja']->Uuid)
            ->assertJsonPath('Pesanan.0.NamaMeja', '7')
            ->assertJsonPath('Pesanan.0.NamaPemesan', 'Bu Ratna')
            ->assertJsonPath('Pesanan.0.Catatan', 'Meja dekat jendela')
            ->assertJsonPath('Pesanan.0.Subtotal', '95000.00')
            ->assertJsonPath('Pesanan.0.Baris.0.Uuid', $pertama['Baris'][0]['Uuid'])
            ->assertJsonPath('Pesanan.0.Baris.0.HargaSatuan', '25000.00')
            ->assertJsonPath('Pesanan.0.Baris.0.HargaPilihan', '5000.00')
            ->assertJsonPath('Pesanan.0.Baris.0.Pilihan.1.UuidPilihan', $k['Boba']->Uuid)
            ->assertJsonPath('Pesanan.0.Baris.0.Catatan', 'Es dipisah')
            ->assertJsonPath('Pesanan.1.Uuid', $kedua['Uuid'])
            ->assertJsonPath('Pesanan.1.NamaMeja', '9')
            ->assertJsonPath('Pesanan.1.NamaPemesan', null);
        expect($respons->json('Pesanan.0.DibuatPada'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($respons->json('Pesanan.0')))->toBe(['Uuid', 'Nomor', 'UuidMeja', 'NamaMeja', 'NamaPemesan', 'Catatan', 'DibuatPada', 'Subtotal', 'Baris'])
            ->and(array_keys($respons->json('Pesanan.0.Baris.0')))->toEqualCanonicalizing(['Uuid', 'UuidProduk', 'UuidProdukSatuan', 'NamaProduk', 'Jumlah', 'HargaSatuan', 'HargaPilihan', 'Pilihan', 'Catatan']);

        // Perangkat outlet lain tenant yang sama tidak melihatnya.
        $outletLain = Outlet::query()->create(['IdMerek' => $k['Outlet']->IdMerek, 'Kode' => 'CBG2', 'Nama' => 'Cabang Kartasura']);
        $lain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletLain, 'Kasir Kartasura');
        $this->withToken($lain['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertOk()->assertExactJson(['Pesanan' => []]);
        ProsesPesananSendiri($this, $lain['Token'], $pertama['Uuid'], 'terima', ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertNotFound()->assertJsonPath('Galat.Kode', 'PesananTidakDitemukan');
    });

    it('terima oleh pelayan: status Diterima, idempoten dengan UuidPesananTerbuka sama, audit. Tamu melihat Diterima', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $pelayan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Pelayan);
        $kiriman = KirimPesananTamu($this, $k);
        $uuidPesanan = (string) Str::ulid();
        $data = ['UuidPengguna' => $pelayan->Uuid, 'UuidPesananTerbuka' => $uuidPesanan];

        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', $data)->assertOk()
            ->assertExactJson(['Uuid' => $kiriman['Uuid'], 'Status' => 'Diterima', 'UuidPesananTerbuka' => $uuidPesanan]);
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', $data)->assertOk()->assertJsonPath('Status', 'Diterima');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananSendiri::query()->where('Uuid', $kiriman['Uuid'])->sole();
        expect($pesanan->Status)->toBe(StatusPesananSendiri::Diterima)
            ->and($pesanan->UuidPesananTerbuka)->toBe($uuidPesanan)
            ->and($pesanan->IdPemroses)->toBe($pelayan->Id)
            ->and($pesanan->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($pesanan->DiprosesPada)->not->toBeNull();
        $audit = LogAudit::query()->where('Peristiwa', 'pesan-sendiri.terima')->sole();
        expect($audit->IdPengguna)->toBe($pelayan->Id)
            ->and($audit->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($audit->NilaiBaru)->toMatchArray(['Status' => 'Diterima', 'UuidPesananTerbuka' => $uuidPesanan]);

        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertJsonPath('Status', 'Diterima');
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertExactJson(['Pesanan' => []]);
    });

    it('perangkat kedua: terima dengan pesanan terbuka lain atau tolak setelah diterima → 409 SudahDiproses', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kedua = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Belakang');
        $kiriman = KirimPesananTamu($this, $k);

        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])->assertOk();
        ProsesPesananSendiri($this, $kedua['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $k['Supervisor']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'SudahDiproses')->assertJsonPath('Galat.Detail.Status', 'Diterima');
        $pesan = ProsesPesananSendiri($this, $kedua['Token'], $kiriman['Uuid'], 'tolak', ['UuidPengguna' => $k['Supervisor']->Uuid, 'Alasan' => 'Dapur sudah tutup'])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'SudahDiproses')->json('Galat.Pesan');
        expect($pesan)->toContain('diterima');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'like', 'pesan-sendiri.%')->count())->toBe(1);
    });

    it('tolak beralasan (3–200 karakter), idempoten. Tamu melihat alasan. Terima setelah ditolak → 409', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = KirimPesananTamu($this, $k);
        $data = ['UuidPengguna' => $k['Kasir']->Uuid, 'Alasan' => 'Nasi goreng sedang habis, silakan pesan menu lain'];

        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'tolak', [...$data, 'Alasan' => 'ok'])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'tolak', $data)->assertOk()->assertExactJson(['Uuid' => $kiriman['Uuid'], 'Status' => 'Ditolak']);
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'tolak', $data)->assertOk()->assertJsonPath('Status', 'Ditolak');
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'SudahDiproses');

        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertOk()
            ->assertJsonPath('Status', 'Ditolak')
            ->assertJsonPath('AlasanTolak', 'Nasi goreng sedang habis, silakan pesan menu lain');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'pesan-sendiri.tolak')->sole()->IdPengguna)->toBe($k['Kasir']->Id);
    });

    it('izin: staf gudang ditolak TanpaIzin (403). Pengguna bukan anggota ditolak. Status tetap menunggu', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $kiriman = KirimPesananTamu($this, $k);

        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $gudang->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'tolak', ['UuidPengguna' => $gudang->Uuid, 'Alasan' => 'Tidak jelas'])
            ->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => (string) Str::ulid(), 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'KasirTidakDitemukan');
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertJsonCount(1, 'Pesanan');
    });

    it('kedaluwarsa 30 menit: hilang dari daftar, terima/tolak → 409 Kedaluwarsa', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = KirimPesananTamu($this, $k);

        $this->travel(31)->minutes();
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'Kedaluwarsa');
        ProsesPesananSendiri($this, $k['Token'], $kiriman['Uuid'], 'tolak', ['UuidPengguna' => $k['Kasir']->Uuid, 'Alasan' => 'Terlambat'])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'Kedaluwarsa');
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertExactJson(['Pesanan' => []]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananSendiri::query()->sole()->Status)->toBe(StatusPesananSendiri::Kedaluwarsa);
    });

    it('isolasi tenant: perangkat tenant lain tidak melihat dan tidak bisa memproses', function (): void {
        $a = BantuanPesanSendiri::Siapkan($this);
        $kiriman = KirimPesananTamu($this, $a);
        $b = BantuanPesanSendiri::Siapkan($this);

        $this->withToken($b['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertExactJson(['Pesanan' => []]);
        ProsesPesananSendiri($this, $b['Token'], $kiriman['Uuid'], 'terima', ['UuidPengguna' => $b['Kasir']->Uuid, 'UuidPesananTerbuka' => (string) Str::ulid()])
            ->assertNotFound()->assertJsonPath('Galat.Kode', 'PesananTidakDitemukan');
        ProsesPesananSendiri($this, $b['Token'], $kiriman['Uuid'], 'tolak', ['UuidPengguna' => $b['Kasir']->Uuid, 'Alasan' => 'Coba tolak'])->assertNotFound();
        $this->withToken($a['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertJsonCount(1, 'Pesanan');
    });
});
