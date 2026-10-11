import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Badge } from '@/Komponen/Ui/badge';
import { Button } from '@/Komponen/Ui/button';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { LabelAkun, SaranAkun, useTampilKodeAkun } from '@/Pustaka/SaranAkun';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisKategoriKas, JenisKategoriKas, PropsKategoriKas } from '@/Tipe/Kasir';

const alamat = '/kelola/kasir/kategori-kas';

const kolom: KolomTabel<BarisKategoriKas>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true, kelasSel: 'font-semibold text-teks-utama' },
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row }) => row.original.LabelJenis,
    },
    {
        id: 'Akun',
        accessorFn: (k) => k.Akun ?? '—',
        header: 'Akun jurnal',
        meta: { label: 'Akun jurnal', prioritas: 'rendah', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'Aktif',
        accessorKey: 'Aktif',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <Badge variant={row.original.Aktif ? 'default' : 'outline'}>
                {row.original.Aktif ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    },
];

type IsianKategori = { Nama: string; Jenis: JenisKategoriKas; UuidAkun: string };

const opsiJenis = [
    { Nilai: 'Keluar', Label: 'Kas keluar (beban, kasbon, dsb.)' },
    { Nilai: 'Masuk', Label: 'Kas masuk (tambahan modal, pendapatan lain)' },
];

/** F-06: kategori kas masuk/keluar non-penjualan yang dipilih kasir di POS, masing-masing dengan akun jurnalnya. */
export default function HalamanKategoriKas({ Kategori, OpsiAkun }: PropsKategoriKas) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [form, AturForm] = useState<{ uuid: string | null; isian: IsianKategori; akunManual: boolean } | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [memprosesBiaya, AturMemprosesBiaya] = useState(false);
    const [tampilKode, AturTampilKode] = useTampilKodeAkun();

    /** Audit kemudahan pakai #15: akun jurnal disarankan dari nama kategori sampai pengguna memilihnya sendiri. */
    const AturIsian = (ubah: Partial<IsianKategori>, akunManual?: boolean) => {
        if (form === null) {
            return;
        }

        const isian = { ...form.isian, ...ubah };
        const manual = akunManual ?? form.akunManual;
        const saran = manual ? null : SaranAkun(isian.Nama, OpsiAkun[isian.Jenis]);

        AturForm({ ...form, isian: saran ? { ...isian, UuidAkun: saran.Uuid } : isian, akunManual: manual });
    };

    const Buka = (kategori: BarisKategoriKas | null) =>
        AturForm({
            uuid: kategori?.Uuid ?? null,
            isian: kategori
                ? { Nama: kategori.Nama, Jenis: kategori.Jenis, UuidAkun: kategori.UuidAkun ?? '' }
                : { Nama: '', Jenis: 'Keluar', UuidAkun: SaranAkun('', OpsiAkun.Keluar)?.Uuid ?? '' },
            akunManual: kategori !== null,
        });

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (form === null) {
            return;
        }

        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturForm(null),
        };

        if (form.uuid === null) {
            router.post(alamat, form.isian, opsi);
        } else {
            router.put(`${alamat}/${form.uuid}`, form.isian, opsi);
        }
    };

    /** D-106: sekali klik menambah kategori biaya umum + akun bebannya; yang sudah ada tidak diubah. */
    const SiapkanBiayaUmum = () =>
        router.post(
            `${alamat}/biaya-umum`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemprosesBiaya(true),
                onFinish: () => AturMemprosesBiaya(false),
            },
        );

    const UbahStatus = (kategori: BarisKategoriKas) =>
        router.put(`${alamat}/${kategori.Uuid}/status`, { Aktif: !kategori.Aktif }, { preserveScroll: true });

    return (
        <TataLetakAplikasi judul="Kategori kas">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kasir memilih kategori ini saat mencatat kas keluar (misalnya beli es batu, bayar parkir) atau kas masuk
                di luar penjualan. Setiap kategori dijurnal ke akun yang dipilih. Kategori yang sudah dipakai cukup
                dinonaktifkan, tidak dihapus.
            </p>
            <AksiHalaman>
                <Tombol varian="sekunder" memproses={memprosesBiaya} onClick={SiapkanBiayaUmum}>
                    Tambah biaya umum (listrik, transport, dll.)
                </Tombol>
                <Button onClick={() => Buka(null)}>Tambah kategori kas</Button>
            </AksiHalaman>

            <TabelData
                id="kasir-kategori-kas"
                label="Daftar kategori kas"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Kategori }}
                ambilIdBaris={(k) => k.Uuid}
                urutBawaan="Nama"
                cari="Cari nama kategori"
                saring={[
                    {
                        id: 'Jenis',
                        label: 'Jenis',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Keluar', label: 'Kas keluar' },
                            { nilai: 'Masuk', label: 'Kas masuk' },
                        ],
                    },
                    { id: 'Aktif', label: 'Hanya yang aktif', jenis: 'ya' },
                ]}
                labelBaris={(k) => `untuk kategori ${k.Nama}`}
                aksiBaris={(k) => (
                    <ItemAksiBaris
                        aksi={[
                            { label: 'Ubah kategori', saatPilih: () => Buka(k) },
                            {
                                label: k.Aktif ? 'Nonaktifkan kategori' : 'Aktifkan kategori',
                                saatPilih: () => UbahStatus(k),
                                bahaya: k.Aktif,
                            },
                        ]}
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kategori kas. Klik "Tambah biaya umum" untuk memulai dengan listrik, air, transport, dan biaya harian lain.',
                }}
            />

            {form !== null ? (
                <DialogFormulir
                    judul={form.uuid === null ? 'Tambah kategori kas' : 'Ubah kategori kas'}
                    galatUmum={galat.Umum}
                    saatTutup={() => AturForm(null)}
                >
                    <form
                        onSubmit={Simpan}
                        className="flex flex-col gap-4"
                        aria-label="Formulir kategori kas"
                        noValidate
                    >
                        <BidangTeks
                            label="Nama kategori"
                            nilai={form.isian.Nama}
                            saatBerubah={(nilai) => AturIsian({ Nama: nilai })}
                            galat={galat.Nama}
                            maxLength={100}
                            required
                        />
                        {form.uuid === null ? (
                            <BidangPilihan
                                label="Jenis"
                                nilai={form.isian.Jenis}
                                opsi={opsiJenis}
                                saatBerubah={(nilai) =>
                                    AturIsian({ Jenis: nilai as JenisKategoriKas, UuidAkun: '' }, false)
                                }
                                galat={galat.Jenis}
                                required
                            />
                        ) : null}
                        <BidangPilihan
                            label={form.isian.Jenis === 'Keluar' ? 'Dicatat sebagai biaya' : 'Dicatat sebagai'}
                            nilai={form.isian.UuidAkun}
                            kosong="Pilih akun"
                            opsi={OpsiAkun[form.isian.Jenis].map((a) => ({
                                Nilai: a.Uuid,
                                Label: LabelAkun(a, tampilKode),
                            }))}
                            saatBerubah={(nilai) => AturIsian({ UuidAkun: nilai }, true)}
                            galat={galat.UuidAkun}
                            required
                        />
                        <p className="text-keterangan text-teks-sekunder">
                            Dipilih otomatis dari nama kategori; ubah bila kurang pas.
                        </p>
                        <KotakCentang
                            label="Tampilkan kode akun (untuk akuntan)"
                            nilai={tampilKode}
                            saatBerubah={AturTampilKode}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturForm(null)}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" memproses={memproses}>
                                Simpan kategori
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
