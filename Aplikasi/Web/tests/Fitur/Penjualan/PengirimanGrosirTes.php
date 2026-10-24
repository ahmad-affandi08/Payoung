<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BatalkanSuratJalan;
use App\Domain\Penjualan\Aksi\CatatPengirimanSuratJalan;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanArmada;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Aksi\UbahStatusArmada;
use App\Domain\Penjualan\Data\DataArmada;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataPengirimanGrosir;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use App\Domain\Penjualan\Model\Armada;
use App\Domain\Penjualan\Model\SuratJalan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Pengiriman grosir (F-12 §9.7): armada, kabar lapangan surat jalan (Disiapkan → DalamPerjalanan → Diterima | Gagal),
 * konfirmasi terima. Ini kabar lapangan: angka, stok, dan jurnal surat jalan tidak boleh tersentuh.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Sumber Pangan');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'piutang.grosir');
    $this->gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kemasan 1 kg'], '15000.00', $this->k['Pcs']);
    BantuanStokAwal::BuatDanPosting($this->k['Gudang'], [BantuanStokAwal::Baris($this->gula, '1000', '11000')], $this->k['Pemilik']->Id, '2026-09-01');
    $this->toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550006', 'LimitKredit' => '50000000', 'TerminHari' => 30]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function SuratJalanPengirimanUji($tes): SuratJalan
{
    $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $tes->toko->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisPesananGrosir($tes->gula->Uuid, BantuanHarga::SatuanDasar($tes->gula)->Uuid, Kuantitas::Dari('100'), Uang::Nol())],
    ), $tes->k['Pemilik']->Id);
    $pesanan = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $tes->k['Pemilik']->Id, true, 'Pelanggan lama');

    return app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
        uuidPesanan: $pesanan->Uuid,
        idGudang: $tes->k['Gudang']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisSuratJalan(1, Kuantitas::Dari('100'))],
    ), $tes->k['Pemilik']->Id);
}

function CatatPengirimanUji($tes, SuratJalan $suratJalan, StatusPengirimanGrosir $tujuan, array $isi = []): SuratJalan
{
    return app(CatatPengirimanSuratJalan::class)->Jalankan(new DataPengirimanGrosir(
        uuidSuratJalan: $suratJalan->Uuid,
        tujuan: $tujuan,
        uuidArmada: $isi['uuidArmada'] ?? null,
        namaPengemudi: $isi['namaPengemudi'] ?? null,
        namaPenerima: $isi['namaPenerima'] ?? null,
        catatan: $isi['catatan'] ?? null,
        waktuLokal: $isi['waktuLokal'] ?? null,
    ), $tes->k['Pemilik']->Id);
}

function KodeGalatPengirimanUji(Closure $fn): ?string
{
    try {
        $fn();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

describe('Armada pengiriman', function (): void {
    it('nomor kendaraan dinormalkan dan unik per tenant; ubah, nonaktifkan, aktifkan idempoten dengan audit', function (): void {
        $armada = app(SimpanArmada::class)->Jalankan(new DataArmada('Truk Engkel 1', ' ad  1234  xy ', 'Pak Slamet', null), null, $this->k['Pemilik']->Id);

        expect($armada->NomorKendaraan)->toBe('AD 1234 XY')
            ->and($armada->Aktif)->toBeTrue()
            ->and(KodeGalatPengirimanUji(fn () => app(SimpanArmada::class)->Jalankan(new DataArmada('Truk lain', 'AD 1234 XY'), null, $this->k['Pemilik']->Id)))->toBe('NomorKendaraanDipakai')
            ->and(KodeGalatPengirimanUji(fn () => app(SimpanArmada::class)->Jalankan(new DataArmada('', 'B 1 A'), null, $this->k['Pemilik']->Id)))->toBe('ArmadaTidakLengkap');

        $ubah = app(SimpanArmada::class)->Jalankan(new DataArmada('Truk Engkel Satu', 'AD 1234 XY', 'Pak Slamet'), $armada, $this->k['Pemilik']->Id);
        expect($ubah->Nama)->toBe('Truk Engkel Satu');

        app(UbahStatusArmada::class)->Jalankan($armada, false, $this->k['Pemilik']->Id);
        app(UbahStatusArmada::class)->Jalankan($armada, false, $this->k['Pemilik']->Id);
        expect($armada->refresh()->Aktif)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'armada.nonaktifkan')->count())->toBe(1);

        app(UbahStatusArmada::class)->Jalankan($armada, true, $this->k['Pemilik']->Id);
        expect($armada->refresh()->Aktif)->toBeTrue();
    });
});

describe('CatatPengirimanSuratJalan', function (): void {
    it('alur lengkap: berangkat (armada & pengemudi tersalin), diterima (penerima), tanpa menyentuh angka surat jalan; selesai tak bisa diubah', function (): void {
        $armada = app(SimpanArmada::class)->Jalankan(new DataArmada('Truk Engkel 1', 'AD 1234 XY', 'Pak Slamet'), null, $this->k['Pemilik']->Id);
        $suratJalan = SuratJalanPengirimanUji($this);
        $totalSebelum = [$suratJalan->Total, $suratJalan->TotalHpp, $suratJalan->IdJurnal];
        expect($suratJalan->StatusPengiriman)->toBe(StatusPengirimanGrosir::Disiapkan);

        $berangkat = CatatPengirimanUji($this, $suratJalan, StatusPengirimanGrosir::DalamPerjalanan, ['uuidArmada' => $armada->Uuid]);
        expect($berangkat->StatusPengiriman)->toBe(StatusPengirimanGrosir::DalamPerjalanan)
            ->and($berangkat->IdArmada)->toBe($armada->Id)
            ->and($berangkat->NamaPengirim)->toBe('Pak Slamet')
            ->and($berangkat->NomorKendaraan)->toBe('AD 1234 XY')
            ->and($berangkat->BerangkatPada)->not->toBeNull();

        expect(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $berangkat, StatusPengirimanGrosir::Diterima)))->toBe('PenerimaWajib')
            ->and(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $berangkat, StatusPengirimanGrosir::Diterima, ['namaPenerima' => 'Bu Ani', 'waktuLokal' => '2026-09-28 09:00'])))->toBe('WaktuTerimaTidakValid');

        $terima = CatatPengirimanUji($this, $berangkat, StatusPengirimanGrosir::Diterima, ['namaPenerima' => 'Bu Ani (kasir toko)', 'catatan' => 'Kemasan utuh, dicek bersama sopir']);
        expect($terima->StatusPengiriman)->toBe(StatusPengirimanGrosir::Diterima)
            ->and($terima->NamaPenerima)->toBe('Bu Ani (kasir toko)')
            ->and($terima->DiterimaPada)->not->toBeNull()
            ->and($terima->CatatanPengiriman)->toBe('Kemasan utuh, dicek bersama sopir')
            // Kabar lapangan tidak menyentuh dokumen akuntansinya.
            ->and([$terima->Total, $terima->TotalHpp, $terima->IdJurnal])->toBe($totalSebelum)
            ->and($terima->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $terima, StatusPengirimanGrosir::DalamPerjalanan)))->toBe('StatusPengirimanTidakValid');

        expect(RiwayatStatusDokumen::query()->where('JenisDokumen', CatatPengirimanSuratJalan::JENIS_RIWAYAT)->where('IdDokumen', $suratJalan->Id)->pluck('StatusKe')->all())
            ->toBe(['DalamPerjalanan', 'Diterima'])
            ->and(LogAudit::query()->where('Peristiwa', 'grosir.pengiriman-ubah')->count())->toBe(2);
    });

    it('gagal kirim wajib beralasan lalu bisa dikirim ulang; batal berangkat kembali ke Disiapkan; ambil sendiri langsung Diterima', function (): void {
        $suratJalan = SuratJalanPengirimanUji($this);
        $jalan = CatatPengirimanUji($this, $suratJalan, StatusPengirimanGrosir::DalamPerjalanan, ['namaPengemudi' => 'Pak Joko']);
        expect($jalan->NamaPengirim)->toBe('Pak Joko')
            ->and($jalan->IdArmada)->toBeNull()
            ->and(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $jalan, StatusPengirimanGrosir::Gagal, ['catatan' => 'x'])))->toBe('AlasanGagalWajib');

        $gagal = CatatPengirimanUji($this, $jalan, StatusPengirimanGrosir::Gagal, ['catatan' => 'Toko tutup, pemilik sedang umrah']);
        expect($gagal->StatusPengiriman)->toBe(StatusPengirimanGrosir::Gagal);

        $ulang = CatatPengirimanUji($this, $gagal, StatusPengirimanGrosir::DalamPerjalanan);
        $batal = CatatPengirimanUji($this, $ulang, StatusPengirimanGrosir::Disiapkan);
        expect($batal->StatusPengiriman)->toBe(StatusPengirimanGrosir::Disiapkan)
            ->and($batal->BerangkatPada)->toBeNull();

        $langsung = CatatPengirimanUji($this, $batal, StatusPengirimanGrosir::Diterima, ['namaPenerima' => 'Pak Bambang (ambil sendiri)']);
        expect($langsung->StatusPengiriman)->toBe(StatusPengirimanGrosir::Diterima);
    });

    it('armada nonaktif ditolak; surat jalan dibatalkan tidak punya pengiriman; surat jalan yang sudah diterima tidak bisa dibatalkan lewat tombol (pakai retur)', function (): void {
        $armada = app(SimpanArmada::class)->Jalankan(new DataArmada('Pickup 2', 'AD 99 ZZ'), null, $this->k['Pemilik']->Id);
        app(UbahStatusArmada::class)->Jalankan($armada, false, $this->k['Pemilik']->Id);

        $suratJalan = SuratJalanPengirimanUji($this);
        expect(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $suratJalan, StatusPengirimanGrosir::DalamPerjalanan, ['uuidArmada' => $armada->Uuid])))->toBe('ArmadaTidakDitemukan');

        $batal = app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Pesanan salah gudang', $this->k['Pemilik']->Id);
        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(KodeGalatPengirimanUji(fn () => CatatPengirimanUji($this, $batal, StatusPengirimanGrosir::DalamPerjalanan)))->toBe('SuratJalanDibatalkan');

        expect(Armada::query()->count())->toBe(1);
    });
});

describe('HTTP pengiriman & armada grosir', function (): void {
    it('armada lewat rute, kabar pengiriman lewat rute dengan masalah per bidang, halaman detail & daftar membawa status; tenant lain 404', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/grosir/armada', ['Nama' => 'Truk Engkel 1', 'NomorKendaraan' => 'ad 1234 xy', 'NamaPengemudi' => 'Pak Slamet'])->assertSessionHasNoErrors();
        $this->post('/kelola/grosir/armada', ['Nama' => 'Truk lain', 'NomorKendaraan' => 'AD 1234 XY'])->assertSessionHasErrors('NomorKendaraan');
        $armada = Armada::query()->sole();
        $this->get('/kelola/grosir/armada')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Armada/Daftar')
            ->where('Armada.0.NomorKendaraan', 'AD 1234 XY'));

        $suratJalan = SuratJalanPengirimanUji($this);
        $dasar = "/kelola/grosir/surat-jalan/{$suratJalan->Uuid}/pengiriman";
        $this->post("{$dasar}/berangkat", ['UuidArmada' => $armada->Uuid])->assertSessionHasNoErrors();
        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('SuratJalan.StatusPengiriman', 'DalamPerjalanan')
            ->where('SuratJalan.NamaArmada', 'Truk Engkel 1')
            ->where('Tindakan.Pengiriman', true)
            ->where('RiwayatPengiriman.0.StatusKe', 'DalamPerjalanan')
            ->has('OpsiArmada', 1));
        $this->get('/kelola/grosir/surat-jalan?saring[Pengiriman]=DalamPerjalanan', ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('Data.0.StatusPengiriman', 'DalamPerjalanan');

        $this->post("{$dasar}/diterima", [])->assertSessionHasErrors();
        $this->post("{$dasar}/diterima", ['NamaPenerima' => 'Bu Ani', 'Waktu' => '2026-09-27 09:30'])->assertSessionHasNoErrors();
        expect(SuratJalan::query()->sole()->StatusPengiriman)->toBe(StatusPengirimanGrosir::Diterima);
        $this->post("{$dasar}/langkah-ngawur", [])->assertStatus(404);
        $this->get("/kelola/grosir/surat-jalan/{$suratJalan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tindakan.Batalkan', false));

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        BantuanFitur::Beri($lain['Tenant']->Id, 'piutang.grosir');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->post("{$dasar}/gagal", ['Catatan' => 'Toko tutup total'])->assertStatus(404);
        $this->put("/kelola/grosir/armada/{$armada->Uuid}", ['Nama' => 'Dibajak', 'NomorKendaraan' => 'X 1 Y'])->assertStatus(404);
    });
});
