<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/**
 * Peran bawaan yang dibuat untuk setiap tenant (PRD §19.1). Nilai = kolom `Peran.Kode`. Peran khusus sektor
 * (Dapur/Barista) ditambahkan template sektor di F-01; `Apoteker` (§9.5, K-26) bawaan untuk sektor Apotek. `Pelayan` (v2.00) bawaan untuk mode Pelayan; `Salesman`
 * (§19 "Sales/Salesman", SLS-11) bawaan untuk aplikasi mode Salesman.
 *
 * Peran bawaan diselaraskan sistem (tidak diubah tenant); tenant menyesuaikan lewat peran kustom.
 * `Pemilik` selalu lolos pemeriksaan izin, apa pun isi tabel `PeranIzin`.
 */
enum PeranTenantBawaan: string
{
    case Pemilik = 'Pemilik';
    case Admin = 'Admin';
    case ManajerOutlet = 'ManajerOutlet';
    case Supervisor = 'Supervisor';
    case Kasir = 'Kasir';
    case StafGudang = 'StafGudang';
    case StafPembelian = 'StafPembelian';
    case Akuntan = 'Akuntan';
    case Pelayan = 'Pelayan';
    case Salesman = 'Salesman';
    case Apoteker = 'Apoteker';
    case Karyawan = 'Karyawan';

    /**
     * D-183: awalan kode sektor yang memakai peran ini; null = peran umum. Dipakai `PenyaringSektor` untuk tidak
     * menawarkan Pelayan di toko kelontong, Apoteker di bengkel, dan seterusnya.
     *
     * @return list<string>|null
     */
    public function AmbilSektor(): ?array
    {
        return match ($this) {
            self::Pelayan => ['FNB'],
            self::Salesman => ['WHS', 'RTL-BLD', 'BIZ-MFG'],
            self::Apoteker => ['RTL-PHR'],
            default => null,
        };
    }

    public function AmbilNama(): string
    {
        return match ($this) {
            self::Pemilik => 'Pemilik (Owner)',
            self::Admin => 'Admin',
            self::ManajerOutlet => 'Manajer Outlet',
            self::Supervisor => 'Supervisor',
            self::Kasir => 'Kasir',
            self::StafGudang => 'Staf Gudang',
            self::StafPembelian => 'Staf Pembelian',
            self::Akuntan => 'Akuntan',
            self::Pelayan => 'Pelayan',
            self::Salesman => 'Salesman',
            self::Apoteker => 'Apoteker',
            self::Karyawan => 'Karyawan',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::Pemilik => 'Semua akses di semua outlet, termasuk langganan.',
            self::Admin => 'Semua akses kecuali langganan & kepemilikan.',
            self::ManajerOutlet => 'Operasional outlet yang ditugaskan: produk, stok, persetujuan, laporan outlet.',
            self::Supervisor => 'Persetujuan di POS (void, diskon, kas keluar), buka ulang shift.',
            self::Kasir => 'POS: jual, bayar, simpan pesanan, cetak, buka/tutup shift sendiri.',
            self::StafGudang => 'Penerimaan, transfer, opname, penyesuaian stok (butuh persetujuan).',
            self::StafPembelian => 'Pemasok & pesanan pembelian.',
            self::Akuntan => 'Keuangan, jurnal, pajak, tutup buku; membaca semua laporan.',
            self::Pelayan => 'Aplikasi POS mode Pelayan: ambil pesanan meja & kirim ke dapur, tanpa pembayaran.',
            self::Salesman => 'Aplikasi mode Salesman: kunjungan pelanggan, lihat stok & piutang pelanggan, ambil pesanan grosir (dikonfirmasi back-office).',
            self::Karyawan => 'Hanya absen masuk/pulang dari HP (D-180). Tanpa akses dashboard lain; cocok untuk staf yang tidak memakai kasir.',
            self::Apoteker => 'POS apotek: jual seperti Kasir, ditambah menyerahkan obat keras/psikotropika/narkotika dengan resep dan Obat Wajib Apotek; laporan apotek dengan data pasien.',
        };
    }

    /**
     * D-180: apakah orang berperan ini bawaannya ikut absensi. Manajemen (Pemilik, Admin, Akuntan, Manajer Outlet,
     * Supervisor) tidak; staf lapangan ya. Pemilik toko bisa mengubahnya per orang di formulir Tim.
     */
    public function AmbilIkutAbsensiBawaan(): bool
    {
        return ! in_array($this, [self::Pemilik, self::Admin, self::Akuntan, self::ManajerOutlet, self::Supervisor], true);
    }

    /** Cakupan outlet peran bawaan (D-104): peran tingkat usaha selalu semua outlet, peran operasional memilih outlet. */
    public function AmbilCakupanOutlet(): CakupanOutletPeran
    {
        return in_array($this, [self::Pemilik, self::Admin, self::Akuntan], true)
            ? CakupanOutletPeran::Semua
            : CakupanOutletPeran::Terpilih;
    }

    /**
     * @return list<IzinTenant>
     */
    public function AmbilIzin(): array
    {
        return match ($this) {
            self::Pemilik => IzinTenant::cases(),
            self::Admin => array_values(array_filter(IzinTenant::cases(), fn (IzinTenant $izin) => ! $izin->CekKhususPemilik())),
            self::ManajerOutlet => [
                IzinTenant::OutletLihat,
                IzinTenant::PenggunaLihat,
                IzinTenant::ProdukLihat,
                IzinTenant::ProdukKelola,
                IzinTenant::PersediaanLihat,
                IzinTenant::PersediaanKelola,
                IzinTenant::PersediaanPenyesuaianSetujui,
                IzinTenant::PersediaanStokAwalPosting,
                IzinTenant::PenjualanBuat,
                IzinTenant::PenjualanVoid,
                IzinTenant::PenjualanRetur,
                IzinTenant::PenjualanDiskonManual,
                IzinTenant::PosHargaTimpa,
                IzinTenant::KasKeluarSetujui,
                IzinTenant::PenjualanDiskonSetujui,
                IzinTenant::PenjualanTempoSetujui,
                IzinTenant::ShiftSelisihSetujui,
                IzinTenant::LaporanPenjualanLihat,
                // F-02b: perangkat & PIN kasir di outlet yang ditugaskan.
                IzinTenant::PerangkatLihat,
                IzinTenant::PerangkatKelola,
                IzinTenant::PenggunaPinAtur,
                IzinTenant::BantuanTiketLihat,
                IzinTenant::BantuanTiketKelola,
                // F-16a: data pelanggan outlet.
                IzinTenant::PelangganLihat,
                IzinTenant::PelangganKelola,
                // F-18: karyawan & jadwal outlet.
                IzinTenant::KaryawanLihat,
                IzinTenant::KaryawanKelola,
                // D-23 C: menandai transaksi outlet yang perlu dicek.
                IzinTenant::TindakanTinjau,
                // F-07 mode service: reservasi layanan outlet.
                IzinTenant::ReservasiKelola,
                // Laundry: tiket & status proses cucian outlet.
                IzinTenant::LaundryKelola,
                // Bengkel (§9.10): perintah kerja & kendaraan pelanggan outlet.
                IzinTenant::BengkelKelola,
                // F-05f: bahan terbuang outlet.
                IzinTenant::PersediaanTerbuangCatat,
                // Grosir (F-12): SO, surat jalan, dan faktur penjualan outletnya; BR-12.6 penyetuju limit kredit,
                // sejajar dengan PenjualanTempoSetujui untuk penjualan tempo di kasir.
                IzinTenant::GrosirKelola,
                IzinTenant::GrosirSetujuiKredit,
                // F-21: invoice & penawaran outletnya.
                IzinTenant::InvoiceKelola,
                IzinTenant::TokoOnlineKelola,
                IzinTenant::PengirimanKelola,
            ],
            self::Supervisor => [
                IzinTenant::ProdukLihat,
                IzinTenant::PenjualanBuat,
                IzinTenant::PenjualanVoid,
                IzinTenant::PenjualanRetur,
                IzinTenant::PenjualanDiskonManual,
                IzinTenant::PosHargaTimpa,
                // F-06 BR-06.4: PIN supervisor untuk kas keluar di atas batas.
                IzinTenant::KasKeluarSetujui,
                // F-07b BR-07.3: PIN supervisor untuk diskon manual di atas batas kasir.
                IzinTenant::PenjualanDiskonSetujui,
                // F-12 BR-12.1: PIN supervisor untuk penjualan tempo di atas limit / piutang lewat jatuh tempo.
                IzinTenant::PenjualanTempoSetujui,
                // F-11: PIN supervisor untuk selisih kas tutup shift di atas toleransi.
                IzinTenant::ShiftSelisihSetujui,
                IzinTenant::PelangganLihat,
                IzinTenant::KaryawanLihat,
                IzinTenant::ReservasiKelola,
                IzinTenant::LaundryKelola,
                IzinTenant::BengkelKelola,
                IzinTenant::PersediaanTerbuangCatat,
                // Grosir: Supervisor boleh mengelola dokumennya, tetapi persetujuan limit kredit (BR-12.6) TIDAK
                // diberikan bawaan — itu keputusan atas risiko piutang usaha, bukan kelonggaran satu transaksi seperti
                // diskon atau selisih kas. Owner bisa menambahkannya lewat peran kustom bila memang diinginkan.
                IzinTenant::GrosirKelola,
                IzinTenant::TokoOnlineKelola,
                IzinTenant::PengirimanKelola,
            ],
            self::Kasir => [IzinTenant::ProdukLihat, IzinTenant::PenjualanBuat],
            // D-180: tidak ada izin back-office; halaman Absen terbuka bagi siapa pun yang ikut absensi (bukan izin).
            self::Karyawan => [],
            self::Pelayan => [IzinTenant::ProdukLihat, IzinTenant::PesananMejaCatat],
            // Salesman hanya mengambil pesanan (draf) dan mencatat kunjungan; konfirmasi SO & BR-12.6 tetap di tangan
            // pemegang `grosir.kelola`/`grosir.setujui-kredit` di back-office.
            self::Salesman => [IzinTenant::ProdukLihat, IzinTenant::PelangganLihat, IzinTenant::SalesmanKunjungan],
            // Apotek (§9.5, §19): apoteker penanggung jawab pelayanan resep (PMK 73/2016). Laporan penjualan ikut karena
            // laporan obat wajib resep & data pendukung SIPNAP berada di bawah izin itu; data pasien butuh `apotek.resep.lihat`.
            self::Apoteker => [
                IzinTenant::ProdukLihat,
                IzinTenant::PenjualanBuat,
                IzinTenant::PelangganLihat,
                IzinTenant::LaporanPenjualanLihat,
                IzinTenant::ApotekObatKerasJual,
                IzinTenant::ApotekResepLihat,
            ],
            self::StafGudang => [IzinTenant::ProdukLihat, IzinTenant::PersediaanLihat, IzinTenant::PersediaanKelola, IzinTenant::PersediaanTerbuangCatat, IzinTenant::PengirimanKelola],
            self::StafPembelian => [IzinTenant::ProdukLihat, IzinTenant::PersediaanLihat, IzinTenant::PembelianKelola],
            self::Akuntan => [
                IzinTenant::OutletLihat,
                IzinTenant::ProdukLihat,
                IzinTenant::PersediaanLihat,
                // F-05a: Akuntan memposting stok awal karena menulis jurnal ekuitas saldo awal.
                IzinTenant::PersediaanStokAwalPosting,
                IzinTenant::LaporanPenjualanLihat,
                IzinTenant::LaporanKeuanganLihat,
                IzinTenant::AkuntansiKelola,
                // F-21: Akuntan menerbitkan dan menagih invoice (pembukuan penjualan jasa).
                IzinTenant::InvoiceKelola,
                IzinTenant::PelangganLihat,
                IzinTenant::KaryawanLihat,
                IzinTenant::TindakanTinjau,
            ],
        };
    }
}
