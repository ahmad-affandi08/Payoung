/**
 * Pabrik key TanStack Query terpusat (PRD §17.4.2). Semua kueri wajib memakai key dari sini
 * agar invalidasi setelah mutasi Inertia konsisten.
 */
export const KunciKueri = {
    // D-16: data TabelData mode server, per tabel & query URL ternormalisasi.
    Tabel: (id: string, alamat: string, query: string) => ['Tabel', id, alamat, query] as const,
    TabelSemua: (id: string) => ['Tabel', id] as const,
    Perangkat: (idOutlet: string) => ['Perangkat', idOutlet] as const,
    // Pencarian cepat di kepala halaman: hasil per sumber (produk, pelanggan, …) untuk satu kata.
    PencarianCepat: (idSumber: string, kata: string) => ['PencarianCepat', idSumber, kata] as const,
    Laporan: (nama: string, saring: Record<string, string>) => ['Laporan', nama, saring] as const,
    // PLT-12: bantuan kontekstual di kepala back-office (artikel Pusat Bantuan untuk satu jalur + kata cari).
    BantuanKontekstual: (jalur: string, kata: string) => ['BantuanKontekstual', jalur, kata] as const,
    // PLT-12 (D-163): obrolan langsung dengan tim dukungan (keadaan layanan + percakapan pelapor) dan inbox petugas.
    Obrolan: ['Obrolan'] as const,
    ObrolanInbox: ['ObrolanInbox'] as const,
    ObrolanPesan: (uuid: string) => ['ObrolanPesan', uuid] as const,
    // PRD v3.14: pemilih pelanggan di formulir pesanan grosir (dropdown cari-server).
    Grosir: {
        CariPelanggan: (kata: string) => ['Grosir', 'CariPelanggan', kata] as const,
    },
    // F-21: pemilih jasa/produk tanpa stok di baris invoice.
    Invoice: {
        CariJasa: (kata: string) => ['Invoice', 'CariJasa', kata] as const,
        UangMukaTersedia: (uuidPelanggan: string, untuk: string) =>
            ['Invoice', 'UangMuka', uuidPelanggan, untuk] as const,
    },
    // TAX-07: pemilih pembeli Faktur Pajak per transaksi kasir.
    Penjualan: {
        CariPembeliFakturPajak: (kata: string) => ['Penjualan', 'CariPembeliFakturPajak', kata] as const,
    },
    // PRD v3.12: kesiapan ekspor Faktur Pajak Keluaran Coretax per periode & outlet.
    FakturPajakCoretax: (saring: Record<string, string>) => ['FakturPajakCoretax', saring] as const,
    NotaReturPajak: (saring: Record<string, string>) => ['NotaReturPajak', saring] as const,
    // CRM-07: pratinjau jumlah penerima kampanye pesan untuk kanal & saringan segmen yang sedang diisi.
    PratinjauKampanye: (kanal: string, segmen: string) => ['PratinjauKampanye', kanal, segmen] as const,
    // F-03: pemilih bahan/komponen (GET /kelola/produk/cari) dan polling status impor.
    Produk: {
        Cari: (kata: string, jenis: readonly string[]) => ['Produk', 'Cari', kata, [...jenis]] as const,
        // PRD-10: pencarian baris label (satuan produk beserta barcode & harga).
        CariLabel: (kata: string) => ['Produk', 'CariLabel', kata] as const,
    },
    Impor: {
        Status: (uuid: string) => ['Impor', 'Status', uuid] as const,
    },
    // F-18 bagian 4 (D-37): panel absen dari HP (wajah terdaftar) satu karyawan.
    Karyawan: {
        AbsenHp: (uuid: string) => ['Karyawan', 'AbsenHp', uuid] as const,
        // K37: sebaran kemiripan wajah absensi web (kalibrasi ambang).
        KalibrasiWajah: () => ['Karyawan', 'KalibrasiWajah'] as const,
    },
    // CRM-06: riwayat buku satu gift card di dialog rincian.
    GiftCard: {
        Rincian: (uuid: string) => ['GiftCard', 'Rincian', uuid] as const,
    },
    // PGL-16: jejak permintaan satu sesi akses dukungan (Pengaturan › Akses dukungan).
    Dukungan: {
        AktivitasSesi: (uuid: string) => ['Dukungan', 'AktivitasSesi', uuid] as const,
        // PGL-16 bagian 2: alat bantu di konsol (status perangkat, log, daftar kirim ulang).
        AlatPerangkat: (uuidTenant: string) => ['Dukungan', 'AlatPerangkat', uuidTenant] as const,
        AlatKirimUlang: (uuidTenant: string) => ['Dukungan', 'AlatKirimUlang', uuidTenant] as const,
        AlatLog: (uuidTenant: string, uuidPerangkat: string) =>
            ['Dukungan', 'AlatLog', uuidTenant, uuidPerangkat] as const,
    },
    // F-05a: pemilih produk stok awal (GET /kelola/persediaan/produk/cari), polling status posting & impor stok awal.
    Persediaan: {
        CariProduk: (kata: string, uuidGudang: string | null) =>
            ['Persediaan', 'CariProduk', kata, uuidGudang] as const,
        StatusPosting: (uuid: string) => ['Persediaan', 'StatusPosting', uuid] as const,
        StatusImpor: (uuid: string) => ['Persediaan', 'StatusImpor', uuid] as const,
        // F-05b: batch & nomor seri tersedia per produk & lokasi (GET /kelola/persediaan/pelacakan).
        Pelacakan: (uuidProduk: string, uuidGudang: string) =>
            ['Persediaan', 'Pelacakan', uuidProduk, uuidGudang] as const,
    },
    // F-17 Self-Order QR Meja: harga keranjang dari server (web publik tidak menghitung harga), polling status pesanan
    // tamu, dan QR meja di back-office.
    PesanSendiri: {
        Hitung: (token: string, tanda: string) => ['PesanSendiri', 'Hitung', token, tanda] as const,
        Status: (token: string, uuid: string) => ['PesanSendiri', 'Status', token, uuid] as const,
        QrMeja: (uuidMeja: string) => ['PesanSendiri', 'QrMeja', uuidMeja] as const,
        Menu: (token: string) => ['PesanSendiri', 'Menu', token] as const,
        StatusQris: (token: string, kodeAkses: string) => ['PesanSendiri', 'StatusQris', token, kodeAkses] as const,
    },
    // F-17 bagian 4: kios pesan sendiri di layar sentuh outlet (menu per pilihan makan di sini/bawa pulang, harga keranjang
    // dari server, status pesanan, layar antrian).
    Kios: {
        Menu: (token: string, santap: string) => ['Kios', 'Menu', token, santap] as const,
        Hitung: (token: string, santap: string, tanda: string) => ['Kios', 'Hitung', token, santap, tanda] as const,
        Status: (token: string, kodeAkses: string) => ['Kios', 'Status', token, kodeAkses] as const,
        Antrian: (token: string) => ['Kios', 'Antrian', token] as const,
    },
    // F-07 mode service: slot reservasi kosong (back-office & halaman publik) per outlet/layanan/tanggal/staf.
    Reservasi: {
        Slot: (alamat: string, outlet: string, layanan: string, tanggal: string, staf: string) =>
            ['Reservasi', 'Slot', alamat, outlet, layanan, tanggal, staf] as const,
    },
    // Bengkel (§9.10): pemilih pelanggan, kendaraan, dan produk jasa/sparepart di formulir perintah kerja (cari-server).
    Bengkel: {
        Cari: (sumber: string, url: string) => ['Bengkel', 'Cari', sumber, url] as const,
    },
    // P-10: dampak menaikkan versi minimum (BR-P10.2), dibaca saat dialog dibuka.
    Pengelola: {
        DampakVersiMinimum: (uuidRilis: string) => ['Pengelola', 'DampakVersiMinimum', uuidRilis] as const,
    },
} as const;
