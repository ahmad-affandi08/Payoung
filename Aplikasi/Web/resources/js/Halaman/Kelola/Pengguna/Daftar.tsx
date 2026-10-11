import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Tombol from '@/Komponen/Formulir/Tombol';
import FormAksesPengguna from '@/Komponen/Kelola/FormAksesPengguna';
import FormPin from '@/Komponen/Kelola/FormPin';
import TabPengguna from '@/Komponen/Kelola/TabPengguna';
import AksiMassalPengguna from '@/Komponen/Organisasi/AksiMassalPengguna';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris, type AksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import {
    CekBatasPenuh,
    FormatBatas,
    IzinTenant,
    PunyaIzinTenant,
    type Batas,
    type OpsiOutletPengguna,
    type OpsiPeranPengguna,
} from '@/Tipe/Organisasi';

type Anggota = {
    Uuid: string;
    Nama: string;
    /** null = karyawan hanya kasir (masuk aplikasi kasir dengan PIN, D-22). */
    Email: string | null;
    Pemilik: boolean;
    UuidPeran: string | null;
    NamaPeran: string | null;
    SemuaOutlet: boolean;
    UuidOutlet: string[];
    Status: 'Aktif' | 'Nonaktif';
    DinonaktifkanPada: string | null;
    /** D-46: karyawan yang tertaut ke akun ini (null = belum dicatat sebagai karyawan). */
    UuidKaryawan: string | null;
    StatusKaryawan: 'Aktif' | 'Nonaktif' | null;
};

type Undangan = {
    Uuid: string;
    Email: string;
    NamaPeran: string | null;
    SemuaOutlet: boolean;
    JumlahOutlet: number;
    BerlakuSampai: string;
    Pengundang: string;
};

type PropsDaftar = {
    Anggota: Anggota[];
    Undangan: Undangan[];
    Peran: OpsiPeranPengguna[];
    Outlet: OpsiOutletPengguna[];
    BatasPengguna: Batas;
    UuidSaya: string;
    BolehCatatKaryawan?: boolean;
};

type Pilihan = { jenis: 'akses' | 'nonaktifkan' | 'pin'; anggota: Anggota } | null;

/** Kolom daftar pengguna; kode outlet & akun sendiri dari props halaman. */
function BuatKolom(namaOutlet: Map<string, string>, uuidSaya: string): KolomTabel<Anggota>[] {
    return [
        {
            id: 'Nama',
            accessorFn: (anggota) => `${anggota.Nama} ${anggota.Email ?? 'kasir'}`,
            header: 'Nama',
            meta: { label: 'Nama', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: anggota } }) => (
                <>
                    <span className="block font-semibold text-teks-utama">
                        {anggota.Nama}
                        {anggota.Uuid === uuidSaya ? (
                            <span className="font-normal text-teks-sekunder"> (Anda)</span>
                        ) : null}
                    </span>
                    <span className="block text-keterangan break-all text-teks-sekunder">
                        {anggota.Email ?? 'Hanya kasir (masuk dengan PIN)'}
                    </span>
                </>
            ),
        },
        {
            id: 'NamaPeran',
            accessorFn: (anggota) => anggota.NamaPeran ?? 'Belum ada peran',
            header: 'Peran',
            meta: { label: 'Peran', prioritas: 'penting' },
        },
        {
            id: 'Outlet',
            header: 'Outlet',
            enableSorting: false,
            meta: { label: 'Outlet', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: anggota } }) =>
                anggota.SemuaOutlet
                    ? 'Semua outlet'
                    : anggota.UuidOutlet.map((uuid) => namaOutlet.get(uuid) ?? 'Diarsipkan').join(', ') ||
                      'Belum ditugaskan',
        },
        {
            id: 'Karyawan',
            accessorFn: (anggota) => anggota.StatusKaryawan ?? 'Bukan karyawan',
            header: 'Karyawan',
            enableSorting: false,
            meta: { label: 'Karyawan', prioritas: 'rendah' },
            cell: ({ row: { original: anggota } }) =>
                anggota.UuidKaryawan === null ? (
                    <span className="text-teks-sekunder">Bukan karyawan</span>
                ) : (
                    <Link href="/kelola/karyawan" className="text-brand underline">
                        {anggota.StatusKaryawan === 'Nonaktif' ? 'Karyawan (nonaktif)' : 'Karyawan'}
                    </Link>
                ),
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row: { original: anggota } }) =>
                anggota.Status === 'Aktif' ? (
                    <LabelStatus jenis="sukses" teks="Aktif" />
                ) : (
                    <LabelStatus
                        jenis="netral"
                        teks={`Nonaktif sejak ${FormatTanggalWaktu(anggota.DinonaktifkanPada)}`}
                    />
                ),
        },
    ];
}

const kolomUndangan: KolomTabel<Undangan>[] = [
    {
        id: 'Email',
        accessorKey: 'Email',
        header: 'Email',
        meta: { label: 'Email', prioritas: 'utama', wajib: true, kelasSel: 'break-all font-semibold text-teks-utama' },
    },
    {
        id: 'NamaPeran',
        accessorFn: (undangan) => undangan.NamaPeran ?? '—',
        header: 'Peran',
        meta: { label: 'Peran', prioritas: 'penting' },
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: undangan } }) =>
            undangan.SemuaOutlet ? 'Semua outlet' : `${String(undangan.JumlahOutlet)} outlet`,
    },
    {
        id: 'Pengundang',
        accessorKey: 'Pengundang',
        header: 'Diundang oleh',
        meta: { label: 'Diundang oleh', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'BerlakuSampai',
        accessorKey: 'BerlakuSampai',
        header: 'Berlaku sampai',
        meta: { label: 'Berlaku sampai', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.BerlakuSampai),
    },
];

/** Pengguna tenant: undang, atur peran & outlet, nonaktifkan (F-02 langkah 3, BR-02.1, BR-00.1). */
export default function HalamanDaftarPengguna({
    Anggota,
    Undangan,
    Peran,
    Outlet,
    BatasPengguna,
    UuidSaya,
    BolehCatatKaryawan = false,
}: PropsDaftar) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const akses = props.Akses;
    const bolehUndang = PunyaIzinTenant(akses, IzinTenant.PenggunaUndang);
    const bolehUbah = PunyaIzinTenant(akses, IzinTenant.PenggunaUbah);
    const bolehNonaktifkan = PunyaIzinTenant(akses, IzinTenant.PenggunaNonaktifkan);
    const bolehAturPin = PunyaIzinTenant(akses, IzinTenant.PenggunaPinAtur);
    const sayaPemilik = akses?.Pemilik ?? false;
    const [pilihan, AturPilihan] = useState<Pilihan>(null);
    const penuh = CekBatasPenuh(BatasPengguna);
    const peranTerlihat = Peran.filter((peran) => sayaPemilik || !peran.Pemilik);
    const kolom = useMemo(
        () => BuatKolom(new Map(Outlet.map((outlet) => [outlet.Uuid, outlet.Kode])), UuidSaya),
        [Outlet, UuidSaya],
    );

    // Pemilik hanya bisa diubah Pemilik lain; akun sendiri tidak bisa diubah dari sini.
    const BolehSentuh = (anggota: Anggota) => anggota.Uuid !== UuidSaya && (sayaPemilik || !anggota.Pemilik);

    const SusunAksi = (anggota: Anggota): AksiBaris[] => {
        if (!BolehSentuh(anggota)) {
            return [];
        }

        const aksi: AksiBaris[] = [];
        if (anggota.Status === 'Aktif' && anggota.UuidKaryawan === null && BolehCatatKaryawan) {
            aksi.push({
                label: 'Catat sebagai karyawan',
                saatPilih: () => router.post(`/kelola/pengguna/${anggota.Uuid}/karyawan`, {}, { preserveScroll: true }),
            });
        }
        if (anggota.Status === 'Aktif' && bolehUbah) {
            aksi.push({ label: 'Ubah akses', saatPilih: () => AturPilihan({ jenis: 'akses', anggota }) });
        }
        // Kasir yang lupa PIN: atur PIN baru dari sini (sebelumnya hanya lewat Keamanan akun › PIN kasir).
        if (anggota.Status === 'Aktif' && bolehAturPin) {
            aksi.push({ label: 'Atur ulang PIN', saatPilih: () => AturPilihan({ jenis: 'pin', anggota }) });
        }
        if (anggota.Status === 'Aktif' && bolehNonaktifkan) {
            aksi.push({
                label: 'Nonaktifkan',
                bahaya: true,
                saatPilih: () => AturPilihan({ jenis: 'nonaktifkan', anggota }),
            });
        }
        if (anggota.Status === 'Nonaktif' && bolehNonaktifkan) {
            aksi.push({
                label: 'Aktifkan kembali',
                nonaktif: penuh,
                saatPilih: () => router.post(`/kelola/pengguna/${anggota.Uuid}/aktifkan`, {}, { preserveScroll: true }),
            });
        }

        return aksi;
    };

    return (
        <TataLetakAplikasi judul="Pengguna & peran">
            <Pemberitahuan jenis="info" judul="Pengguna dan karyawan kini digabung di menu Tim">
                Satu daftar, satu formulir tambah, dan nonaktif sekaligus untuk akun &amp; jadwalnya.{' '}
                <Link href="/kelola/tim" className="font-semibold text-brand underline">
                    Buka Tim
                </Link>
                .
            </Pemberitahuan>
            <TabPengguna />
            <AksiHalaman
                keterangan={
                    <p className="text-isi text-teks-sekunder">
                        Kursi pengguna (anggota aktif + undangan menunggu):{' '}
                        <span className="font-semibold text-teks-utama">{FormatBatas(BatasPengguna, 'pengguna')}</span>
                    </p>
                }
            >
                {/* Audit kemudahan pakai #34: satu pintu "Tambah staf"; undangan email ada di halaman itu. */}
                {bolehUndang && penuh ? <Tombol disabled>Tambah staf</Tombol> : null}
                {bolehUndang && !penuh ? (
                    <Button asChild>
                        <Link href="/kelola/pengguna/buat">Tambah staf</Link>
                    </Button>
                ) : null}
            </AksiHalaman>

            {bolehUndang && penuh ? (
                <Pemberitahuan jenis="info" judul="Batas pengguna paket sudah tercapai">
                    Nonaktifkan pengguna yang tidak dipakai, batalkan undangan, atau{' '}
                    <AjakanTambahBatas>
                        tingkatkan paket di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    .
                </Pemberitahuan>
            ) : null}

            {pilihan?.jenis === 'akses' ? (
                <DialogFormulir
                    jenis="panel"
                    judul={`Peran & akses ${pilihan.anggota.Nama}`}
                    saatTutup={() => AturPilihan(null)}
                >
                    <FormAksesPengguna
                        key={pilihan.anggota.Uuid}
                        alamat={`/kelola/pengguna/${pilihan.anggota.Uuid}/akses`}
                        metode="put"
                        awal={{
                            Email: pilihan.anggota.Email ?? '',
                            Peran: pilihan.anggota.UuidPeran ?? '',
                            SemuaOutlet: pilihan.anggota.SemuaOutlet,
                            Outlet: pilihan.anggota.UuidOutlet,
                        }}
                        peran={peranTerlihat}
                        outlet={Outlet}
                        tombol="Simpan akses"
                        saatSelesai={() => AturPilihan(null)}
                        saatBatal={() => AturPilihan(null)}
                    />
                </DialogFormulir>
            ) : null}
            {pilihan?.jenis === 'pin' ? (
                <DialogFormulir
                    judul={`Atur ulang PIN ${pilihan.anggota.Nama}`}
                    keterangan="PIN lama tidak bisa dilihat. Isi PIN baru 6 angka, lalu beritahukan langsung kepada yang bersangkutan. PIN baru berlaku di perangkat kasir setelah perangkat tersinkron."
                    saatTutup={() => AturPilihan(null)}
                >
                    <FormPin
                        key={pilihan.anggota.Uuid}
                        alamat={`/kelola/pengguna/${pilihan.anggota.Uuid}/pin`}
                        labelTombol="Simpan PIN baru"
                        saatSelesai={() => AturPilihan(null)}
                    />
                </DialogFormulir>
            ) : null}
            {pilihan?.jenis === 'nonaktifkan' ? (
                <KonfirmasiNonaktifkan
                    key={pilihan.anggota.Uuid}
                    anggota={pilihan.anggota}
                    saatSelesai={() => AturPilihan(null)}
                />
            ) : null}

            <TabelData
                id="organisasi-pengguna"
                label="Daftar pengguna"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Anggota }}
                ambilIdBaris={(anggota) => anggota.Uuid}
                cari="Cari nama atau email"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Nonaktif', label: 'Nonaktif' },
                        ],
                    },
                ]}
                labelBaris={(anggota) => `untuk ${anggota.Nama}`}
                {...(bolehUbah || bolehNonaktifkan
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<Anggota>) => (
                              <AksiMassalPengguna
                                  konteks={konteks}
                                  peran={peranTerlihat}
                                  bolehSentuh={BolehSentuh}
                                  bolehUbah={bolehUbah}
                                  bolehNonaktifkan={bolehNonaktifkan}
                              />
                          ),
                      }
                    : {})}
                aksiBaris={(anggota) => {
                    const aksi = SusunAksi(anggota);

                    return aksi.length === 0 ? null : <ItemAksiBaris aksi={aksi} />;
                }}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada pengguna lain. Tambahkan kasir atau staf agar tim bisa ikut bekerja.',
                }}
            />

            <section className="flex flex-col gap-2" aria-labelledby="judul-undangan">
                <h2 id="judul-undangan" className="text-subjudul font-semibold text-teks-utama">
                    Undangan menunggu
                </h2>
                <TabelData
                    id="organisasi-undangan"
                    label="Undangan menunggu"
                    kolom={kolomUndangan}
                    sumber={{ mode: 'lokal', data: Undangan }}
                    ambilIdBaris={(undangan) => undangan.Uuid}
                    labelBaris={(undangan) => `undangan ${undangan.Email}`}
                    {...(bolehUndang
                        ? {
                              aksiBaris: (undangan: Undangan) => (
                                  <DropdownMenuItem
                                      variant="destructive"
                                      onSelect={() =>
                                          router.post(
                                              `/kelola/pengguna/undangan/${undangan.Uuid}/batalkan`,
                                              {},
                                              { preserveScroll: true },
                                          )
                                      }
                                  >
                                      Batalkan undangan
                                  </DropdownMenuItem>
                              ),
                          }
                        : {})}
                    kosong={{ ilustrasi: true, judul: 'Tidak ada undangan yang menunggu diterima.' }}
                />
            </section>
        </TataLetakAplikasi>
    );
}

function KonfirmasiNonaktifkan({ anggota, saatSelesai }: { anggota: Anggota; saatSelesai: () => void }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Nonaktifkan ${anggota.Nama}?`}
            labelAksi="Nonaktifkan pengguna"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.post(
                    `/kelola/pengguna/${anggota.Uuid}/nonaktifkan`,
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => AturMemproses(true),
                        onFinish: () => AturMemproses(false),
                        onSuccess: saatSelesai,
                    },
                )
            }
        >
            <p>
                {anggota.Nama} langsung keluar dari usaha ini dan tidak bisa memilihnya lagi. Akun & riwayatnya tetap
                tersimpan, dan bisa diaktifkan kembali kapan saja. Undangan yang ia kirim ikut dibatalkan.
            </p>
        </DialogKonfirmasi>
    );
}
