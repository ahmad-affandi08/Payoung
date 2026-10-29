<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Kueri;

use App\Domain\Karyawan\Kueri\KaryawanPos;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Organisasi\Kueri\JenisPesananOutlet;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\ProfilPajakOutlet;
use App\Domain\Organisasi\Kueri\SektorOutletTenant;
use App\Domain\Organisasi\Kueri\StafPerangkat;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Layanan\VerifierPinOffline;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pajak\Kueri\TarifPajakBerlaku;
use App\Domain\Pelanggan\Kueri\PengaturanDepositTenant;
use App\Domain\Pemenuhan\Kueri\PengaturanLaundryTenant;
use App\Domain\Penjualan\Kueri\DaftarMetodePembayaran;
use App\Domain\Penjualan\Kueri\NomorUrutPenjualanPerangkat;
use App\Domain\Penjualan\Kueri\StatusTokoOnlineOutlet;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Tenant\Kueri\FiturOutlet;
use App\Domain\Tenant\Kueri\PengaturanBarcodeTimbanganTenant;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use stdClass;

/**
 * Isi `GET /api/pos/v1/data-awal` (PRD §16.3). F-06: staf & verifier PIN offline, kategori kas aktif, pengaturan
 * kasir, dan parameter Argon2id. F-07b: batas diskon & pembulatan tunai di `Pengaturan`, identitas `Outlet` &
 * `Perangkat` (nomor BR-07.1, struk), `ProfilPajak` outlet, `TarifPajak` terbit (nasional + kota outlet, belum
 * berakhir, termasuk yang akan berlaku), dan `MetodePembayaran` aktif jenis fase 1. Katalog tetap lewat `/katalog`
 * (F-03); bagian lain (promo, meja) ditambahkan flow masing-masing. PRD v1.46: `Perangkat.NomorUrutPenjualan`
 * = objek `{"YYMMDD": urut terakhir}` penjualan perangkat ini (14 hari terakhir) agar pemasangan ulang aplikasi tidak
 * memakai nomor yang sama (`NomorUrutRetur` sama untuk nomor retur `RJ/...`); `TarifPajak[].Kategori` = kategori jenis pajak (`Ppn`/`Pbjt`/`Lainnya`).
 * Cetak struk (PRD v1.79): `Struk` = pengaturan struk tenant (`TampilkanLogo`, `NamaDicetak`, `TeksKepala`, saklar
 * alamat/telepon/NPWP/kasir/pelanggan/hemat, `CatatanKaki`, `TeksPenutup`) + `NamaUsaha`, `Npwp` (hanya bila outlet
 * PKP), `AdaLogo` (logo usaha tersedia & ditampilkan; diunduh lewat `/logo-struk`), dan `TandaAir` (paket tanpa fitur
 * `struk.tanpa-watermark`). F-16d: `Deposit` (`Berlaku`, `MinimalIsi`, `MaksimalIsi`) &
 * `Perangkat.NomorUrutIsiDeposit`. Laundry (§9.9): `Laundry` (`Aktif`, `JamReguler`, `JamExpress`, `Parfum`,
 * `AwalanLacak` = awalan tautan `/s/{kode}` untuk QR label cucian, selalu terisi). v3.51: `Outlet.JenisPesanan` (daftar
 * `MakanDiTempat`/`BawaPulang`/`Antar` yang dipilih kasir) & `Outlet.JenisPesananBawaan` (bawaan transaksi baru).
 * K-8: `Outlet.ModeKasir` (mode kasir template sektor, enum `ModeKasir`) & `Outlet.ModeKasirBawaan`.
 * D-16: `Outlet.AdaLogo` = logo merek outlet tersedia untuk bilah atas (diunduh lewat `/logo-outlet`, terpisah dari
 * `/logo-struk` yang tunduk pada saklar tampil struk).
 */
final class DataAwalKasir
{
    public function __construct(
        private readonly StafPerangkat $staf,
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly OutletPenjualan $outlet,
        private readonly ProfilPajakOutlet $profilPajak,
        private readonly TarifPajakBerlaku $tarifPajak,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly DaftarMetodePembayaran $metodePembayaran,
        private readonly NomorUrutPenjualanPerangkat $nomorUrut,
        private readonly KaryawanPos $karyawan,
        private readonly ProfilTenant $profilTenant,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PengaturanStrukTenant $pengaturanStruk,
        private readonly PengaturanDepositTenant $deposit,
        private readonly PengaturanLaundryTenant $laundry,
        private readonly StatusTokoOnlineOutlet $statusTokoOnline,
        private readonly JenisPesananOutlet $jenisPesanan,
        private readonly PengaturanBarcodeTimbanganTenant $barcodeTimbangan,
        private readonly FiturOutlet $fiturOutlet,
        private readonly SektorOutletTenant $sektorOutlet,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Perangkat $perangkat): array
    {
        $pengaturan = $this->pengaturan->Ambil();
        $outlet = $this->outlet->Ambil($perangkat->IdOutlet, $perangkat->Id);
        $profil = $this->profilPajak->Ambil($perangkat->IdOutlet);
        $hariIni = $this->tanggalBisnis->Hitung($perangkat->IdOutlet);
        $nomorUrut = $this->nomorUrut->Ambil($perangkat->Id, $hariIni);
        $nomorUrutRetur = $this->nomorUrut->AmbilRetur($perangkat->Id, $hariIni);
        $nomorUrutDeposit = $this->nomorUrut->AmbilIsiDeposit($perangkat->Id, $hariIni);

        return [
            'Pengaturan' => [
                'BatasKasKeluar' => $pengaturan->batasKasKeluar->KeString(),
                'ShiftBersama' => $pengaturan->shiftBersama,
                'BatasDiskonManual' => (string) $pengaturan->batasDiskonManual,
                'BatasDiskonPenyetuju' => (string) $pengaturan->batasDiskonPenyetuju,
                'PembulatanTunai' => $pengaturan->AmbilPembulatanTunaiLarik(),
                // F-11: tutup shift buta & toleransi selisih kas (di atasnya wajib alasan + PIN shift.selisih.setujui).
                'TutupShiftButa' => $pengaturan->tutupShiftButa,
                'ToleransiSelisihKas' => $pengaturan->toleransiSelisihKas->KeString(),
                // F-09: batas hari retur sejak tanggal bisnis penjualan (aplikasi memeriksa sebelum mengirim retur).
                'BatasHariRetur' => $pengaturan->batasHariRetur,
                // F-12: tempo butuh penyetuju bila pelanggan punya piutang lewat jatuh tempo lebih dari N hari (BR-12.1).
                'BatasHariLewatJatuhTempo' => $pengaturan->batasHariLewatJatuhTempo,
                // Cetak struk bagian 4: buka laci manual (tanpa transaksi) wajib PIN penyetuju kas.keluar.setujui.
                'BukaLaciPerluPin' => $pengaturan->bukaLaciPerluPin,
                // K28 (aditif): retur tanpa struk; aplikasi memperingatkan bila Σ hari ini melewati batas (server: tinjauan).
                'BatasReturTanpaStrukHarian' => $pengaturan->batasReturTanpaStrukHarian->KeString(),
                // X4 (aditif): tombol "Minta persetujuan jarak jauh" di dialog PIN penyetuju hanya bila fitur paket aktif.
                'PersetujuanJarakJauh' => $this->fitur->CekAktif($perangkat->IdTenant, 'persetujuan.jarak-jauh'),
                // v3.55 (aditif, §9.3): barcode timbangan EAN-13 `AA PPPPP NNNNN C` (berat gram atau harga Rupiah).
                'BarcodeTimbangan' => $this->barcodeTimbangan->Ambil(),
            ],
            'Struk' => $this->AmbilStruk($perangkat, $profil->pkp ?? false, $outlet?->namaOutlet, $outlet?->idOutlet),
            'Outlet' => $outlet === null ? null : [
                'Uuid' => $outlet->uuidOutlet,
                'Kode' => $outlet->kodeOutlet,
                'Nama' => $outlet->namaOutlet,
                'Alamat' => $outlet->alamat,
                'Telepon' => $outlet->telepon,
                // D-16: logo merek outlet tersedia untuk bilah atas Ruang Kerja (diunduh lewat `/logo-outlet`).
                'AdaLogo' => $this->pengaturanStruk->AmbilPathLogoUsaha($perangkat->IdTenant, $outlet->idOutlet) !== null,
                // Tambahan di luar PRD (aditif): perangkat menghitung tanggal bisnis & nomor BR-07.1 offline.
                'ZonaWaktu' => $outlet->zonaWaktu,
                'JamTutupBuku' => $outlet->jamTutupBuku,
                // v3.51 (aditif): jenis pesanan yang dipilih kasir per transaksi & bawaannya (kosong = tanpa pilihan).
                ...array_intersect_key($this->jenisPesanan->AmbilDariId($outlet->idOutlet), ['JenisPesanan' => 1, 'JenisPesananBawaan' => 1]),
                // K-8 (aditif, §5.1): mode kasir template sektor; kasir memilih beranda & tampilan katalog darinya.
                ...$this->fiturOutlet->AmbilModeKasirPos($outlet->idOutlet),
            ],
            'Perangkat' => [
                'Uuid' => $perangkat->Uuid,
                'Kode' => $perangkat->Kode,
                // K26 (aditif): awalan huruf nomor antrian perangkat ini (`A` → `A-042`), null = nomor polos.
                'AwalanAntrian' => $perangkat->AwalanAntrian,
                // Objek JSON walau kosong (`{}`), bukan larik.
                'NomorUrutPenjualan' => $nomorUrut === [] ? new stdClass : $nomorUrut,
                'NomorUrutRetur' => $nomorUrutRetur === [] ? new stdClass : $nomorUrutRetur,
                'NomorUrutIsiDeposit' => $nomorUrutDeposit === [] ? new stdClass : $nomorUrutDeposit,
            ],
            // F-16d bagian 1: deposit pelanggan (fitur paket, batas isi per transaksi Rupiah bulat).
            'Deposit' => $this->deposit->KeLarik(),
            'Laundry' => $this->AmbilLaundry($perangkat->IdTenant),
            // F-17: kasir hanya menampilkan menu Pesanan toko online bila outlet ini memang melayaninya.
            'TokoOnline' => ['Aktif' => $this->statusTokoOnline->CekAktif($outlet?->idOutlet)],
            // D-48 (aditif): sektor outlet ini (template + jenis usaha tambahan); kasir menyaring fitur khusus sektor.
            'KodeSektor' => $outlet === null ? [] : $this->sektorOutlet->AmbilKodeUntukOutlet($perangkat->IdTenant, $outlet->idOutlet),
            'ProfilPajak' => [
                'Pkp' => $profil->pkp ?? false,
                'PungutPbjt' => $profil->pungutPbjt ?? false,
                'HargaTermasukPajak' => $profil->hargaTermasukPajak ?? false,
                'BiayaLayanan' => ['Aktif' => $profil->biayaLayananAktif ?? false, 'Persen' => $profil->persenBiayaLayanan ?? '0.00'],
            ],
            'TarifPajak' => $this->tarifPajak->DaftarUntukOutlet($outlet?->kodeKota, $hariIni),
            'MetodePembayaran' => $this->metodePembayaran->AmbilUntukPos(),
            'KategoriKas' => array_values(KategoriKas::query()
                ->where('Aktif', true)
                ->orderBy('Jenis')
                ->orderBy('Urutan')
                ->orderBy('Nama')
                ->get()
                ->map(fn (KategoriKas $k): array => ['Uuid' => $k->Uuid, 'Nama' => $k->Nama, 'Jenis' => $k->Jenis->value])
                ->all()),
            'Staf' => $this->staf->Ambil($perangkat),
            // F-18: staf yang bisa dipilih sebagai pelayan baris (komisi).
            'Karyawan' => $this->karyawan->Ambil($perangkat->IdOutlet),
            'PinOffline' => [
                'Tersedia' => $perangkat->KunciPinOffline !== null,
                'Parameter' => VerifierPinOffline::AmbilParameter(),
                'BatasSalah' => 5,
                'MenitKunci' => 5,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function AmbilStruk(Perangkat $perangkat, bool $pkp, ?string $namaOutlet, ?int $idOutlet): array
    {
        $tenant = $this->profilTenant->Ambil($perangkat->IdTenant);
        $struk = $this->pengaturanStruk->Ambil($perangkat->IdTenant, $idOutlet);

        return [
            ...$struk->KeLarik(),
            // D-75: judul struk = nama outlet (identitas yang dilihat pelanggan), bukan nama usaha/akun pemilik.
            'NamaUsaha' => $namaOutlet ?? $tenant['Nama'],
            'Npwp' => $pkp ? $tenant['Npwp'] : null,
            'AdaLogo' => $this->pengaturanStruk->AmbilPathLogo($perangkat->IdTenant, $idOutlet) !== null,
            'TandaAir' => ! $this->fitur->CekAktif($perangkat->IdTenant, 'struk.tanpa-watermark'),
            // POS-11: awalan tautan struk digital; aplikasi menambah Uuid penjualan. Null = struk digital dimatikan.
            'AwalanStrukDigital' => $struk->tampilkanStrukDigital ? KodeStrukDigital::AmbilAwalan($perangkat->IdTenant) : null,
        ];
    }

    /**
     * @return array{Aktif: bool, JamReguler: int, JamExpress: int, Parfum: list<string>, AwalanLacak: string}
     */
    private function AmbilLaundry(int $idTenant): array
    {
        $p = $this->laundry->AmbilLarik();

        return [
            'Aktif' => $p['Aktif'],
            'JamReguler' => $p['JamReguler'],
            'JamExpress' => $p['JamExpress'],
            'Parfum' => $p['Parfum'],
            'AwalanLacak' => KodeStrukDigital::AmbilAwalan($idTenant),
        ];
    }
}
