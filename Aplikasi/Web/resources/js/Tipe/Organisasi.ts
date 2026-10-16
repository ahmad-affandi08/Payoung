import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/** Kunci izin tenant yang dipakai layar back-office (enum IzinTenant di Backend, format D-06). */
export const IzinTenant = {
    OutletLihat: 'outlet.lihat',
    OutletKelola: 'outlet.kelola',
    PenggunaLihat: 'pengguna.lihat',
    PenggunaUndang: 'pengguna.undang',
    PenggunaUbah: 'pengguna.ubah',
    PenggunaNonaktifkan: 'pengguna.nonaktifkan',
    PeranKelola: 'peran.kelola',
    AuditLihat: 'audit.lihat',
    // F-02b perangkat POS & PIN kasir.
    PerangkatLihat: 'perangkat.lihat',
    PerangkatKelola: 'perangkat.kelola',
    PenggunaPinAtur: 'pengguna.pin.atur',
    LanggananKelola: 'langganan.kelola',
    DataKelola: 'data.kelola',
    BantuanTiketLihat: 'bantuan.tiket.lihat',
    BantuanTiketKelola: 'bantuan.tiket.kelola',
    // PGL-16: memberi & mencabut izin akses tim dukungan Payoung (khusus Owner).
    DukunganAksesKelola: 'dukungan.akses.kelola',
    // F-01 panduan awal (profil usaha, template sektor, pajak, produk awal, metode pembayaran).
    PanduanAwalKelola: 'panduan-awal.kelola',
    // F-03 master produk, harga & pajak (batas stok = persediaan.kelola, kelompok pajak = akuntansi.kelola).
    ProdukLihat: 'produk.lihat',
    ProdukKelola: 'produk.kelola',
    ProdukHargaUbah: 'produk.harga.ubah',
    PersediaanKelola: 'persediaan.kelola',
    AkuntansiKelola: 'akuntansi.kelola',
    // F-05a stok awal & buku stok (posting stok awal menulis jurnal; jurnal dilihat dengan laporan.keuangan.lihat).
    PersediaanLihat: 'persediaan.lihat',
    PersediaanStokAwalPosting: 'persediaan.stok-awal.posting',
    LaporanKeuanganLihat: 'laporan.keuangan.lihat',
    // F-06 shift & kas: daftar shift memakai laporan.penjualan.lihat, persetujuan kas keluar di POS.
    LaporanPenjualanLihat: 'laporan.penjualan.lihat',
    KasKeluarSetujui: 'kas.keluar.setujui',
    // F-04 pembelian: pemasok, PO, penerimaan, faktur, hutang, retur; persetujuan PO & pengaturan pembelian.
    PembelianKelola: 'pembelian.kelola',
    PembelianPoSetujui: 'pembelian.po.setujui',
    // F-16a pelanggan (CRM-01).
    PelangganLihat: 'pelanggan.lihat',
    PelangganKelola: 'pelanggan.kelola',
    // F-16d bagian 1: tarik/sesuaikan deposit & batal isi deposit.
    PelangganDepositKelola: 'pelanggan.deposit.kelola',
    // F-16d bagian 2: kembalikan/hanguskan sisa paket sesi.
    PelangganSesiKelola: 'pelanggan.sesi.kelola',
    // D-23 C: tandai transaksi "sudah dicek" di Kotak Tindakan.
    TindakanTinjau: 'tindakan.tinjau',
    ReservasiKelola: 'reservasi.kelola',
    LaundryKelola: 'laundry.kelola',
    // Bengkel (§9.10): perintah kerja, kendaraan pelanggan, persetujuan estimasi, servis berkala.
    BengkelKelola: 'bengkel.kelola',
    PersediaanTerbuangCatat: 'persediaan.terbuang.catat',
    KaryawanLihat: 'karyawan.lihat',
    KaryawanKelola: 'karyawan.kelola',
    // F-08 v2.06: gerbang pembayaran QRIS dinamis milik tenant (akun merchant sendiri).
    PembayaranGerbangAtur: 'pembayaran.gerbang.atur',
    // X7 Open API v1: token API publik (khusus Owner).
    IntegrasiApiKelola: 'integrasi.api.kelola',
    // Grosir (F-12, §9.7, D-32): kelola pesanan/surat jalan/faktur, dan menembus limit kredit saat konfirmasi SO.
    GrosirKelola: 'grosir.kelola',
    GrosirSetujuiKredit: 'grosir.setujui-kredit',
    InvoiceKelola: 'invoice.kelola',
    TokoOnlineKelola: 'toko-online.kelola',
    PengirimanKelola: 'pengiriman.kelola',
    // Apotek (§9.5): menyerahkan obat wajib resep/OWA (Apoteker) & melihat data pasien di laporan apotek.
    ApotekObatKerasJual: 'apotek.obat-keras.jual',
    ApotekResepLihat: 'apotek.resep.lihat',
} as const;

export type KunciIzinTenant = (typeof IzinTenant)[keyof typeof IzinTenant];

/** Hanya untuk menampilkan/menyembunyikan menu & tombol. Server tetap penentu (WajibIzinTenant). */
export function PunyaIzinTenant(akses: PropsBersamaAplikasi['Akses'], izin: KunciIzinTenant): boolean {
    // D-35: izin yang halamannya tidak ada di edisi ini (langganan & bantuan di edisi Lisensi) mati juga bagi Pemilik.
    if (akses === null || (akses.IzinNonaktif ?? []).includes(izin)) {
        return false;
    }

    return akses.Pemilik || akses.Izin.includes(izin);
}

export type Pilihan = { Nilai: string; Label: string };

export type Batas = { Batas: number | null; Terpakai: number };

export type Kota = { Kode: string; Nama: string; NamaProvinsi: string | null; ZonaWaktu: string };

export type StatusOrganisasi = 'Aktif' | 'Diarsipkan';

/** Teks pemakaian batas paket, misal "2 dari 3 outlet" atau "4 pengguna (tanpa batas)". */
export function FormatBatas(batas: Batas, objek: string): string {
    return batas.Batas === null
        ? `${String(batas.Terpakai)} ${objek} (tanpa batas)`
        : `${String(batas.Terpakai)} dari ${String(batas.Batas)} ${objek}`;
}

export function CekBatasPenuh(batas: Batas): boolean {
    return batas.Batas !== null && batas.Terpakai >= batas.Batas;
}

/** Satu izin tenant untuk formulir peran (enum IzinTenant di Backend). */
export type IzinPeran = { Kunci: string; Label: string; Kelompok: string; KhususPemilik: boolean };

/** Opsi peran & outlet untuk formulir undangan / ubah akses pengguna. */
/** Cakupan outlet yang dibawa peran (D-104): Semua (Admin/Akuntan/Pemilik), Terpilih (peran operasional), Bebas (peran kustom). */
export type CakupanOutlet = 'Semua' | 'Terpilih' | 'Bebas';
export type OpsiPeranPengguna = { Uuid: string; Nama: string; Pemilik: boolean; Cakupan: CakupanOutlet };
export type OpsiOutletPengguna = { Uuid: string; Kode: string; Nama: string };

/** Props halaman penuh "Tambah outlet". */
export type PropsBuatOutlet = { Merek: Pilihan[]; Kota: Kota[]; BatasOutlet: Batas };

/** Props halaman penuh "Buat peran". */
export type PropsBuatPeran = { DaftarIzin: IzinPeran[] };

/** Props halaman penuh "Undang pengguna" dan "Tambah pengguna" (D-22). */
export type PropsBuatUndangan = { Peran: OpsiPeranPengguna[]; Outlet: OpsiOutletPengguna[]; BatasPengguna: Batas };
