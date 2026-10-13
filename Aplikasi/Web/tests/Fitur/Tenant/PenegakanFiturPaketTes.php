<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Perantara\PetaFiturRute;
use App\Http\Perantara\WajibFiturPaket;
use Illuminate\Support\Facades\Route;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-111 (BR-P04.3): server selalu penentu hak fitur paket. Sebelumnya fitur berbayar hanya digembok di menu, sehingga
 * tenant Gratis/Starter bisa memakainya lewat URL langsung. `PetaFiturRute` memetakan rute → fitur; `WajibFiturPaket`
 * menegakkannya untuk back-office, API aplikasi Kasir, dan API Owner.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function TokenPemilikPenegakanUji(object $tes, Pengguna $pengguna): string
{
    $pengguna->forceFill(['EmailDiverifikasiPada' => now()])->save();
    $token = $tes->postJson('/api/pemilik/v1/masuk', ['Email' => $pengguna->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP Uji'])->assertOk()->json('Token');

    return is_string($token) ? $token : '';
}

/** @return array{Tenant: object, Pemilik: object} */
function TenantPaketUji(string $kodePaket, string $nama = 'Toko Berkah Boyolali'): array
{
    $t = BantuanOrganisasi::BuatTenant($nama);
    BantuanFitur::PindahPaket($t['Tenant']->Id, $kodePaket);

    return $t;
}

describe('peta rute → fitur terjaga dari drift', function (): void {
    it('setiap pola cocok dengan rute terdaftar dan setiap kunci fitur ada di katalog', function (): void {
        $katalog = array_column(json_decode((string) file_get_contents(database_path('Data/KatalogFitur.json')), true, flags: JSON_THROW_ON_ERROR)['Fitur'], 'Kunci');
        $nama = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->values();

        foreach (PetaFiturRute::ATURAN as $aturan) {
            expect($katalog)->toContain($aturan['Fitur'])
                ->and($nama->contains(fn (string $n): bool => fnmatch($aturan['Pola'], $n)))->toBeTrue("Pola {$aturan['Pola']} tidak cocok dengan rute mana pun.");
        }
    });

    it('fitur yang digembok di menu back-office punya aturan di server (kecuali yang dijaga di Aksi)', function (): void {
        // Fitur ini sudah ditegakkan di Aksi/kontroler masing-masing (cek `PemeriksaFiturTenant::CekAktif`), bukan per rute.
        $dijagaDiAksi = [
            'promo.mesin', 'pelanggan.deposit', 'pelanggan.paket-sesi', 'pelanggan.loyalti', 'kanal.toko-online', 'kanal.self-order',
            'integrasi.whatsapp', 'api.publik', 'persetujuan.jarak-jauh', 'keamanan.2fa-wajib',
        ];
        $diMenu = [];

        foreach (['resources/js/TataLetak/TataLetakAplikasi.tsx', 'resources/js/Pustaka/DaftarPengaturan.ts'] as $berkas) {
            preg_match_all("/fitur: '([a-z0-9.\\-]+)'/", (string) file_get_contents(base_path($berkas)), $cocok);
            $diMenu = [...$diMenu, ...$cocok[1]];
        }

        $diServer = array_unique(array_column(PetaFiturRute::ATURAN, 'Fitur'));
        $tanpaAturan = array_values(array_diff(array_unique($diMenu), $diServer, $dijagaDiAksi));

        expect($tanpaAturan)->toBe([], 'Fitur digembok di menu tetapi tidak ditegakkan server: '.implode(', ', $tanpaAturan));
    });
});

describe('back-office /kelola', function (): void {
    it('Starter: membuat opname/transfer ditolak, melihat daftar yang sudah ada tetap boleh, halaman formulir ditutup', function (): void {
        $t = TenantPaketUji('STARTER');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/persediaan/opname')->assertOk();
        $masuk()->post('/kelola/persediaan/opname', [])->assertSessionHasErrors('Umum');
        $masuk()->post('/kelola/persediaan/transfer', [])->assertSessionHasErrors('Umum');
        $masuk()->get('/kelola/persediaan/transfer/buat')->assertRedirect('/kelola')->assertSessionHasErrors('Umum');
        $masuk()->get('/kelola/daftar-harga/buat')->assertRedirect('/kelola')->assertSessionHasErrors('Umum');
        $masuk()->get('/kelola/pembelian/pesanan/buat')->assertRedirect('/kelola')->assertSessionHasErrors('Umum');
    });

    it('fitur yang halamannya sendiri adalah fitur (akuntansi, laporan lengkap) ditutup seluruhnya; JSON mendapat 403 FiturTidakAktif', function (): void {
        $t = TenantPaketUji('STARTER', 'Warung Sedap Klaten');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/akuntansi/jurnal')->assertRedirect('/kelola')->assertSessionHasErrors('Umum');
        $masuk()->getJson('/kelola/akuntansi/jurnal')->assertForbidden()->assertJsonPath('Galat.Kode', WajibFiturPaket::KODE_GALAT);
        // Laporan lengkap ada di Starter, akuntansi penuh tidak: laba rugi boleh, neraca tidak.
        $masuk()->get('/kelola/akuntansi/laporan/laba-rugi')->assertOk();
        $masuk()->get('/kelola/akuntansi/laporan/neraca')->assertRedirect('/kelola');
        // F8: anggaran vs realisasi termasuk akuntansi penuh.
        $masuk()->get('/kelola/akuntansi/anggaran')->assertRedirect('/kelola');
    });

    it('Gratis: laporan pajak/stok (laporan lengkap) dan data pelanggan baru ditolak, laporan penjualan dasar tetap terbuka', function (): void {
        $t = TenantPaketUji('GRATIS', 'Toko Mungil Salatiga');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/laporan/penjualan')->assertOk();
        $masuk()->get('/kelola/laporan/pajak')->assertRedirect('/kelola');
        $masuk()->get('/kelola/laporan/stok')->assertRedirect('/kelola');
        $masuk()->post('/kelola/persediaan/stok-awal', [])->assertSessionHasErrors('Umum');
        $masuk()->get('/kelola/pelanggan/buat')->assertRedirect('/kelola');
        $masuk()->get('/kelola/pelanggan')->assertOk();
    });

    it('tab Anti-fraud hanya untuk paket yang memuatnya; tab lain tidak terpengaruh', function (): void {
        $t = TenantPaketUji('PRO', 'Kedai Kopi Wonogiri');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/laporan/penjualan?tab=harian')->assertOk();
        $masuk()->get('/kelola/laporan/penjualan?tab=anti-fraud')->assertRedirect('/kelola')->assertSessionHasErrors('Umum');

        BantuanFitur::Beri($t['Tenant']->Id, 'keamanan.anti-fraud');
        $masuk()->get('/kelola/laporan/penjualan?tab=anti-fraud')->assertOk();
    });

    it('Pro memuat opname, pembelian PO, akuntansi, dan komisi tetapi tidak transfer, daftar harga, grosir', function (): void {
        $t = TenantPaketUji('PRO', 'Toko Bangunan Jaya Sragen');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/akuntansi/jurnal')->assertOk();
        $masuk()->get('/kelola/persediaan/opname')->assertOk();
        $masuk()->get('/kelola/karyawan/komisi/laporan')->assertOk();
        $masuk()->get('/kelola/persediaan/transfer/buat')->assertRedirect('/kelola');
        $masuk()->get('/kelola/daftar-harga/buat')->assertRedirect('/kelola');
        $masuk()->get('/kelola/grosir/pesanan/buat')->assertRedirect('/kelola');
    });

    it('Bisnis memuat transfer, daftar harga, dan grosir', function (): void {
        $t = TenantPaketUji('BISNIS', 'Distributor Sembako Karanganyar');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/persediaan/transfer/buat')->assertOk();
        $masuk()->get('/kelola/daftar-harga/buat')->assertOk();
        $masuk()->get('/kelola/grosir/pesanan/buat')->assertOk();
    });

    it('override fitur dari konsol membuka fitur di luar paket; mematikan penegakan membuka semuanya', function (): void {
        $t = TenantPaketUji('STARTER', 'Toko Roti Manis Sukoharjo');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $masuk()->get('/kelola/persediaan/transfer/buat')->assertRedirect('/kelola');
        BantuanFitur::Beri($t['Tenant']->Id, 'stok.transfer');
        $masuk()->get('/kelola/persediaan/transfer/buat')->assertOk();

        $masuk()->get('/kelola/daftar-harga/buat')->assertRedirect('/kelola');
        config(['tenant.PenegakanFiturPaket' => false]);
        $masuk()->get('/kelola/daftar-harga/buat')->assertOk();
    });
});

describe('API aplikasi Kasir dan Owner', function (): void {
    it('API POS: modul Gudang (opname/terima PO/transfer) mengikuti fitur paketnya; KDS ikut pos.kds', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanFitur::PindahPaket($k['Tenant']->Id, 'STARTER');
        $pos = fn (string $url) => $this->withToken($k['Token'])->getJson("/api/pos/v1/{$url}");

        // Starter tidak punya opname, PO, transfer, maupun KDS.
        foreach (['gudang/opname', 'gudang/pesanan-pembelian', 'gudang/transfer', 'dapur/tiket'] as $url) {
            $pos($url)->assertForbidden()->assertJsonPath('Galat.Kode', WajibFiturPaket::KODE_GALAT);
        }

        BantuanFitur::PindahPaket($k['Tenant']->Id, 'PRO');
        $pos('gudang/opname')->assertOk();
        $pos('gudang/pesanan-pembelian')->assertOk();
        $pos('dapur/tiket')->assertOk();
        $pos('gudang/transfer')->assertForbidden();

        BantuanFitur::Beri($k['Tenant']->Id, 'stok.transfer');
        $pos('gudang/transfer')->assertOk();
    });

    it('API Owner: insight mingguan hanya bagi tenant yang punya fitur Forecast & insight', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Toko Insight Owner Boyolali');
        $token = TokenPemilikPenegakanUji($this, $k['Pemilik']);
        $ambil = fn () => $this->withToken($token)->withHeaders(['X-Tenant' => $k['Tenant']->Uuid, 'X-Versi-Aplikasi' => '1.0.0'])->getJson('/api/pemilik/v1/insight');

        $ambil()->assertForbidden()->assertJsonPath('Galat.Kode', WajibFiturPaket::KODE_GALAT);

        BantuanFitur::Beri($k['Tenant']->Id, 'laporan.insight');
        $ambil()->assertOk();
    });
});
