import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogKodeAktivasi from '@/Komponen/Kelola/DialogKodeAktivasi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Card } from '@/Komponen/Ui/card';
import { useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { CekBatasPenuh, FormatBatas, IzinTenant, PunyaIzinTenant, type Batas, type Pilihan } from '@/Tipe/Organisasi';
import type { KodeAktivasiBaru } from '@/Tipe/PanduanAwal';

type StatusPerangkat = 'Aktif' | 'BelumDiaktifkan' | 'Dicabut';

type Perangkat = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Jenis: string;
    LabelJenis: string;
    UuidOutlet: string | null;
    NamaOutlet: string | null;
    Status: StatusPerangkat;
    Platform: string | null;
    VersiAplikasi: string | null;
    /** v1.96: ringkasan profil hardware & hasil wizard uji perangkat. */
    PerangkatKeras?: string | null;
    DiaktifkanPada: string | null;
    TerakhirAktifPada: string | null;
    DicabutPada: string | null;
};

type Outlet = { Uuid: string; Kode: string; Nama: string; BatasPerangkat: Batas };

type PropsDaftar = {
    Perangkat: Perangkat[];
    Outlet: Outlet[];
    JenisPerangkat: Pilihan[];
    KodeAktivasiBaru: KodeAktivasiBaru | null;
};

const labelStatus: Record<StatusPerangkat, { jenis: 'sukses' | 'peringatan' | 'netral'; teks: string }> = {
    Aktif: { jenis: 'sukses', teks: 'Aktif' },
    BelumDiaktifkan: { jenis: 'peringatan', teks: 'Belum diaktifkan' },
    Dicabut: { jenis: 'netral', teks: 'Dicabut' },
};

const kolom: KolomTabel<Perangkat>[] = [
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode',
        meta: { label: 'Kode', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label text-teks-utama' },
    },
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'penting' },
        cell: ({ row: { original: baris } }) => (
            <>
                <span className="text-teks-utama">{baris.Nama}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {baris.LabelJenis}
                    {baris.Platform ? ` | ${baris.Platform}` : ''}
                    {baris.VersiAplikasi ? ` | versi ${baris.VersiAplikasi}` : ''}
                </span>
                {baris.PerangkatKeras ? (
                    <span className="block text-keterangan text-teks-sekunder">{baris.PerangkatKeras}</span>
                ) : null}
            </>
        ),
    },
    {
        id: 'NamaOutlet',
        accessorFn: (baris) => baris.NamaOutlet ?? '—',
        header: 'Outlet',
        meta: { label: 'Outlet', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={labelStatus[row.original.Status].jenis} teks={labelStatus[row.original.Status].teks} />
        ),
    },
    {
        id: 'TerakhirAktifPada',
        accessorKey: 'TerakhirAktifPada',
        header: 'Terakhir aktif',
        meta: { label: 'Terakhir aktif', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.TerakhirAktifPada),
    },
];

/** Perangkat POS per outlet: tambah, aktivasi lewat kode + QR, cabut (F-02 langkah 5, BR-02.1, BR-02.3). */
export default function HalamanDaftarPerangkat({ Perangkat, Outlet, JenisPerangkat, KodeAktivasiBaru }: PropsDaftar) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehKelola = PunyaIzinTenant(props.Akses, IzinTenant.PerangkatKelola);
    const [formTerbuka, AturFormTerbuka] = useState(false);
    const [cabut, AturCabut] = useState<Perangkat | null>(null);
    const [ubah, AturUbah] = useState<Perangkat | null>(null);
    const kodeBaru = useRahasiaSekali(KodeAktivasiBaru);

    return (
        <TataLetakAplikasi judul="Perangkat">
            <p className="text-isi text-teks-sekunder">
                Setiap aplikasi kasir, layar dapur, atau perangkat gudang didaftarkan di sini lalu diaktifkan dengan
                kode 8 karakter atau QR. Kode perangkat dipakai untuk nomor transaksi saat offline.
            </p>

            {Outlet.length > 0 ? (
                <ul className="grid grid-cols-1 gap-2 text-label sm:grid-cols-2 lg:grid-cols-4">
                    {Outlet.map((outlet) => (
                        <li key={outlet.Uuid}>
                            <Card className="gap-1 px-4 py-3 rounded-panel shadow-none">
                                <span className="text-teks-sekunder">{outlet.Nama}: </span>
                                <span className="font-semibold text-teks-utama">
                                    {FormatBatas(outlet.BatasPerangkat, 'perangkat')}
                                </span>
                            </Card>
                        </li>
                    ))}
                </ul>
            ) : null}

            {bolehKelola && Outlet.some((outlet) => CekBatasPenuh(outlet.BatasPerangkat)) ? (
                <Pemberitahuan jenis="info" judul="Batas perangkat paket tercapai di sebagian outlet">
                    Cabut perangkat yang tidak dipakai, atau{' '}
                    <AjakanTambahBatas>
                        tambah add-on perangkat di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    . Perangkat yang dicabut tidak dihitung.
                </Pemberitahuan>
            ) : null}

            {bolehKelola ? (
                <AksiHalaman>
                    <Tombol onClick={() => AturFormTerbuka(true)} disabled={Outlet.length === 0}>
                        Tambah perangkat
                    </Tombol>
                </AksiHalaman>
            ) : null}
            {bolehKelola && formTerbuka ? (
                <FormTambah outlet={Outlet} jenis={JenisPerangkat} saatSelesai={() => AturFormTerbuka(false)} />
            ) : null}

            {ubah ? <FormUbahNama key={ubah.Uuid} perangkat={ubah} saatSelesai={() => AturUbah(null)} /> : null}
            {cabut ? <KonfirmasiCabut perangkat={cabut} saatSelesai={() => AturCabut(null)} /> : null}

            <TabelData
                id="organisasi-perangkat"
                label="Daftar perangkat"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Perangkat }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="Kode"
                cari="Cari kode atau nama perangkat"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: Object.entries(labelStatus).map(([nilai, label]) => ({ nilai, label: label.teks })),
                    },
                    {
                        id: 'NamaOutlet',
                        label: 'Outlet',
                        jenis: 'pilihanBanyak',
                        opsi: Outlet.map((outlet) => ({ nilai: outlet.Nama, label: outlet.Nama })),
                    },
                ]}
                labelBaris={(baris) => `perangkat ${baris.Nama} (${baris.Kode})`}
                aksiBaris={(baris) =>
                    bolehKelola && baris.Status !== 'Dicabut' ? (
                        <ItemAksiBaris
                            aksi={[
                                { label: 'Ubah nama', saatPilih: () => AturUbah(baris) },
                                {
                                    label: baris.Status === 'Aktif' ? 'Pindahkan ke HP lain' : 'Buat kode baru',
                                    saatPilih: () =>
                                        router.post(
                                            `/kelola/perangkat/${baris.Uuid}/kode-aktivasi`,
                                            {},
                                            { preserveScroll: true },
                                        ),
                                },
                                { label: 'Cabut', bahaya: true, saatPilih: () => AturCabut(baris) },
                            ]}
                        />
                    ) : null
                }
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada perangkat. Tambahkan perangkat kasir pertama untuk mulai berjualan.',
                }}
            />
            {kodeBaru.aktif ? <DialogKodeAktivasi kode={kodeBaru.aktif} saatSelesai={kodeBaru.selesai} /> : null}
        </TataLetakAplikasi>
    );
}

type PropsFormTambah = { outlet: Outlet[]; jenis: Pilihan[]; saatSelesai: () => void };

function FormTambah({ outlet, jenis, saatSelesai }: PropsFormTambah) {
    const formulir = useForm({ Nama: '', Outlet: '', Jenis: jenis[0]?.Nilai ?? 'Kasir' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/perangkat', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul="Tambah perangkat" saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangOutlet
                    nilai={formulir.data.Outlet}
                    opsi={outlet.map((baris) => ({ Nilai: baris.Uuid, Label: `${baris.Nama} (${baris.Kode})` }))}
                    saatBerubah={(nilai) => formulir.setData('Outlet', nilai)}
                    galat={formulir.errors.Outlet}
                />
                <BidangTeks
                    label="Nama perangkat"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    keterangan='Misal "Kasir Depan" atau "Tablet Dapur"'
                    maxLength={100}
                    autoFocus
                    required
                />
                <BidangPilihan
                    label="Jenis"
                    nilai={formulir.data.Jenis}
                    opsi={jenis}
                    saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                    galat={formulir.errors.Jenis}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Tambah & buat kode aktivasi
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function FormUbahNama({ perangkat, saatSelesai }: { perangkat: Perangkat; saatSelesai: () => void }) {
    const formulir = useForm({ Nama: perangkat.Nama });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`/kelola/perangkat/${perangkat.Uuid}`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul={`Ubah nama ${perangkat.Nama}`} saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label={`Nama perangkat ${perangkat.Kode}`}
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    maxLength={100}
                    autoFocus
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan nama
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function KonfirmasiCabut({ perangkat, saatSelesai }: { perangkat: Perangkat; saatSelesai: () => void }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Cabut ${perangkat.Nama} (${perangkat.Kode})?`}
            labelAksi="Cabut perangkat"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.post(
                    `/kelola/perangkat/${perangkat.Uuid}/cabut`,
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
                Aplikasi di perangkat ini langsung tidak bisa dipakai. Transaksi offline yang sudah dibuat sebelumnya
                tetap diterima begitu tersambung internet, lalu ditandai untuk diperiksa. Pencabutan tidak bisa
                dibatalkan; kode {perangkat.Kode} tidak akan dipakai lagi.
            </p>
        </DialogKonfirmasi>
    );
}
