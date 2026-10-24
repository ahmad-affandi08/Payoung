import { router, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import BidangGambar from '@/Komponen/Formulir/BidangGambar';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import HasilBacaQris from '@/Komponen/PanduanAwal/HasilBacaQris';
import { useBacaQris } from '@/Komponen/PanduanAwal/useBacaQris';
import RingkasanGalatFormulir, { FokusGalatPertama } from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import TataLetakPanduan from '@/Komponen/PanduanAwal/TataLetakPanduan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import { Card, CardContent, CardHeader } from '@/Komponen/Ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen } from '@/Pustaka/Format';
import { FormatMasukanPersen, NormalisasiMasukanPersen } from '@/Pustaka/MasukanUang';
import { AlamatPanduan, type MetodePembayaranRingkas, type PropsMetodePembayaranPanduan } from '@/Tipe/PanduanAwal';

type IsianMetodePembayaran = {
    Jenis: string;
    Nama: string;
    KodeBank: string;
    GambarQris: File | null;
    /** Hanya penampung galat server; teks yang dikirim berasal dari hasil baca gambar (`useBacaQris`). */
    TeksQris: string;
    NomorRekening: string;
    NamaPemilikRekening: string;
    Kanal: string;
    PersenBiaya: string;
};

type JenisBank = PropsMetodePembayaranPanduan['Bank'][number]['Jenis'];

// Edc → bank atau jaringan EDC; Transfer → bank atau e-wallet (DesainF01 §D SimpanMetodePembayaranPermintaan).
const jenisBankPerMetode: Record<string, JenisBank[]> = {
    Edc: ['Bank', 'JaringanEdc'],
    Transfer: ['Bank', 'Ewallet'],
};

const contohNama: Record<string, string> = {
    QrisStatis: 'Misal "QRIS Toko". Tampil sebagai tombol di kasir.',
    QrisDinamis: 'Misal "QRIS Otomatis". Tampil sebagai tombol di kasir.',
    Edc: 'Misal "EDC BCA". Tampil sebagai tombol di kasir.',
    Transfer: 'Misal "Transfer BCA". Tampil sebagai tombol di kasir.',
    Marketplace: 'Misal "GoFood". Tampil di kasir hanya untuk pesanan kanal platform ini.',
};

/**
 * Langkah 5 F-01: Tunai selalu ada; tambah QRIS statis, QRIS dinamis (F-08), EDC per bank, transfer, atau (X8) platform
 * ojol/marketplace yang dananya dicairkan platform.
 */
export default function HalamanMetodePembayaran({
    Progres,
    MetodePembayaran,
    JenisTersedia,
    Bank,
    BatasGambarQris,
    GerbangPembayaran,
    KanalPlatform = [],
    PersenBiayaMaksimal = { Umum: '10', Platform: '40' },
}: PropsMetodePembayaranPanduan) {
    const hanyaTunai = MetodePembayaran.every((metode) => metode.Wajib);
    const isi = (
        <>
            {hanyaTunai ? (
                <p className="text-isi text-teks-sekunder">
                    Saat ini kasir hanya menerima Tunai. Tambahkan QRIS, EDC, atau transfer bank yang Anda pakai supaya
                    kasir bisa mencatat pembayaran non-tunai dengan benar.
                </p>
            ) : (
                <p className="text-isi text-teks-sekunder">
                    Metode pembayaran aktif tampil sebagai tombol di layar bayar aplikasi kasir.
                </p>
            )}

            <TabelMetodePembayaran metodePembayaran={MetodePembayaran} batasGambarQris={BatasGambarQris} />

            <FormTambahMetode
                jenisTersedia={JenisTersedia}
                bank={Bank}
                batasGambarQris={BatasGambarQris}
                gerbangPembayaran={GerbangPembayaran}
                kanalPlatform={KanalPlatform}
                persenBiayaMaksimal={PersenBiayaMaksimal}
            />
        </>
    );

    // Audit kemudahan pakai: setelah panduan selesai, halaman ini juga rumah tetap "Metode pembayaran" di Pengaturan,
    // jadi tampil di tata letak back-office biasa, bukan di dalam langkah panduan.
    return Progres.SelesaiPada !== null ? (
        <TataLetakAplikasi judul="Metode pembayaran" jejak={[{ label: 'Pengaturan', href: '/kelola/pengaturan' }]}>
            {isi}
        </TataLetakAplikasi>
    ) : (
        <TataLetakPanduan progres={Progres} langkah="MetodePembayaran" lanjut="tandai-selesai">
            {isi}
        </TataLetakPanduan>
    );
}

/**
 * F-08: berapa hari uang metode ini wajar menunggu sebelum masuk rekening. Lewat batas itu, Kotak Tindakan mengingatkan
 * bahwa platform belum menyetor. Kosong = bawaan jenis metode; tidak memengaruhi pembukuan.
 */
function AturBatasHariMenunggu({ metode }: { metode: MetodePembayaranRingkas }) {
    const [terbuka, AturTerbuka] = useState(false);
    const formulir = useForm({ BatasHariMenunggu: metode.BatasHariKustom ? String(metode.BatasHariKustom) : '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((data) => ({
            BatasHariMenunggu: data.BatasHariMenunggu === '' ? null : data.BatasHariMenunggu,
        }));
        formulir.post(`${AlamatPanduan.MetodePembayaran}/${metode.Uuid}/batas-hari-menunggu`, {
            preserveScroll: true,
            onSuccess: () => AturTerbuka(false),
        });
    };

    return (
        <Dialog open={terbuka} onOpenChange={AturTerbuka}>
            <span className="block text-keterangan">
                Wajar menunggu pencairan {metode.BatasHariMenunggu} hari
                {metode.BatasHariKustom ? '' : ' (bawaan)'} |{' '}
                <DialogTrigger asChild>
                    <button type="button" className="font-semibold text-brand underline">
                        Atur
                    </button>
                </DialogTrigger>
            </span>
            <DialogContent>
                <form onSubmit={Kirim} noValidate className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Batas menunggu pencairan {metode.Nama}</DialogTitle>
                        <DialogDescription>
                            Setelah lewat batas ini, Kotak Tindakan mengingatkan bahwa uang belum masuk rekening.
                            Kosongkan untuk memakai bawaan.
                        </DialogDescription>
                    </DialogHeader>
                    <BidangTeks
                        label="Batas menunggu (hari)"
                        nilai={formulir.data.BatasHariMenunggu}
                        saatBerubah={(nilai) => formulir.setData('BatasHariMenunggu', nilai.replace(/\D/g, ''))}
                        galat={formulir.errors.BatasHariMenunggu}
                        keterangan="1 sampai 60 hari kalender sejak tanggal transaksi."
                        inputMode="numeric"
                        maxLength={2}
                    />
                    <DialogFooter>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan batas
                        </Tombol>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * D-189: QRIS statis yang teksnya tersimpan = kasir menampilkan QRIS dengan nominal terisi. Metode lama (hanya gambar)
 * bisa diaktifkan dengan mengunggah ulang gambar QRIS yang sama; gambarnya dibaca di peramban dan tidak diganti.
 */
function AturNominalOtomatis({
    metode,
    batasGambarQris,
}: {
    metode: MetodePembayaranRingkas;
    batasGambarQris: PropsMetodePembayaranPanduan['BatasGambarQris'];
}) {
    const [terbuka, AturTerbuka] = useState(false);
    const [berkas, AturBerkas] = useState<File | null>(null);
    const bacaQris = useBacaQris();
    const formulir = useForm<{ TeksQris: string | null }>({ TeksQris: null });
    const alamat = `${AlamatPanduan.MetodePembayaran}/${metode.Uuid}/teks-qris`;

    const Tutup = (buka: boolean) => {
        AturTerbuka(buka);

        if (!buka) {
            AturBerkas(null);
            bacaQris.Ulang();
        }
    };

    const Aktifkan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (bacaQris.keadaan.Tahap !== 'berhasil') {
            return;
        }

        formulir.transform(() => ({ TeksQris: bacaQris.keadaan.Tahap === 'berhasil' ? bacaQris.keadaan.Teks : null }));
        formulir.post(alamat, { preserveScroll: true, onSuccess: () => Tutup(false) });
    };

    const Matikan = () => {
        formulir.transform(() => ({ TeksQris: null }));
        formulir.post(alamat, { preserveScroll: true });
    };

    return (
        <Dialog open={terbuka} onOpenChange={Tutup}>
            <span className="block text-keterangan">
                {metode.NominalOtomatis ? (
                    <>
                        Nominal terisi otomatis di kasir |{' '}
                        <button type="button" className="font-semibold text-brand underline" onClick={Matikan}>
                            Matikan
                        </button>
                    </>
                ) : (
                    <>
                        Pelanggan mengetik nominal sendiri |{' '}
                        <DialogTrigger asChild>
                            <button type="button" className="font-semibold text-brand underline">
                                Isi nominal otomatis
                            </button>
                        </DialogTrigger>
                    </>
                )}
            </span>
            <DialogContent>
                <form onSubmit={Aktifkan} noValidate className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Nominal otomatis untuk {metode.Nama}</DialogTitle>
                        <DialogDescription>
                            Unggah gambar QRIS toko Anda yang sama. Kode QR dibaca di peramban ini, lalu kasir
                            menampilkan QRIS dengan nominal transaksi yang sudah terisi. Gambar yang tersimpan tidak
                            berubah.
                        </DialogDescription>
                    </DialogHeader>
                    <BidangGambar
                        label="Gambar QRIS"
                        berkas={berkas}
                        saatBerubah={(baru) => {
                            AturBerkas(baru);
                            void bacaQris.Baca(baru);
                        }}
                        ukuranMaksimalKb={batasGambarQris.UkuranMaksimalKb}
                        ekstensi={batasGambarQris.Ekstensi}
                        keterangan="Pastikan kode QR terlihat utuh dan tidak terpotong."
                    />
                    <HasilBacaQris keadaan={bacaQris.keadaan} />
                    {formulir.errors.TeksQris ? (
                        <Pemberitahuan jenis="bahaya">{formulir.errors.TeksQris}</Pemberitahuan>
                    ) : null}
                    <DialogFooter>
                        <Tombol
                            type="submit"
                            memproses={formulir.processing}
                            disabled={bacaQris.keadaan.Tahap !== 'berhasil'}
                        >
                            Aktifkan nominal otomatis
                        </Tombol>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function TabelMetodePembayaran({
    metodePembayaran,
    batasGambarQris,
}: {
    metodePembayaran: MetodePembayaranRingkas[];
    batasGambarQris: PropsMetodePembayaranPanduan['BatasGambarQris'];
}) {
    const [memproses, AturMemproses] = useState<string | null>(null);

    const UbahStatus = (metode: MetodePembayaranRingkas) =>
        router.post(
            `${AlamatPanduan.MetodePembayaran}/${metode.Uuid}/${metode.Aktif ? 'nonaktifkan' : 'aktifkan'}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(metode.Uuid),
                onFinish: () => AturMemproses(null),
            },
        );

    const kolom: KolomTabel<MetodePembayaranRingkas>[] = [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Nama',
            meta: { label: 'Nama', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: metode } }) => (
                <>
                    <span className="break-words text-teks-utama">{metode.Nama}</span>
                    <span className="block text-keterangan text-teks-sekunder">{metode.LabelJenis}</span>
                </>
            ),
        },
        {
            id: 'Rincian',
            header: 'Rincian',
            enableSorting: false,
            meta: { label: 'Rincian', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: metode } }) => (
                <>
                    {metode.TautanGambarQris ? (
                        <img
                            src={metode.TautanGambarQris}
                            alt={`Gambar QRIS ${metode.Nama}`}
                            width={64}
                            height={64}
                            className="size-16 rounded-kontrol border border-garis object-contain"
                        />
                    ) : null}
                    {metode.NamaBank ? <span className="block">{metode.NamaBank}</span> : null}
                    {metode.LabelKanal ? <span className="block">Pesanan {metode.LabelKanal}</span> : null}
                    {metode.NomorRekening ? (
                        <span className="block font-mono text-label">
                            {metode.NomorRekening}
                            {metode.NamaPemilikRekening ? ` a.n. ${metode.NamaPemilikRekening}` : ''}
                        </span>
                    ) : null}
                    {!metode.TautanGambarQris && !metode.NamaBank && !metode.NomorRekening && !metode.LabelKanal
                        ? '—'
                        : null}
                    {metode.Jenis === 'QrisStatis' ? (
                        <AturNominalOtomatis metode={metode} batasGambarQris={batasGambarQris} />
                    ) : null}
                    {metode.BatasHariMenunggu ? <AturBatasHariMenunggu metode={metode} /> : null}
                </>
            ),
        },
        {
            id: 'PersenBiaya',
            header: 'Biaya',
            enableSorting: false,
            meta: { label: 'Biaya', angka: true, prioritas: 'penting' },
            cell: ({ row }) => `${FormatPersen(row.original.PersenBiaya)}%`,
        },
        {
            id: 'Aktif',
            accessorKey: 'Aktif',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) =>
                row.original.Aktif ? (
                    <LabelStatus jenis="sukses" teks="Aktif" />
                ) : (
                    <LabelStatus jenis="netral" teks="Nonaktif" />
                ),
        },
        {
            id: 'Tindakan',
            header: () => <span className="sr-only">Tindakan</span>,
            enableSorting: false,
            meta: { label: 'Tindakan', prioritas: 'penting', wajib: true, kelasSel: 'text-right' },
            cell: ({ row: { original: metode } }) =>
                metode.Wajib ? (
                    <span className="text-keterangan text-teks-sekunder">Selalu tersedia</span>
                ) : metode.Aktif ? (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={memproses !== null}
                                aria-busy={memproses === metode.Uuid || undefined}
                            >
                                {memproses === metode.Uuid ? 'Memproses…' : `Nonaktifkan ${metode.Nama}`}
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Nonaktifkan {metode.Nama}?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Tombol {metode.Nama} hilang dari layar bayar aplikasi kasir. Transaksi lama tidak
                                    berubah, dan metode ini bisa diaktifkan lagi.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                <AlertDialogAction variant="destructive" onClick={() => UbahStatus(metode)}>
                                    Nonaktifkan {metode.Nama}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                ) : (
                    <Tombol
                        varian="sekunder"
                        onClick={() => UbahStatus(metode)}
                        memproses={memproses === metode.Uuid}
                        disabled={memproses !== null}
                    >
                        {`Aktifkan ${metode.Nama}`}
                    </Tombol>
                ),
        },
    ];

    return (
        <TabelData
            id="panduan-metode-pembayaran"
            label="Metode pembayaran"
            kolom={kolom}
            sumber={{ mode: 'lokal', data: metodePembayaran }}
            ambilIdBaris={(metode) => metode.Uuid}
            kosong={{
                ilustrasi: true,
                judul: 'Belum ada metode pembayaran yang tercatat. Tunai selalu tersedia di kasir.',
            }}
        />
    );
}

type PropsFormTambahMetode = {
    jenisTersedia: PropsMetodePembayaranPanduan['JenisTersedia'];
    bank: PropsMetodePembayaranPanduan['Bank'];
    batasGambarQris: PropsMetodePembayaranPanduan['BatasGambarQris'];
    gerbangPembayaran: PropsMetodePembayaranPanduan['GerbangPembayaran'];
    kanalPlatform: NonNullable<PropsMetodePembayaranPanduan['KanalPlatform']>;
    persenBiayaMaksimal: NonNullable<PropsMetodePembayaranPanduan['PersenBiayaMaksimal']>;
};

function FormTambahMetode({
    jenisTersedia,
    bank,
    batasGambarQris,
    gerbangPembayaran,
    kanalPlatform,
    persenBiayaMaksimal,
}: PropsFormTambahMetode) {
    const elemenFormulir = useRef<HTMLFormElement>(null);
    const formulir = useForm<IsianMetodePembayaran>({
        Jenis: jenisTersedia[0]?.Nilai ?? 'QrisStatis',
        Nama: '',
        KodeBank: '',
        GambarQris: null,
        TeksQris: '',
        NomorRekening: '',
        NamaPemilikRekening: '',
        Kanal: '',
        PersenBiaya: '',
    });
    const { Jenis } = formulir.data;
    const bacaQris = useBacaQris();
    const opsiBank = bank
        .filter((baris) => jenisBankPerMetode[Jenis]?.includes(baris.Jenis))
        .map((baris) => ({ Nilai: baris.Kode, Label: baris.Nama }));

    const GantiJenis = (jenis: string) => {
        bacaQris.Ulang();
        formulir.setData({ ...formulir.data, Jenis: jenis, KodeBank: '', GambarQris: null, Kanal: '' });
    };
    const platform = Jenis === 'Marketplace';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        // Isian yang tidak relevan dengan jenis dikirim kosong agar tidak tersimpan.
        formulir.transform((data) => ({
            ...data,
            KodeBank: data.Jenis === 'Edc' || data.Jenis === 'Transfer' ? data.KodeBank || null : null,
            GambarQris: data.Jenis === 'QrisStatis' ? data.GambarQris : null,
            // D-189: hanya teks yang lolos pemeriksaan di peramban yang dikirim; server memeriksa ulang.
            TeksQris:
                data.Jenis === 'QrisStatis' && bacaQris.keadaan.Tahap === 'berhasil' ? bacaQris.keadaan.Teks : null,
            NomorRekening: data.Jenis === 'Transfer' ? data.NomorRekening || null : null,
            NamaPemilikRekening: data.Jenis === 'Transfer' ? data.NamaPemilikRekening || null : null,
            Kanal: data.Jenis === 'Marketplace' ? data.Kanal || null : null,
            PersenBiaya: data.PersenBiaya || null,
        }));
        formulir.post(AlamatPanduan.MetodePembayaran, {
            preserveScroll: true,
            onSuccess: () => {
                formulir.reset();
                bacaQris.Ulang();
            },
            onError: () => FokusGalatPertama(elemenFormulir.current),
        });
    };

    return (
        <Card
            aria-labelledby="judul-tambah-metode"
            className="gap-4 p-4 sm:p-6 rounded-panel shadow-none"
            role="region"
        >
            <CardHeader className="px-0">
                <h2 id="judul-tambah-metode" className="text-subjudul font-semibold text-teks-utama">
                    Tambah metode pembayaran
                </h2>
            </CardHeader>
            <CardContent className="px-0">
                <form ref={elemenFormulir} onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <RingkasanGalatFormulir galat={formulir.errors} />
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <BidangPilihan
                            label="Jenis"
                            nilai={Jenis}
                            opsi={jenisTersedia}
                            saatBerubah={GantiJenis}
                            galat={formulir.errors.Jenis}
                            required
                        />
                        <BidangTeks
                            label="Nama di kasir"
                            nilai={formulir.data.Nama}
                            saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                            galat={formulir.errors.Nama}
                            keterangan={contohNama[Jenis] ?? 'Tampil sebagai tombol di kasir.'}
                            maxLength={60}
                            required
                        />
                        {Jenis === 'Edc' || Jenis === 'Transfer' ? (
                            <BidangPilihan
                                label={Jenis === 'Edc' ? 'Bank penerbit mesin EDC' : 'Bank atau e-wallet tujuan'}
                                nilai={formulir.data.KodeBank}
                                opsi={opsiBank}
                                saatBerubah={(nilai) => formulir.setData('KodeBank', nilai)}
                                galat={formulir.errors.KodeBank}
                                required
                                kosong={opsiBank.length === 0 ? 'Data bank belum tersedia' : 'Pilih bank'}
                            />
                        ) : null}
                        {platform ? (
                            <BidangPilihan
                                label="Platform"
                                nilai={formulir.data.Kanal}
                                opsi={kanalPlatform}
                                saatBerubah={(nilai) => formulir.setData('Kanal', nilai)}
                                galat={formulir.errors.Kanal}
                                required
                                kosong="Pilih platform"
                            />
                        ) : null}
                        {Jenis === 'Transfer' ? (
                            <>
                                <BidangTeks
                                    label="Nomor rekening"
                                    nilai={formulir.data.NomorRekening}
                                    saatBerubah={(nilai) => formulir.setData('NomorRekening', nilai.replace(/\D/g, ''))}
                                    galat={formulir.errors.NomorRekening}
                                    keterangan="Angka saja, 5 sampai 30 digit."
                                    inputMode="numeric"
                                    maxLength={30}
                                    kode
                                    required
                                />
                                <BidangTeks
                                    label="Nama pemilik rekening"
                                    nilai={formulir.data.NamaPemilikRekening}
                                    saatBerubah={(nilai) => formulir.setData('NamaPemilikRekening', nilai)}
                                    galat={formulir.errors.NamaPemilikRekening}
                                    maxLength={100}
                                    required
                                />
                            </>
                        ) : null}
                        <BidangTeks
                            label={
                                platform
                                    ? 'Komisi platform (persen, opsional)'
                                    : 'Biaya per transaksi (persen, opsional)'
                            }
                            nilai={FormatMasukanPersen(formulir.data.PersenBiaya)}
                            saatBerubah={(nilai) => formulir.setData('PersenBiaya', NormalisasiMasukanPersen(nilai))}
                            galat={formulir.errors.PersenBiaya}
                            keterangan={
                                platform
                                    ? `Potongan komisi dari platform, 0 sampai ${persenBiayaMaksimal.Platform} persen, misal 20. Dipakai untuk laporan biaya.`
                                    : `Potongan dari penyedia (MDR), 0 sampai ${persenBiayaMaksimal.Umum} persen, misal 0,7. Dipakai untuk laporan biaya.`
                            }
                            inputMode="decimal"
                            maxLength={7}
                        />
                    </div>
                    {Jenis === 'QrisDinamis' ? <InfoQrisDinamis gerbangPembayaran={gerbangPembayaran} /> : null}
                    {platform ? (
                        <Pemberitahuan jenis="info" judul="Pesanan ojol dicatat manual di kasir">
                            Kasir memilih kanal (misal GoFood) di keranjang, lalu membayar dengan metode ini. Harga
                            khusus platform diatur lewat Daftar harga dengan kanal yang sama. Dana tercatat sebagai
                            piutang pencairan sampai platform mentransfer ke rekening toko.
                        </Pemberitahuan>
                    ) : null}
                    {Jenis === 'QrisStatis' ? (
                        <>
                            <BidangGambar
                                label="Gambar QRIS"
                                berkas={formulir.data.GambarQris}
                                saatBerubah={(berkas) => {
                                    formulir.setData('GambarQris', berkas);
                                    void bacaQris.Baca(berkas);
                                }}
                                ukuranMaksimalKb={batasGambarQris.UkuranMaksimalKb}
                                ekstensi={batasGambarQris.Ekstensi}
                                keterangan="Foto atau unduh QRIS dari aplikasi bank/penyedia Anda. Pastikan kode QR terlihat utuh. Kode QR dibaca di peramban Anda, supaya kasir bisa menampilkan QRIS dengan nominal yang sudah terisi."
                                galat={formulir.errors.GambarQris}
                            />
                            <HasilBacaQris keadaan={bacaQris.keadaan} />
                            {formulir.errors.TeksQris ? (
                                <Pemberitahuan jenis="bahaya">{formulir.errors.TeksQris}</Pemberitahuan>
                            ) : null}
                        </>
                    ) : null}
                    <div>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Tambah metode pembayaran
                        </Tombol>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

/** F-08: QRIS dinamis dibuat per transaksi lewat gerbang pembayaran yang diaktifkan pengelola platform. */
function InfoQrisDinamis({
    gerbangPembayaran,
}: {
    gerbangPembayaran: PropsMetodePembayaranPanduan['GerbangPembayaran'];
}) {
    return gerbangPembayaran.Aktif ? (
        <Pemberitahuan jenis="info" judul={`Gerbang pembayaran aktif: ${gerbangPembayaran.Penyedia ?? 'tersedia'}`}>
            Kasir membuat kode QR berisi jumlah tagihan untuk setiap transaksi. Status lunas masuk otomatis, jadi kasir
            tidak perlu memeriksa mutasi rekening. Aplikasi kasir harus tersambung internet saat memakai metode ini.
        </Pemberitahuan>
    ) : (
        <Pemberitahuan jenis="peringatan" judul="Gerbang pembayaran belum aktif">
            {gerbangPembayaran.Petunjuk ??
                'QRIS dinamis butuh akun merchant toko di salah satu penyedia gerbang pembayaran (dana langsung ke rekening toko).'}{' '}
            Metode ini tetap bisa ditambahkan, tetapi kasir baru bisa memakainya setelah diaktifkan di{' '}
            <a
                href={gerbangPembayaran.Tautan ?? '/kelola/pembayaran/gerbang'}
                className="font-semibold text-brand underline underline-offset-2"
            >
                {gerbangPembayaran.NamaMenu ?? 'Gerbang pembayaran'}
            </a>
            .
        </Pemberitahuan>
    );
}
