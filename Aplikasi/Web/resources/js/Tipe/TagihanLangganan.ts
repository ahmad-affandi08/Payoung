/** Tagihan & pembayaran langganan (P-08). Bentuk = TagihanLanggananTenant::PetakanTagihan/PetakanPembayaran. */
export type StatusTagihanLangganan =
    'Draf' | 'Terbit' | 'JatuhTempo' | 'Lunas' | 'Dibatalkan' | 'Dihapuskan' | 'Dikembalikan';

/** D-49: rincian add-on pada tagihan (satu baris prorata pada tagihan Addon, per add-on pada Perpanjangan). */
export type RincianAddonTagihan = {
    KodeAddon: string;
    NamaAddon: string;
    Jumlah: number;
    HargaBulanan: string;
    JumlahBulan: number;
    Prorata: boolean;
    HariDitagih: number | null;
    HariPeriode: number | null;
    Subtotal: string;
};

export type TagihanLangganan = {
    Uuid: string;
    Nomor: string;
    Jenis: 'Aktivasi' | 'Perpanjangan' | 'Addon' | 'NaikPaket';
    LabelJenis: string;
    Status: StatusTagihanLangganan;
    LabelStatus: string;
    /** D-112: diterbitkan Keuangan untuk paket harga negosiasi; dibayar dengan transfer, bukan lewat gerbang. */
    Manual: boolean;
    Keterangan: string | null;
    KodePaket: string;
    NamaPaket: string;
    Siklus: 'Bulanan' | 'Tahunan' | 'Kustom';
    JumlahBulan: number;
    Subtotal: string;
    /** Subtotal paket saja (Subtotal − add-on), dihitung server. */
    SubtotalPaket: string;
    RincianAddon: RincianAddonTagihan[];
    /** D-98: rincian naik paket (null untuk jenis lain). */
    NaikPaket: { NamaPaketAsal: string | null; HariDitagih: number | null; HariPeriode: number | null } | null;
    KodeKupon: string | null;
    Diskon: string;
    TarifPpn: string;
    PengaliDppPembilang: number;
    PengaliDppPenyebut: number;
    DasarPengenaanPajak: string;
    JumlahPpn: string;
    Total: string;
    TerbitPada: string;
    JatuhTempoPada: string;
    DibayarPada: string | null;
    DibatalkanPada: string | null;
    AlasanBatal: string | null;
    DihapusBukuPada: string | null;
    AlasanHapusBuku: string | null;
    DikembalikanPada: string | null;
    PeriodeMulai: string | null;
    PeriodeSelesai: string | null;
};

export type StatusPembayaranLangganan = 'Menunggu' | 'Diterima' | 'Ditolak';

export type MetodePembayaranLangganan = 'TransferManual' | 'Gateway' | 'Kupon';

export type MetodeBayarSnap = 'Qris' | 'VirtualAccount' | 'Dana' | 'ShopeePay';

/** D-159: satu cara bayar SNAP yang ditawarkan (bank untuk virtual account lewat `Kanal`). */
export type PilihanBayar = { Metode: MetodeBayarSnap; Label: string; Kanal: string | null };

/** D-159: isi halaman bayar Payoung untuk pembayaran SNAP yang masih menunggu. */
export type TampilanBayar = {
    Metode: MetodeBayarSnap;
    Tampilan: 'Qr' | 'NomorVa' | 'Alihkan';
    Label: string;
    QrSvg: string | null;
    NomorVa: string | null;
    NamaBank: string | null;
    UrlAlih: string | null;
    Jumlah: string;
    KedaluwarsaPada: string | null;
};

export type PembayaranLangganan = {
    Uuid: string;
    Metode: MetodePembayaranLangganan;
    LabelMetode: string;
    Status: StatusPembayaranLangganan;
    LabelStatus: string;
    Jumlah: string;
    TanggalTransfer: string | null;
    BankPengirim: string | null;
    NamaPengirim: string | null;
    BankTujuan: string | null;
    NomorRekeningTujuan: string | null;
    NamaFileBukti: string | null;
    MimeBukti: string | null;
    DiunggahPada: string | null;
    DiverifikasiPada: string | null;
    AlasanTolak: string | null;
    /** D-159: ada hanya untuk pembayaran SNAP yang masih menunggu. */
    TampilanBayar?: TampilanBayar | null;
};

/** Jenis label status tagihan: warna hanya penguat teks (PRD §17.6.3). */
export function JenisLabelTagihan(status: StatusTagihanLangganan): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Lunas':
            return 'sukses';
        case 'Terbit':
            return 'peringatan';
        case 'JatuhTempo':
            return 'bahaya';
        default:
            return 'netral';
    }
}

export function JenisLabelPembayaran(status: StatusPembayaranLangganan): 'sukses' | 'peringatan' | 'bahaya' {
    switch (status) {
        case 'Diterima':
            return 'sukses';
        case 'Ditolak':
            return 'bahaya';
        default:
            return 'peringatan';
    }
}

export type StatusRefundLangganan = 'Diajukan' | 'Disetujui' | 'Ditolak';

/** D-112: pengajuan refund tagihan lunas (P-08, BR-P08.2). Bentuk = DaftarTagihanPlatform::PetakanRefund. */
export type RefundLangganan = {
    Uuid: string;
    Status: StatusRefundLangganan;
    LabelStatus: string;
    Jumlah: string;
    Alasan: string;
    CatatanPengembalian: string | null;
    Pengaju: string | null;
    Pemutus: string | null;
    DiajukanPada: string | null;
    DiputuskanPada: string | null;
    AlasanTolak: string | null;
    LayananDiakhiri: boolean;
};

export function JenisLabelRefund(status: StatusRefundLangganan): 'sukses' | 'peringatan' | 'bahaya' {
    switch (status) {
        case 'Disetujui':
            return 'sukses';
        case 'Ditolak':
            return 'bahaya';
        default:
            return 'peringatan';
    }
}
