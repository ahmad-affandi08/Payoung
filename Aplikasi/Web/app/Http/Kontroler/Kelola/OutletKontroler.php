<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Organisasi\Aksi\AturJamOperasionalOutlet;
use App\Domain\Organisasi\Aksi\AturJenisPesananOutlet;
use App\Domain\Organisasi\Aksi\AturKiosOutlet;
use App\Domain\Organisasi\Aksi\AturLayarAbsensiOutlet;
use App\Domain\Organisasi\Aksi\AturLokasiAbsensiOutlet;
use App\Domain\Organisasi\Aksi\AturWajibQrAbsensi;
use App\Domain\Organisasi\Aksi\SimpanOutlet;
use App\Domain\Organisasi\Aksi\UbahStatusOutlet;
use App\Domain\Organisasi\Enum\BentukMeja;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\JenisPesanan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\JenisPesananOutlet;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Layanan\JamOperasionalOutlet;
use App\Domain\Organisasi\Layanan\PenjagaModeMeja;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Referensi\Enum\ZonaWaktu;
use App\Domain\Referensi\Kueri\WilayahKota;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Http\Permintaan\Kelola\AturJamOperasionalOutletPermintaan;
use App\Http\Permintaan\Kelola\AturJenisPesananOutletPermintaan;
use App\Http\Permintaan\Kelola\AturLokasiAbsensiOutletPermintaan;
use App\Http\Permintaan\Kelola\AturPesanSendiriOutletPermintaan;
use App\Http\Permintaan\Kelola\AturWajibQrAbsensiPermintaan;
use App\Http\Permintaan\Kelola\SimpanOutletPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outlet & merek (F-02 langkah 1, BR-02.1, BR-02.2). Daftar menampilkan pemakaian batas paket.
 */
final class OutletKontroler extends DasarKelolaKontroler
{
    public function Daftar(WilayahKota $wilayahKota, PemakaianBatasOrganisasi $pemakaian, PastikanBatasPaket $batasPaket): Response
    {
        $boleh = $this->IdOutletBoleh();
        $kota = $this->PetakanKota($wilayahKota);
        $namaKota = array_column($kota, 'Nama', 'Kode');
        $jumlahGudang = Gudang::query()->where('Status', StatusOrganisasi::Aktif->value)->selectRaw('IdOutlet, count(*) as Jumlah')->groupBy('IdOutlet')->pluck('Jumlah', 'IdOutlet');
        $merek = Merek::query()->orderBy('Nama')->get();
        $namaMerek = $merek->pluck('Nama', 'Id');

        $outlet = Outlet::query()
            ->when($boleh !== null, fn ($kueri) => $kueri->whereKey($boleh ?? []))
            ->orderBy('Status')
            ->orderBy('Nama')
            ->get()
            ->map(fn (Outlet $baris): array => [
                'Uuid' => $baris->Uuid,
                'Kode' => $baris->Kode,
                'Nama' => $baris->Nama,
                'NamaMerek' => $namaMerek->get($baris->IdMerek),
                'NamaKota' => $baris->KodeKota === null ? null : ($namaKota[$baris->KodeKota] ?? $baris->KodeKota),
                'ZonaWaktu' => self::LabelZonaWaktu($baris->ZonaWaktu),
                'JamTutupBuku' => $baris->JamTutupBuku,
                'JumlahGudang' => (int) ($jumlahGudang->get($baris->Id) ?? 0),
                'Status' => $baris->Status->value,
            ]);
        $jumlahOutletPerMerek = Outlet::query()->selectRaw('IdMerek, count(*) as Jumlah')->groupBy('IdMerek')->pluck('Jumlah', 'IdMerek');

        return Inertia::render('Kelola/Outlet/Daftar', [
            'Outlet' => $outlet->values(),
            'Merek' => $merek->map(fn (Merek $baris): array => [
                'Uuid' => $baris->Uuid,
                'Nama' => $baris->Nama,
                'JumlahOutlet' => (int) ($jumlahOutletPerMerek->get($baris->Id) ?? 0),
            ])->values(),
            'Kota' => $kota,
            'BatasOutlet' => $batasPaket->AmbilRingkasan($this->IdTenant(), 'BatasOutlet', $pemakaian->HitungOutlet()),
        ]);
    }

    /** Halaman penuh "Tambah outlet" (pola sama dengan Tambah produk); batas paket tetap ditampilkan. */
    public function Buat(WilayahKota $wilayahKota, PemakaianBatasOrganisasi $pemakaian, PastikanBatasPaket $batasPaket): Response
    {
        return Inertia::render('Kelola/Outlet/Buat', [
            'Merek' => Merek::query()->orderBy('Nama')->get()->map(fn (Merek $merek): array => ['Nilai' => $merek->Uuid, 'Label' => $merek->Nama])->values(),
            'Kota' => $this->PetakanKota($wilayahKota),
            'BatasOutlet' => $batasPaket->AmbilRingkasan($this->IdTenant(), 'BatasOutlet', $pemakaian->HitungOutlet()),
        ]);
    }

    public function AturJenisPesanan(string $outlet, AturJenisPesananOutletPermintaan $permintaan, AturJenisPesananOutlet $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->boolean('Otomatis'), $permintaan->AmbilDaftar(), $permintaan->AmbilBawaan());

        return back()->with('Kilat', "Jenis pesanan kasir {$baris->Nama} disimpan. Perangkat kasir memakainya setelah memuat ulang data.");
    }

    /** F-18 bagian 4 (D-37): titik lokasi & radius absensi web outlet. */
    public function AturLokasiAbsensi(string $outlet, AturLokasiAbsensiOutletPermintaan $permintaan, AturLokasiAbsensiOutlet $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->AmbilLintang(), $permintaan->AmbilBujur(), $permintaan->integer('RadiusAbsensiMeter'));

        return back()->with('Kilat', $baris->Lintang === null
            ? "Titik lokasi {$baris->Nama} dihapus. Absen dari HP tidak bisa di outlet ini."
            : "Lokasi absensi {$baris->Nama} disimpan (radius {$baris->RadiusAbsensiMeter} m).");
    }

    /** F-e: jam operasional per hari (jam lokal outlet); dipakai toko online untuk menutup pesanan di luar jam buka. */
    public function AturJamOperasional(string $outlet, AturJamOperasionalOutletPermintaan $permintaan, AturJamOperasionalOutlet $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->AmbilJam());

        return back()->with('Kilat', $baris->JamOperasional === null
            ? "Jam operasional {$baris->Nama} dihapus. Outlet dianggap selalu buka."
            : "Jam operasional {$baris->Nama} disimpan.");
    }

    /** F-18 bagian 4 (D-37): buat (ulang) tautan layar QR absensi outlet; tautan & kode lama langsung mati. */
    public function BuatLayarAbsensi(string $outlet, AturLayarAbsensiOutlet $atur): RedirectResponse
    {
        $baris = $this->CariOutlet($outlet);
        $atur->Jalankan($baris, true);

        return back()->with('Kilat', "Tautan layar QR absensi {$baris->Nama} dibuat. Buka tautannya di tablet atau monitor di outlet.");
    }

    public function CabutLayarAbsensi(string $outlet, AturLayarAbsensiOutlet $atur): RedirectResponse
    {
        $baris = $this->CariOutlet($outlet);
        $atur->Jalankan($baris, false);

        return back()->with('Kilat', "Layar QR absensi {$baris->Nama} dicabut. Absen dari HP di outlet ini tidak lagi meminta QR.");
    }

    public function AturWajibQrAbsensi(string $outlet, AturWajibQrAbsensiPermintaan $permintaan, AturWajibQrAbsensi $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->boolean('Wajib'));

        return back()->with('Kilat', $baris->WajibQrAbsensi
            ? "Absen dari HP di {$baris->Nama} kini wajib memindai QR di layar outlet."
            : "Absen dari HP di {$baris->Nama} tidak lagi wajib memindai QR.");
    }

    public function Detail(string $outlet, WilayahKota $wilayahKota, MejaOutlet $mejaOutlet, PenjagaModeMeja $modeMeja, PemeriksaFiturTenant $fitur, JenisPesananOutlet $jenisPesanan, ProfilTenant $profil): Response
    {
        $baris = $this->CariOutlet($outlet);

        return Inertia::render('Kelola/Outlet/Detail', [
            'Outlet' => [
                'Uuid' => $baris->Uuid,
                'Kode' => $baris->Kode,
                'Nama' => $baris->Nama,
                'UuidMerek' => Merek::query()->whereKey($baris->IdMerek)->value('Uuid'),
                'Alamat' => $baris->Alamat,
                'KodeKota' => $baris->KodeKota,
                'ZonaWaktu' => self::LabelZonaWaktu($baris->ZonaWaktu),
                'JamTutupBuku' => $baris->JamTutupBuku,
                'Pkp' => (bool) ($baris->ProfilPajak['Pkp'] ?? false),
                'Nitku' => is_string($baris->ProfilPajak['Nitku'] ?? null) ? $baris->ProfilPajak['Nitku'] : null,
                'PungutPbjt' => (bool) ($baris->ProfilPajak['PungutPbjt'] ?? false),
                'Status' => $baris->Status->value,
                'KodeTerkunci' => $baris->KodeDikunciPada !== null,
                // Modul Salesman bagian 3: outlet kanvas = kendaraan salesman.
                'Kanvas' => $baris->Kanvas,
                'NomorKendaraan' => $baris->NomorKendaraan,
            ],
            'Gudang' => Gudang::query()
                ->where('IdOutlet', $baris->Id)
                ->orderBy('Status')
                ->orderBy('Nama')
                ->get()
                ->map(fn (Gudang $gudang): array => [
                    'Uuid' => $gudang->Uuid,
                    'Kode' => $gudang->Kode,
                    'Nama' => $gudang->Nama,
                    'Jenis' => $gudang->Jenis->value,
                    'Status' => $gudang->Status->value,
                ])
                ->values(),
            'Merek' => Merek::query()->orderBy('Nama')->get()->map(fn (Merek $merek): array => ['Nilai' => $merek->Uuid, 'Label' => $merek->Nama])->values(),
            'Kota' => $this->PetakanKota($wilayahKota),
            'JenisGudang' => array_map(fn (JenisGudang $jenis): array => ['Nilai' => $jenis->value, 'Label' => $jenis->AmbilLabel()], JenisGudang::cases()),
            // F-10a: bagian meja tampil bila fitur mode meja aktif di outlet ini atau sudah ada data meja.
            'ModeMeja' => ['Aktif' => $modeMeja->CekAktif($baris), ...$mejaOutlet->Ambil($baris->Id)],
            'BentukMeja' => array_map(fn (BentukMeja $bentuk): array => ['Nilai' => $bentuk->value, 'Label' => $bentuk->AmbilLabel()], BentukMeja::cases()),
            // v3.51: jenis pesanan yang dipilih kasir per transaksi.
            'JenisPesanan' => [
                ...$jenisPesanan->Ambil($baris),
                'Pilihan' => array_map(fn (JenisPesanan $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel()], JenisPesanan::cases()),
            ],
            // F-18 bagian 4 (D-37): titik lokasi & radius absensi web.
            'LokasiAbsensi' => [
                'Lintang' => $baris->Lintang === null ? null : (string) $baris->Lintang,
                'Bujur' => $baris->Bujur === null ? null : (string) $baris->Bujur,
                'RadiusMeter' => $baris->RadiusAbsensiMeter,
                // Layar QR berganti (bukti hadir kedua): tautan rahasia untuk tablet/monitor outlet.
                'TautanLayar' => is_string($baris->TokenLayarAbsen) ? url('/'.$profil->AmbilSlug($baris->IdTenant)."/layar-absen/{$baris->TokenLayarAbsen}") : null,
                'WajibQr' => $baris->WajibQrAbsensi,
            ],
            // F-e: jam operasional per hari (kosong = tidak diatur, selalu buka).
            'JamOperasional' => [
                'Diatur' => $baris->JamOperasional !== null,
                'Hari' => array_values(array_map(fn (string $nama): array => [
                    'Nama' => $nama,
                    'Buka' => $baris->JamOperasional[$nama]['Buka'] ?? null,
                    'Tutup' => $baris->JamOperasional[$nama]['Tutup'] ?? null,
                ], JamOperasionalOutlet::HARI)),
                'ZonaWaktu' => $baris->ZonaWaktu,
            ],
            // F-17: pesan sendiri QR meja (sakelar outlet + fitur paket `kanal.self-order`).
            'PesanSendiri' => [
                'FiturAktif' => $fitur->CekAktif($baris->IdTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI),
                'Aktif' => $baris->PesanSendiriAktif,
            ],
            // F-17 bagian 4: kios pesan sendiri di layar sentuh outlet (tautan rahasia per outlet).
            'Kios' => [
                'FiturAktif' => $fitur->CekAktif($baris->IdTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI),
                'Aktif' => $baris->KiosAktif,
                'Tautan' => is_string($baris->TokenKios) ? url('/'.$profil->AmbilSlug($baris->IdTenant)."/kios/{$baris->TokenKios}") : null,
                'TautanAntrian' => is_string($baris->TokenKios) ? url('/'.$profil->AmbilSlug($baris->IdTenant)."/kios/{$baris->TokenKios}/antrian") : null,
                'QrisTersedia' => PengaturanTokoOnline::query()->value('QrisAktif') === true,
            ],
        ]);
    }

    /** F-17 bagian 4: hidupkan/matikan kios pesan sendiri outlet; tautan rahasia dibuat saat pertama kali dihidupkan. */
    public function AturKios(string $outlet, AturPesanSendiriOutletPermintaan $permintaan, AturKiosOutlet $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->boolean('Aktif'));

        return back()->with('Kilat', $baris->KiosAktif
            ? "Kios pesan sendiri aktif di {$baris->Nama}. Buka tautannya di tablet outlet."
            : "Kios pesan sendiri dimatikan di {$baris->Nama}.");
    }

    /** Tautan lama langsung mati (misal tablet hilang); tablet outlet harus membuka tautan yang baru. */
    public function BuatUlangKios(string $outlet, AturKiosOutlet $atur): RedirectResponse
    {
        $baris = $atur->BuatUlangToken($this->CariOutlet($outlet));

        return back()->with('Kilat', "Tautan kios {$baris->Nama} dibuat ulang. Tautan lama tidak berlaku lagi; buka tautan baru di tablet outlet.");
    }

    public function Simpan(SimpanOutletPermintaan $permintaan, SimpanOutlet $simpan): RedirectResponse
    {
        $outlet = $simpan->Jalankan(null, $permintaan->AmbilData());

        return redirect()->route('kelola.outlet.detail', ['outlet' => $outlet->Uuid])
            ->with('Kilat', "Outlet {$outlet->Nama} ditambahkan bersama lokasi stok Toko.");
    }

    public function Ubah(string $outlet, SimpanOutletPermintaan $permintaan, SimpanOutlet $simpan): RedirectResponse
    {
        $baris = $simpan->Jalankan($this->CariOutlet($outlet), $permintaan->AmbilData());

        return back()->with('Kilat', "Outlet {$baris->Nama} disimpan.");
    }

    public function Arsipkan(string $outlet, UbahStatusOutlet $ubahStatus): RedirectResponse
    {
        $baris = $ubahStatus->Jalankan($this->CariOutlet($outlet), StatusOrganisasi::Diarsipkan);

        return back()->with('Kilat', "Outlet {$baris->Nama} diarsipkan. Datanya tetap tersimpan dan bisa dipulihkan.");
    }

    public function Pulihkan(string $outlet, UbahStatusOutlet $ubahStatus): RedirectResponse
    {
        $baris = $ubahStatus->Jalankan($this->CariOutlet($outlet), StatusOrganisasi::Aktif);

        return back()->with('Kilat', "Outlet {$baris->Nama} aktif kembali.");
    }

    /**
     * @return list<array{Kode: string, Nama: string, NamaProvinsi: string|null, ZonaWaktu: string}>
     */
    private function PetakanKota(WilayahKota $wilayahKota): array
    {
        return array_map(fn (array $kota): array => [
            'Kode' => $kota['Kode'],
            'Nama' => $kota['Nama'],
            'NamaProvinsi' => $kota['NamaProvinsi'],
            'ZonaWaktu' => $kota['ZonaWaktu'],
        ], $wilayahKota->AmbilSemua());
    }

    /** Zona IANA yang disimpan → WIB/WITA/WIT untuk tampilan (§17.6.7). */
    private static function LabelZonaWaktu(string $zonaIana): string
    {
        foreach (ZonaWaktu::cases() as $zona) {
            if ($zona->AmbilZonaIana() === $zonaIana) {
                return $zona->value;
            }
        }

        return $zonaIana;
    }
}
