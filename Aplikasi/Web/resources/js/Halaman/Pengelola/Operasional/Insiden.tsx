import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Button } from '@/Komponen/Ui/button';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import {
    IzinPengelola,
    PunyaIzin,
    type BarisInsiden,
    type PropsBersamaPengelola,
    type PropsInsiden,
    type StatusInsiden,
    type TingkatInsiden,
} from '@/Tipe/Pengelola';

const alamat = '/operasional/insiden';

const JenisTingkat: Record<TingkatInsiden, 'netral' | 'peringatan' | 'bahaya'> = {
    Kecil: 'netral',
    Besar: 'peringatan',
    Kritis: 'bahaya',
};

/** Status yang boleh dipilih dari status sekarang (selaras `StatusInsiden::BisaBerubahKe` di server). */
export function StatusBerikut(asal: StatusInsiden): StatusInsiden[] {
    switch (asal) {
        case 'Diselidiki':
            return ['Diselidiki', 'Teridentifikasi', 'Dipantau', 'Selesai'];
        case 'Teridentifikasi':
            return ['Teridentifikasi', 'Dipantau', 'Selesai'];
        case 'Dipantau':
            return ['Dipantau', 'Teridentifikasi', 'Selesai'];
        case 'Selesai':
            return [];
    }
}

type Dialog = { jenis: 'buka' } | { jenis: 'kronologi' | 'pembaruan' | 'post-mortem'; insiden: BarisInsiden };

/**
 * P-11 PGL-21 manajemen insiden layanan: catat insiden (tingkat, dampak, komponen, perkiraan % tenant), perbarui
 * kronologi, umumkan ke halaman status publik, dan tulis post-mortem setelah selesai. Insiden yang berdampak di atas
 * ambang % tenant aktif selalu langsung diumumkan (BR-P11.2).
 */
export default function HalamanInsiden(props: PropsInsiden) {
    const { props: bersama } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(bersama.Pengguna, IzinPengelola.OperasionalKelola);
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const Tutup = () => AturDialog(null);

    const kolom: KolomTabel<BarisInsiden>[] = [
        {
            id: 'Judul',
            accessorKey: 'Judul',
            header: 'Insiden',
            meta: { label: 'Insiden', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: i } }) => (
                <span className="flex flex-col">
                    <span className="font-semibold break-words text-teks-utama">{i.Judul}</span>
                    <span className="text-label text-teks-sekunder">{i.LabelKomponen.join(', ')}</span>
                </span>
            ),
        },
        {
            id: 'Tingkat',
            accessorKey: 'Tingkat',
            header: 'Tingkat',
            meta: { label: 'Tingkat', prioritas: 'utama' },
            cell: ({ row: { original: i } }) => <LabelStatus jenis={JenisTingkat[i.Tingkat]} teks={i.Tingkat} />,
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row: { original: i } }) => (
                <LabelStatus jenis={i.Status === 'Selesai' ? 'sukses' : 'peringatan'} teks={i.LabelStatus} />
            ),
        },
        {
            id: 'Publik',
            header: 'Halaman status',
            enableSorting: false,
            meta: { label: 'Halaman status', prioritas: 'penting' },
            cell: ({ row: { original: i } }) => (i.DiumumkanPada === null ? 'Internal' : 'Tampil di publik'),
        },
        {
            id: 'MulaiPada',
            accessorKey: 'MulaiPada',
            header: 'Mulai',
            meta: { label: 'Mulai', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: i } }) => FormatTanggalWaktu(i.MulaiPada),
        },
        {
            id: 'Persen',
            accessorKey: 'PersenTenantTerdampak',
            header: 'Tenant terdampak',
            meta: { label: 'Tenant terdampak', prioritas: 'rendah', angka: true },
            cell: ({ row: { original: i } }) =>
                i.PersenTenantTerdampak === null ? '—' : `${String(i.PersenTenantTerdampak)}%`,
        },
    ];

    const Umumkan = (i: BarisInsiden) =>
        router.post(
            `${alamat}/${i.Uuid}/umumkan`,
            {},
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakPengelola judul="Operasional">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Catat gangguan layanan di sini. Insiden yang diumumkan tampil di halaman status publik{' '}
                <Link href="/operasional" className="underline">
                    (kembali ke dasbor operasional)
                </Link>
                . Insiden yang berdampak di atas {String(props.AmbangWajibUmumPersen)}% tenant aktif wajib diumumkan
                dalam 15 menit, jadi sistem langsung mengumumkannya.
            </p>
            <AksiHalaman>
                <Button asChild variant="outline">
                    <a href="/status" target="_blank" rel="noreferrer">
                        Lihat halaman status
                    </a>
                </Button>
                {bolehKelola ? (
                    <Tombol memproses={memproses} onClick={() => AturDialog({ jenis: 'buka' })}>
                        Catat insiden
                    </Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-insiden"
                label="Insiden layanan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: props.Insiden }}
                ambilIdBaris={(i) => i.Uuid}
                labelBaris={(i) => i.Judul}
                cari="Cari judul insiden"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: props.OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Tingkat',
                        label: 'Tingkat',
                        jenis: 'pilihanBanyak',
                        opsi: props.OpsiTingkat.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                ]}
                aksiBaris={(i: BarisInsiden) => (
                    <>
                        <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'kronologi', insiden: i })}>
                            Lihat kronologi
                        </DropdownMenuItem>
                        {bolehKelola && i.Status !== 'Selesai' ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'pembaruan', insiden: i })}>
                                Tambah pembaruan
                            </DropdownMenuItem>
                        ) : null}
                        {bolehKelola && i.DiumumkanPada === null ? (
                            <DropdownMenuItem onSelect={() => Umumkan(i)}>Umumkan ke halaman status</DropdownMenuItem>
                        ) : null}
                        {bolehKelola && i.Status === 'Selesai' && i.PostMortemDiterbitkanPada === null ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'post-mortem', insiden: i })}>
                                {i.PostMortem === null ? 'Tulis post-mortem' : 'Ubah post-mortem'}
                            </DropdownMenuItem>
                        ) : null}
                    </>
                )}
                kosong={{ ilustrasi: true, judul: 'Belum ada insiden tercatat.' }}
            />
            {dialog?.jenis === 'buka' ? <FormBuka opsi={props} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'kronologi' ? <Kronologi insiden={dialog.insiden} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'pembaruan' ? <FormPembaruan insiden={dialog.insiden} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'post-mortem' ? <FormPostMortem insiden={dialog.insiden} saatSelesai={Tutup} /> : null}
        </TataLetakPengelola>
    );
}

function FormBuka({ opsi, saatSelesai }: { opsi: PropsInsiden; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const galat = props.errors;
    const [judul, AturJudul] = useState('');
    const [tingkat, AturTingkat] = useState<TingkatInsiden>('Kecil');
    const [dampak, AturDampak] = useState('');
    const [komponen, AturKomponen] = useState<string[]>([]);
    const [persen, AturPersen] = useState('');
    const [diumumkan, AturDiumumkan] = useState(true);
    const [kronologi, AturKronologi] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const persenAngka = persen.trim() === '' ? null : Number(persen);
    const wajibUmum = persenAngka !== null && persenAngka > opsi.AmbangWajibUmumPersen;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            alamat,
            {
                Judul: judul,
                Tingkat: tingkat,
                Dampak: dampak,
                Komponen: komponen,
                PersenTenantTerdampak: persenAngka,
                Diumumkan: diumumkan || wajibUmum,
                KronologiAwal: kronologi,
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatSelesai,
            },
        );
    };

    return (
        <DialogFormulir
            judul="Catat insiden"
            keterangan="Tulis dari sudut pandang pengguna, tanpa istilah teknis internal."
            jenis="panel"
            lebar="lebar"
            galatUmum={galat.Umum}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <div className="sm:col-span-2">
                    <BidangTeks label="Judul" nilai={judul} saatBerubah={AturJudul} galat={galat.Judul} required />
                </div>
                <BidangPilihan
                    label="Tingkat"
                    nilai={tingkat}
                    opsi={opsi.OpsiTingkat}
                    saatBerubah={(n) => AturTingkat(n as TingkatInsiden)}
                    galat={galat.Tingkat}
                    required
                />
                <BidangTeks
                    label="Perkiraan tenant aktif terdampak (%)"
                    nilai={persen}
                    saatBerubah={AturPersen}
                    galat={galat.PersenTenantTerdampak}
                    keterangan={`Di atas ${String(opsi.AmbangWajibUmumPersen)}% wajib diumumkan.`}
                    inputMode="numeric"
                />
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Dampak bagi pengguna"
                        nilai={dampak}
                        saatBerubah={AturDampak}
                        galat={galat.Dampak}
                        baris={3}
                        maksimal={1000}
                        required
                    />
                </div>
                <div className="sm:col-span-2">
                    <GrupCentang
                        legenda="Komponen yang terdampak"
                        opsi={opsi.OpsiKomponen.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                        terpilih={komponen}
                        saatBerubah={AturKomponen}
                        galat={galat.Komponen}
                        required
                    />
                </div>
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Kronologi awal"
                        nilai={kronologi}
                        saatBerubah={AturKronologi}
                        galat={galat.KronologiAwal}
                        baris={3}
                        maksimal={1000}
                        required
                    />
                </div>
                <div className="sm:col-span-2">
                    <KotakCentang
                        label={
                            wajibUmum
                                ? 'Tampilkan di halaman status (wajib: dampak di atas ambang)'
                                : 'Tampilkan di halaman status publik'
                        }
                        nilai={diumumkan || wajibUmum}
                        saatBerubah={(nilai) => {
                            if (!wajibUmum) {
                                AturDiumumkan(nilai);
                            }
                        }}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={memproses}>
                        Catat insiden
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function Kronologi({ insiden, saatSelesai }: { insiden: BarisInsiden; saatSelesai: () => void }) {
    return (
        <DialogFormulir
            judul={insiden.Judul}
            keterangan={`${insiden.LabelStatus} | mulai ${FormatTanggalWaktu(insiden.MulaiPada)}${insiden.SelesaiPada ? ` | selesai ${FormatTanggalWaktu(insiden.SelesaiPada)}` : ''}`}
            saatTutup={saatSelesai}
        >
            <div className="flex flex-col gap-4">
                <p className="text-isi break-words text-teks-utama">{insiden.Dampak}</p>
                <ol aria-label="Kronologi insiden" className="flex flex-col gap-3">
                    {insiden.Pembaruan.map((p, indeks) => (
                        <li
                            key={`${p.Waktu ?? ''}-${String(indeks)}`}
                            className="rounded-kontrol border border-garis p-3"
                        >
                            <p className="text-label text-teks-sekunder">
                                {p.LabelStatus} | {p.Waktu ? FormatTanggalWaktu(p.Waktu) : ''}
                                {p.Oleh ? ` | ${p.Oleh}` : ''}
                            </p>
                            <p className="text-isi break-words text-teks-utama">{p.Isi}</p>
                        </li>
                    ))}
                </ol>
                {insiden.PostMortem ? (
                    <div className="flex flex-col gap-1">
                        <h3 className="text-subjudul font-semibold text-teks-utama">
                            Post-mortem
                            {insiden.PostMortemDiterbitkanPada === null ? ' (draf, belum terbit)' : ''}
                        </h3>
                        <p className="text-isi whitespace-pre-wrap text-teks-utama">{insiden.PostMortem}</p>
                    </div>
                ) : null}
                <DialogFooter className="sm:justify-start">
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Tutup
                    </Tombol>
                </DialogFooter>
            </div>
        </DialogFormulir>
    );
}

function FormPembaruan({ insiden, saatSelesai }: { insiden: BarisInsiden; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const pilihan = StatusBerikut(insiden.Status);
    const [status, AturStatus] = useState<StatusInsiden>(insiden.Status);
    const [isi, AturIsi] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${alamat}/${insiden.Uuid}/pembaruan`,
            { Status: status, Isi: isi },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatSelesai,
            },
        );
    };

    return (
        <DialogFormulir
            judul={`Pembaruan: ${insiden.Judul}`}
            keterangan={
                insiden.DiumumkanPada === null
                    ? 'Insiden ini masih internal, pembaruan tidak tampil di halaman status.'
                    : 'Pembaruan langsung tampil di halaman status publik.'
            }
            galatUmum={props.errors.Umum}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangPilihan
                    label="Status terbaru"
                    nilai={status}
                    opsi={pilihan.map((s) => ({ Nilai: s, Label: s }))}
                    saatBerubah={(n) => AturStatus(n as StatusInsiden)}
                    galat={props.errors.Status}
                    required
                />
                <BidangTeksPanjang
                    label="Isi pembaruan"
                    nilai={isi}
                    saatBerubah={AturIsi}
                    galat={props.errors.Isi}
                    baris={3}
                    maksimal={1000}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses}>
                        {status === 'Selesai' ? 'Tandai selesai' : 'Kirim pembaruan'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormPostMortem({ insiden, saatSelesai }: { insiden: BarisInsiden; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const [teks, AturTeks] = useState(insiden.PostMortem ?? '');
    const [terbitkan, AturTerbitkan] = useState(false);
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${alamat}/${insiden.Uuid}/post-mortem`,
            { PostMortem: teks, Terbitkan: terbitkan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatSelesai,
            },
        );
    };

    return (
        <DialogFormulir
            judul={`Post-mortem: ${insiden.Judul}`}
            keterangan="Apa yang terjadi, penyebabnya, dan langkah agar tidak terulang. Yang sudah terbit tidak bisa diubah."
            galatUmum={props.errors.Umum}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Post-mortem"
                    nilai={teks}
                    saatBerubah={AturTeks}
                    galat={props.errors.PostMortem}
                    baris={8}
                    maksimal={5000}
                    required
                />
                {insiden.DiumumkanPada !== null ? (
                    <KotakCentang
                        label="Terbitkan di halaman status publik"
                        nilai={terbitkan}
                        saatBerubah={AturTerbitkan}
                    />
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        Insiden ini internal, jadi post-mortem hanya tersimpan sebagai catatan internal.
                    </p>
                )}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses}>
                        {terbitkan ? 'Terbitkan post-mortem' : 'Simpan post-mortem'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
