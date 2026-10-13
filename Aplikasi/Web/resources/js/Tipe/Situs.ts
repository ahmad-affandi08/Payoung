/** Situs pemasaran (D-21): props dari `PenyusunHalamanSitus`. */

export type TautanSitus = { Label: string; Tautan: string };

export type GambarSitus = {
    Uuid: string;
    Url: string;
    UrlAbsolut?: string;
    Alt: string;
    Lebar: number | null;
    Tinggi: number | null;
};

/** Sektor di menu Solusi (D-91): satu halaman per sektor usaha. */
export type SektorMenuSitus = { Nama: string; Ringkas: string; Ikon: string; Tautan: string };

/** Kolom menu Solusi: satu kelompok usaha (Kafe & Resto, Toko & Retail, Jasa) beserta sektornya. */
export type KelompokMenuSitus = { Judul: string; Tautan: string; Ikon: string; Sektor: SektorMenuSitus[] };

/** Butir menu utama; `Solusi` terisi pada butir dropdown sektor (`@solusi`). */
export type ButirMenuSitus = TautanSitus & { Solusi?: KelompokMenuSitus[] };

export type DataSitus = {
    NamaSitus: string;
    Slogan: string | null;
    Logo: GambarSitus | null;
    Menu: ButirMenuSitus[];
    MenuKaki: { Judul: string; Tautan: TautanSitus[] }[];
    TeksKaki: string | null;
    Kontak: {
        WhatsApp: string | null;
        TautanWhatsApp: string | null;
        Email: string | null;
        Telepon: string | null;
        Alamat: string | null;
        JamLayanan: string | null;
    };
    MediaSosial: Partial<Record<'Instagram' | 'Facebook' | 'Tiktok' | 'Youtube' | 'Linkedin' | 'X', string>>;
    Pengumuman: { Teks: string; Tautan: string | null } | null;
    TautanUnduh: Partial<Record<'Android' | 'Ios' | 'Windows', string>>;
    TombolDaftar: TautanSitus;
    TombolMasuk: TautanSitus;
    WhatsAppMelayang: boolean;
    Tahun: number;
    /** Bagian B: dimuat hanya setelah pengunjung menyetujui cookie analitik. */
    Analitik?: { IdGoogleAnalytics: string | null; IdMetaPixel: string | null };
};

export type Tombol = { Label: string; Tautan: string } | null;

/** Spesimen bawaan = tangkapan layar asli aplikasi atau keluaran produk (sama dengan `SkemaBagianSitus::SPESIMEN`). */
export type NamaSpesimen =
    | 'Kasir'
    | 'KasirBayar'
    | 'KasirBerhasil'
    | 'KasirShift'
    | 'KasirRiwayat'
    | 'KasirKas'
    | 'KasirHp'
    | 'Pemilik'
    | 'PemilikDuo'
    | 'PemilikLaporan'
    | 'PemilikPersetujuan'
    | 'PemilikKaryawan'
    | 'PemilikInsight'
    | 'Struk'
    | 'Jurnal';

export type SorotanHero = {
    Label: string;
    Judul: string;
    Teks: string | null;
    TombolUtama: Tombol;
    TombolKedua: Tombol;
    Gambar: GambarSitus | null;
    Spesimen: NamaSpesimen | null;
};

export type PaketHarga = {
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    HargaNegosiasi: boolean;
    MasaTrialHari: number;
    HargaBulanan: string | null;
    HargaTahunan: string | null;
    HematTahunan: string | null;
    /** Diskon peluncuran (D-86): harga normal yang pasti terbit setelah `BerlakuSampai`; null bila tidak ada. */
    Promo: {
        HargaBulananNormal: string;
        HargaTahunanNormal: string;
        BerlakuSampai: string;
        PersenDiskon: number;
        HargaTerkunci: boolean;
    } | null;
    Batas: string[];
    Fitur: string[];
};

type JudulBagian = { Label: string | null; Judul: string | null; Subjudul: string | null };

export type BagianSitus =
    | ({ Jenis: 'Hero' } & {
          Label: string | null;
          Judul: string;
          Subjudul: string | null;
          TombolUtama: Tombol;
          TombolKedua: Tombol;
          Gambar: GambarSitus | null;
          Catatan: string | null;
          /** D-39: alasan singkat untuk percaya (baris centang di bawah tombol). */
          Poin?: { Teks: string }[];
          /** D-25: latar hero. `Merek`/`Navy` memberi jangkar gelap penuh tanpa gradien. */
          Latar: 'Terang' | 'Merek' | 'Navy' | null;
          /** D-25: spesimen keluaran produk sebagai jangkar visual bila belum ada gambar. */
          Spesimen: NamaSpesimen | null;
      })
    | { Jenis: 'HeroGeser'; Sorotan: SorotanHero[]; Poin?: { Teks: string }[]; Catatan: string | null }
    | ({ Jenis: 'TabUsaha' } & JudulBagian & {
              Item: {
                  Label: string;
                  Ikon: string | null;
                  Judul: string;
                  Teks: string | null;
                  Poin: { Teks: string }[];
                  Gambar: GambarSitus | null;
                  Spesimen: NamaSpesimen | null;
                  Tombol: Tombol;
              }[];
          })
    | ({ Jenis: 'Langkah' } & JudulBagian & {
              Item: { Ikon: string | null; Judul: string; Teks: string | null }[];
              Tombol: Tombol;
          })
    | ({ Jenis: 'Integrasi' } & JudulBagian & {
              Kelompok: { Ikon: string | null; Judul: string; Teks: string | null; Item: { Nama: string }[] }[];
              Catatan: string | null;
          })
    | ({ Jenis: 'Keunggulan' } & JudulBagian & {
              Kolom: '2' | '3' | '4' | null;
              /** D-25: bentuk blok, supaya dua blok keunggulan berurutan tidak terbaca sebagai satu grid. */
              TataLetak: 'Grid' | 'Daftar' | 'Sorot' | null;
              Item: { Ikon: string | null; Judul: string; Teks: string | null }[];
          })
    | ({ Jenis: 'Sektor' } & JudulBagian & {
              Item: {
                  Ikon: string | null;
                  Nama: string;
                  Teks: string | null;
                  Tautan: string | null;
                  Gambar: GambarSitus | null;
              }[];
          })
    | ({ Jenis: 'GambarTeks' } & JudulBagian & {
              Teks: string | null;
              Poin: { Teks: string }[];
              Gambar: GambarSitus | null;
              PosisiGambar: 'Kanan' | 'Kiri' | null;
              /** D-25: dipakai bila `Gambar` kosong. */
              Spesimen: NamaSpesimen | null;
              Tombol: Tombol;
          })
    | ({ Jenis: 'Statistik' } & JudulBagian & { Item: { Angka: string; Keterangan: string }[] })
    | ({ Jenis: 'Testimoni' } & JudulBagian & {
              Item: {
                  Nama: string;
                  Usaha: string | null;
                  Kutipan: string;
                  Foto: GambarSitus | null;
                  Bintang: number | null;
              }[];
          })
    | ({ Jenis: 'Harga' } & JudulBagian & {
              TampilkanTahunan: boolean;
              PaketDisorot: string | null;
              TeksTombol: string | null;
              CatatanKaki: string | null;
              Paket: PaketHarga[];
              TautanDaftar: string;
          })
    | ({ Jenis: 'Faq' } & JudulBagian & { Item: { Pertanyaan: string; Jawaban: string }[] })
    | ({ Jenis: 'Cta' } & { Judul: string; Teks: string | null; TombolUtama: Tombol; TombolKedua: Tombol })
    | ({ Jenis: 'TeksBebas' } & JudulBagian & { Isi: string })
    | ({ Jenis: 'LogoMitra' } & JudulBagian & {
              Item: { Gambar: GambarSitus | null; Nama: string; Tautan: string | null }[];
          })
    | ({ Jenis: 'Video' } & JudulBagian & { UrlYoutube: string; IdYoutube: string | null })
    | ({ Jenis: 'UnduhAplikasi' } & JudulBagian)
    | ({ Jenis: 'Kontak' } & JudulBagian)
    | { Jenis: 'BelumLengkap'; Label: string }
    | ({ Jenis: 'FormulirProspek' } & JudulBagian & {
              JenisProspek: 'Kontak' | 'Demo' | null;
              TeksTombol: string | null;
          });

export type HalamanSitus = {
    Slug: string;
    Judul: string;
    Bagian: BagianSitus[];
    Seo: { Judul: string; Deskripsi: string };
    Pratinjau?: boolean;
    /** Editor visual (D-63): asal konsol yang boleh membingkai & mengirim isi langsung. */
    AsalEditor?: string;
};

export type PropsHalamanSitus = { Halaman: HalamanSitus; Situs: DataSitus };

/** Situs bagian B2: artikel blog. */
export type RingkasanArtikel = {
    Slug: string;
    Judul: string;
    Ringkasan: string | null;
    Kategori: string | null;
    NamaPenulis: string | null;
    Sampul: GambarSitus | null;
    DiterbitkanPada: string | null;
};

export type SeoSitus = { Judul: string; Deskripsi: string };

export type PropsBlogSitus = {
    Halaman: { Seo: SeoSitus };
    Artikel: RingkasanArtikel[];
    Kategori: string[];
    KategoriAktif: string | null;
    HalamanKe: number;
    JumlahHalaman: number;
    Situs: DataSitus;
};

export type PropsArtikelSitus = {
    Halaman: { Seo: SeoSitus };
    Artikel: RingkasanArtikel & { Isi: string; DiubahPada: string | null };
    Terkait: RingkasanArtikel[];
    Situs: DataSitus;
};

/** PLT-12: Pusat Bantuan publik. */
export type RingkasanBantuan = {
    Slug: string;
    Judul: string;
    Ringkasan: string | null;
    UrlVideo?: string | null;
};

export type PropsPusatBantuan = {
    Halaman: { Seo: SeoSitus };
    Kelompok: { Kategori: string; Artikel: RingkasanBantuan[] }[];
    Kategori: string[];
    KategoriAktif: string | null;
    Cari: string;
    Total: number;
    Situs: DataSitus;
};

export type PropsArtikelBantuan = {
    Halaman: { Seo: SeoSitus };
    Artikel: RingkasanBantuan & { Kategori: string | null; Isi: string; DiperbaruiPada: string | null };
    Terkait: RingkasanBantuan[];
    Situs: DataSitus;
};

/** X7 bagian 3: portal dokumentasi pengembang (`/pengembang`), disusun server dari spesifikasi OpenAPI. */
export type EndpointApiPublik = {
    Metode: string;
    Jalur: string;
    Ringkasan: string;
    Cakupan: string;
    Parameter: { Nama: string; Wajib: boolean }[];
};

export type PropsHalamanPengembang = {
    AlamatApi: string;
    Versi: string;
    Endpoint: EndpointApiPublik[];
    Webhook: { Peristiwa: string; Ringkasan: string }[];
    UnduhSpesifikasi: string;
};
