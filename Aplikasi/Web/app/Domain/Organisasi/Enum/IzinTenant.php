<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/**
 * Izin tenant (PRD §19.1, format D-06 `{modul}.{objek}.{aksi}`). Izin F-02 dipakai perantara `WajibIzinTenant`.
 * Izin modul lain sudah didaftarkan agar peran bawaan & peran kustom stabil sejak awal; penegakannya dibangun
 * bersama flow masing-masing (F-03 produk, F-05 persediaan, F-07/F-09 penjualan, F-13 akuntansi, F-14 laporan,
 * F-19 langganan). Menambah izin baru = tambah case + sesuaikan `PeranTenantBawaan`, lalu jalankan
 * `organisasi:siapkan-peran` agar peran bawaan tenant lama ikut menerimanya.
 */
enum IzinTenant: string
{
    // F-02 Setup organisasi.
    case OutletLihat = 'outlet.lihat';
    case OutletKelola = 'outlet.kelola';
    case PenggunaLihat = 'pengguna.lihat';
    case PenggunaUndang = 'pengguna.undang';
    case PenggunaUbah = 'pengguna.ubah';
    case PenggunaNonaktifkan = 'pengguna.nonaktifkan';
    case PeranKelola = 'peran.kelola';
    case AuditLihat = 'audit.lihat';
    // F-02b Perangkat & PIN kasir.
    case PerangkatLihat = 'perangkat.lihat';
    case PerangkatKelola = 'perangkat.kelola';
    case PenggunaPinAtur = 'pengguna.pin.atur';
    // F-01 Panduan awal (onboarding wizard).
    case PanduanAwalKelola = 'panduan-awal.kelola';
    // F-08 v2.06: gerbang pembayaran QRIS dinamis milik tenant (akun merchant & kredensial; dana ke rekening tenant).
    case PembayaranGerbangAtur = 'pembayaran.gerbang.atur';

    // X7 Open API v1: membuat & mencabut token API publik (khusus Owner).
    case IntegrasiApiKelola = 'integrasi.api.kelola';

    // Flow berikutnya (§19.1 & §19.2); penegakan dibangun bersama flow-nya.
    case ProdukLihat = 'produk.lihat';
    case ProdukKelola = 'produk.kelola';
    case ProdukHargaUbah = 'produk.harga.ubah';
    case PersediaanLihat = 'persediaan.lihat';
    case PersediaanKelola = 'persediaan.kelola';
    case PersediaanPenyesuaianSetujui = 'persediaan.penyesuaian.setujui';
    // F-05a: posting & pembatalan stok awal (menulis jurnal ekuitas saldo awal).
    case PersediaanStokAwalPosting = 'persediaan.stok-awal.posting';
    case PembelianKelola = 'pembelian.kelola';
    // F-04 fase 1 (§19.2): menyetujui PO di atas `BatasPersetujuanPo` (bukan pembuatnya; bawaan Pemilik & Admin).
    case PembelianPoSetujui = 'pembelian.po.setujui';
    case PenjualanBuat = 'penjualan.buat';
    case PenjualanVoid = 'penjualan.void';
    // K-22 (F-09): menerima retur penjualan / menyetujui retur, terpisah dari void.
    case PenjualanRetur = 'penjualan.retur';
    // K28 (F-09): PIN penyetuju retur tanpa struk (barang dikembalikan tanpa bukti beli). Bawaan Pemilik & Admin.
    case PenjualanReturTanpaStruk = 'penjualan.retur.tanpa-struk';
    case PenjualanDiskonManual = 'penjualan.diskon.manual';
    // Price engine lapis 1 (§F-03): mengubah harga satu barang langsung di kasir (tawar-menawar, barang cacat). Tanpa izin ini
    // kasir memakai PIN penyetuju yang punya izin; Supervisor & Manajer Outlet memilikinya bawaan.
    case PosHargaTimpa = 'pos.harga.timpa';
    // F-06 BR-06.4: menyetujui kas keluar di atas batas dengan PIN (supervisor ke atas).
    case KasKeluarSetujui = 'kas.keluar.setujui';
    // F-07b BR-07.3: menyetujui diskon manual di atas batas kasir dengan PIN (supervisor ke atas).
    case PenjualanDiskonSetujui = 'penjualan.diskon.setujui';
    // F-12 BR-12.1: PIN penyetuju penjualan tempo di atas limit kredit / piutang lewat jatuh tempo.
    case PenjualanTempoSetujui = 'penjualan.tempo.setujui';
    // F-11: menyetujui selisih kas tutup shift di atas toleransi dengan PIN (supervisor ke atas).
    case ShiftSelisihSetujui = 'shift.selisih.setujui';
    case LaporanPenjualanLihat = 'laporan.penjualan.lihat';
    case LaporanKeuanganLihat = 'laporan.keuangan.lihat';
    case AkuntansiKelola = 'akuntansi.kelola';
    case LanggananKelola = 'langganan.kelola';
    // Audit E5 (UU PDP): mengunduh ekspor data lengkap usaha dan mengajukan penutupan akun; khusus Pemilik.
    case DataKelola = 'data.kelola';

    // P-09 Bantuan (tiket dukungan ke tim platform).
    case BantuanTiketLihat = 'bantuan.tiket.lihat';
    case BantuanTiketKelola = 'bantuan.tiket.kelola';
    // PGL-16 (P-09, D-132): memberi, melihat riwayat, dan mencabut izin akses tim dukungan Payoung ke usaha (khusus Owner).
    case DukunganAksesKelola = 'dukungan.akses.kelola';
    // F-16a CRM-01 pelanggan (back-office; memilih & membuat pelanggan di POS cukup `penjualan.buat`).
    case PelangganLihat = 'pelanggan.lihat';
    case PelangganKelola = 'pelanggan.kelola';
    // F-16d bagian 1: tarik/sesuaikan deposit pelanggan & batal isi deposit (uang pelanggan).
    case PelangganDepositKelola = 'pelanggan.deposit.kelola';
    // F-16d bagian 2: mengembalikan/menghanguskan sisa paket sesi pelanggan.
    case PelangganSesiKelola = 'pelanggan.sesi.kelola';
    // F-18: karyawan, jadwal kerja, rekap absensi.
    case KaryawanLihat = 'karyawan.lihat';
    case KaryawanKelola = 'karyawan.kelola';
    // v2.00 mode Pelayan: mencatat pesanan meja & mengirim ke dapur tanpa berjualan/menerima pembayaran.
    case PesananMejaCatat = 'pesanan.meja.catat';
    // D-23 C: menandai dokumen offline yang perlu ditinjau "sudah dicek" di Kotak Tindakan.
    case TindakanTinjau = 'tindakan.tinjau';
    // F-07 mode service: melihat & mengelola reservasi layanan (buat, konfirmasi, check-in, pindah jadwal, batal).
    case ReservasiKelola = 'reservasi.kelola';
    // Laundry (§9.9): melihat tiket laundry, mengubah status proses, pengaturan laundry.
    case LaundryKelola = 'laundry.kelola';
    // Bengkel (§9.10): perintah kerja, kendaraan pelanggan, persetujuan estimasi, servis berkala.
    case BengkelKelola = 'bengkel.kelola';
    // F-05f: mencatat bahan terbuang (waste) dari kasir/dapur atau back-office.
    case PersediaanTerbuangCatat = 'persediaan.terbuang.catat';
    // Grosir (F-12, §9.7): sales order, surat jalan, dan faktur penjualan grosir.
    case GrosirKelola = 'grosir.kelola';
    // BR-12.6: mengonfirmasi SO grosir yang melampaui limit kredit atau pelanggannya punya piutang lewat jatuh tempo.
    case GrosirSetujuiKredit = 'grosir.setujui-kredit';
    // Modul Salesman (§9.7, SLS-11): aplikasi mode Salesman — kunjungan pelanggan, lihat stok & piutang pelanggan,
    // ambil pesanan grosir (masuk sebagai draf; konfirmasi & BR-12.6 tetap di back-office).
    case SalesmanKunjungan = 'salesman.kunjungan';
    // F-17/F-10c: kanal toko online dan operasional pengiriman.
    case TokoOnlineKelola = 'toko-online.kelola';
    case PengirimanKelola = 'pengiriman.kelola';
    // Apotek (§9.5, §19 "Apoteker"): menyerahkan obat keras/psikotropika/narkotika (dengan resep) & Obat Wajib Apotek
    // (tanpa resep, dicatat), serta memvalidasi resep di kasir. Asisten apoteker = Kasir biasa tanpa izin ini.
    case ApotekObatKerasJual = 'apotek.obat-keras.jual';
    // Apotek: melihat resep penjualan lengkap dengan data pasien (nama & alamat tidak disamarkan) di laporan apotek.
    case ApotekResepLihat = 'apotek.resep.lihat';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::OutletLihat => 'Melihat outlet, gudang, dan merek',
            self::OutletKelola => 'Menambah, mengubah, dan mengarsipkan outlet, gudang, dan merek',
            self::PenggunaLihat => 'Melihat daftar pengguna & peran',
            self::PenggunaUndang => 'Mengundang pengguna',
            self::PenggunaUbah => 'Mengubah peran & akses outlet pengguna',
            self::PenggunaNonaktifkan => 'Menonaktifkan & mengaktifkan kembali pengguna',
            self::PeranKelola => 'Membuat & mengubah peran kustom',
            self::AuditLihat => 'Melihat log audit',
            // F-02b
            self::PerangkatLihat => 'Melihat perangkat POS',
            self::PerangkatKelola => 'Menambah, mengaktifkan, dan mencabut perangkat POS',
            self::PenggunaPinAtur => 'Mengatur ulang PIN kasir anggota',
            self::PanduanAwalKelola => 'Menjalankan panduan awal (profil usaha, template sektor, pajak, produk awal, metode pembayaran)',
            self::PembayaranGerbangAtur => 'Mengatur gerbang pembayaran QRIS dinamis (akun merchant & kredensial)',
            self::IntegrasiApiKelola => 'Membuat & mencabut token API publik untuk integrasi pihak ketiga',
            self::ProdukLihat => 'Melihat produk',
            self::ProdukKelola => 'Mengelola produk',
            self::ProdukHargaUbah => 'Mengubah harga jual',
            self::PersediaanLihat => 'Melihat stok',
            self::PersediaanKelola => 'Penerimaan, transfer, opname, penyesuaian stok',
            self::PersediaanPenyesuaianSetujui => 'Menyetujui penyesuaian stok',
            self::PersediaanStokAwalPosting => 'Memposting & membatalkan stok awal (jurnal ekuitas saldo awal)',
            self::PembelianKelola => 'Mengelola pemasok, pesanan pembelian, penerimaan, faktur, hutang, dan retur pembelian',
            self::PembelianPoSetujui => 'Menyetujui pesanan pembelian di atas batas persetujuan',
            self::PenjualanBuat => 'Berjualan di POS (jual, bayar, simpan pesanan, shift sendiri)',
            self::PenjualanVoid => 'Membatalkan (void) transaksi',
            self::PenjualanRetur => 'Menerima & menyetujui retur penjualan (termasuk tukar barang)',
            self::PenjualanReturTanpaStruk => 'Menyetujui retur tanpa struk (refund tukar barang atau deposit pelanggan)',
            self::PenjualanDiskonManual => 'Memberi diskon manual',
            self::PosHargaTimpa => 'Mengubah harga barang langsung di kasir',
            self::KasKeluarSetujui => 'Menyetujui kas keluar di atas batas',
            self::PenjualanDiskonSetujui => 'Menyetujui diskon manual di atas batas kasir',
            self::PenjualanTempoSetujui => 'Menyetujui penjualan tempo di atas limit kredit atau saat piutang lewat jatuh tempo',
            self::ShiftSelisihSetujui => 'Menyetujui selisih kas tutup shift di atas toleransi, buka ulang shift, & menutup paksa shift yang tidak ditutup kasir',
            self::LaporanPenjualanLihat => 'Melihat laporan penjualan',
            self::LaporanKeuanganLihat => 'Melihat laporan keuangan',
            self::AkuntansiKelola => 'Mengelola jurnal, pajak, dan tutup buku',
            self::LanggananKelola => 'Mengelola langganan & tagihan',
            self::DataKelola => 'Mengunduh ekspor data lengkap usaha, mengganti jenis usaha outlet, dan menutup akun usaha',
            self::BantuanTiketLihat => 'Melihat tiket bantuan & balasan tim dukungan',
            self::BantuanTiketKelola => 'Membuat, membalas, dan menyelesaikan tiket bantuan',
            self::DukunganAksesKelola => 'Memberi, memantau, dan mencabut izin akses tim dukungan Payoung ke usaha',
            self::PelangganLihat => 'Melihat data & riwayat belanja pelanggan',
            self::PelangganKelola => 'Menambah, mengubah, dan mengarsipkan pelanggan',
            self::PelangganDepositKelola => 'Menarik, menyesuaikan, dan membatalkan isi deposit pelanggan',
            self::PelangganSesiKelola => 'Mengembalikan atau menghanguskan sisa paket sesi pelanggan',
            self::KaryawanLihat => 'Melihat karyawan, jadwal kerja, dan rekap absensi',
            self::KaryawanKelola => 'Mengelola data karyawan dan jadwal kerja',
            self::PesananMejaCatat => 'Mencatat pesanan meja & mengirim ke dapur tanpa menerima pembayaran (Pelayan)',
            self::TindakanTinjau => 'Menandai transaksi yang perlu dicek sebagai sudah dicek (Kotak Tindakan)',
            self::ReservasiKelola => 'Melihat & mengelola reservasi layanan (buat, konfirmasi, check-in, pindah jadwal, batal)',
            self::LaundryKelola => 'Melihat tiket laundry & mengubah status proses cucian (cuci, kering, setrika, siap, diambil)',
            self::BengkelKelola => 'Mengelola perintah kerja bengkel, kendaraan pelanggan, persetujuan estimasi, dan servis berkala',
            self::PersediaanTerbuangCatat => 'Mencatat bahan/menu terbuang (waste) dari kasir, dapur, atau back-office',
            self::GrosirKelola => 'Mengelola pesanan grosir, surat jalan, dan faktur penjualan',
            self::GrosirSetujuiKredit => 'Menyetujui pesanan grosir yang melampaui limit kredit',
            self::SalesmanKunjungan => 'Mencatat kunjungan pelanggan & mengambil pesanan grosir dari aplikasi (Salesman)',
            self::TokoOnlineKelola => 'Mengelola toko online, zona ongkir, dan pesanan online',
            self::PengirimanKelola => 'Mengelola kurir dan status pengiriman pesanan',
            self::ApotekObatKerasJual => 'Menyerahkan obat keras, psikotropika, narkotika, dan Obat Wajib Apotek; memvalidasi resep (Apoteker)',
            self::ApotekResepLihat => 'Melihat resep penjualan beserta data pasien (laporan apotek)',
        };
    }

    /** Kelompok tampilan di halaman peran (mengikuti menu back-office §17.6.5). */
    public function AmbilKelompok(): string
    {
        return match ($this) {
            self::OutletLihat, self::OutletKelola, self::PerangkatLihat, self::PerangkatKelola, self::PanduanAwalKelola,
            self::PembayaranGerbangAtur, self::IntegrasiApiKelola => 'Organisasi',
            self::PenggunaLihat, self::PenggunaUndang, self::PenggunaUbah, self::PenggunaNonaktifkan,
            self::PeranKelola, self::AuditLihat, self::PenggunaPinAtur => 'Pengguna & keamanan',
            self::ProdukLihat, self::ProdukKelola, self::ProdukHargaUbah => 'Produk',
            self::PersediaanLihat, self::PersediaanKelola, self::PersediaanPenyesuaianSetujui, self::PersediaanStokAwalPosting, self::PersediaanTerbuangCatat,
            self::PembelianKelola, self::PembelianPoSetujui => 'Persediaan & pembelian',
            self::PenjualanBuat, self::PenjualanVoid, self::PenjualanRetur, self::PenjualanReturTanpaStruk, self::PenjualanDiskonManual, self::PosHargaTimpa, self::KasKeluarSetujui,
            self::PenjualanDiskonSetujui, self::PenjualanTempoSetujui, self::ShiftSelisihSetujui, self::PesananMejaCatat,
            self::ReservasiKelola, self::LaundryKelola, self::BengkelKelola, self::GrosirKelola, self::GrosirSetujuiKredit, self::SalesmanKunjungan,
            self::TokoOnlineKelola, self::PengirimanKelola => 'Penjualan',
            self::LaporanPenjualanLihat, self::LaporanKeuanganLihat, self::AkuntansiKelola, self::TindakanTinjau => 'Keuangan & laporan',
            self::LanggananKelola, self::DataKelola => 'Langganan',
            self::BantuanTiketLihat, self::BantuanTiketKelola, self::DukunganAksesKelola => 'Bantuan',
            self::PelangganLihat, self::PelangganKelola, self::PelangganDepositKelola, self::PelangganSesiKelola => 'Pelanggan',
            self::KaryawanLihat, self::KaryawanKelola => 'Karyawan',
            self::ApotekObatKerasJual, self::ApotekResepLihat => 'Apotek',
        };
    }

    /**
     * Izin yang hanya boleh dimiliki Owner (§19.1: Admin = semua kecuali langganan & kepemilikan). Tidak bisa
     * dimasukkan ke peran kustom.
     */
    public function CekKhususPemilik(): bool
    {
        return $this === self::LanggananKelola || $this === self::IntegrasiApiKelola || $this === self::DataKelola || $this === self::DukunganAksesKelola;
    }

    /**
     * PGL-16 / BR-P09.1: izin yang tidak pernah dimiliki sesi akses dukungan walau cakupannya "Baca & ubah". Isinya
     * hal yang menyangkut kata sandi, PIN, kredensial integrasi, siapa yang boleh masuk, dan uang langganan: petugas
     * platform tidak boleh melihat atau mengubahnya, apa pun izin yang diberikan Owner.
     */
    public function CekDilarangSaatDukungan(): bool
    {
        return match ($this) {
            self::PenggunaUndang, self::PenggunaUbah, self::PenggunaNonaktifkan, self::PenggunaPinAtur, self::PeranKelola,
            self::LanggananKelola, self::DataKelola, self::IntegrasiApiKelola, self::PembayaranGerbangAtur,
            self::PerangkatKelola, self::DukunganAksesKelola => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function AmbilSemuaKunci(): array
    {
        return array_map(fn (self $izin) => $izin->value, self::cases());
    }
}
