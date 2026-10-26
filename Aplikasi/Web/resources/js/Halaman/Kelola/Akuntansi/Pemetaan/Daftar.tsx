import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris, type AksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Badge } from '@/Komponen/Ui/badge';
import Tombol from '@/Komponen/Formulir/Tombol';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPemetaanAkun, PropsPemetaanAkun, StatusPemetaanAkun } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/pemetaan';

const labelStatus: Record<StatusPemetaanAkun, string> = {
    Sesuai: 'Sesuai',
    TipeSalah: 'Tipe akun salah',
    BelumDipetakan: 'Belum dipetakan',
    AkunNonaktif: 'Akun nonaktif',
};

const kolom: KolomTabel<BarisPemetaanAkun>[] = [
    {
        id: 'LabelPeran',
        accessorKey: 'LabelPeran',
        header: 'Peran akun',
        meta: { label: 'Peran akun', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{p.LabelPeran}</span>
                <span className="text-label text-teks-sekunder">
                    Akun {p.LabelTipeWajib.toLowerCase()}
                    {p.WajibKontra ? ' kontra' : ''}
                </span>
            </>
        ),
    },
    {
        id: 'Tingkat',
        accessorFn: (p) => p.NamaOutlet ?? 'Semua outlet',
        header: 'Berlaku untuk',
        meta: { label: 'Berlaku untuk', prioritas: 'penting' },
    },
    {
        id: 'Akun',
        accessorFn: (p) => (p.KodeAkun ? `${p.KodeAkun} ${p.NamaAkun ?? ''}` : ''),
        header: 'Akun',
        meta: { label: 'Akun', prioritas: 'penting' },
        cell: ({ row: { original: p } }) =>
            p.KodeAkun ? (
                <>
                    <span className="font-mono">{p.KodeAkun}</span> <span className="break-words">{p.NamaAkun}</span>
                </>
            ) : (
                <span className="text-teks-sekunder">Belum dipetakan</span>
            ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (
            <>
                <Badge variant={p.Status === 'Sesuai' ? 'default' : 'outline'}>{labelStatus[p.Status]}</Badge>
                {p.PesanStatus ? (
                    <span className="mt-1 block text-label break-words text-teks-sekunder">{p.PesanStatus}</span>
                ) : null}
            </>
        ),
    },
];

type Formulir = { baris: BarisPemetaanAkun; tambahOutlet: boolean; UuidOutlet: string; UuidAkun: string };

/**
 * F-13a pemetaan akun: setiap peran akun → akun (umum untuk semua outlet, bisa diganti khusus per outlet). Tipe akun
 * divalidasi seperti template sektor (BR-P03.3); perubahan dicatat di log audit dan berlaku untuk jurnal berikutnya.
 */
export default function HalamanPemetaanAkun({ Pemetaan, OpsiAkun, OpsiOutlet, Izin }: PropsPemetaanAkun) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [form, AturForm] = useState<Formulir | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [perbaiki, AturPerbaiki] = useState(false);

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (form === null) {
            return;
        }

        router.put(
            alamat,
            {
                Kunci: form.baris.Kunci,
                UuidOutlet: form.UuidOutlet === '' ? null : form.UuidOutlet,
                UuidAkun: form.UuidAkun,
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturForm(null),
            },
        );
    };

    const AksiBarisPemetaan = (p: BarisPemetaanAkun) => {
        const aksi: AksiBaris[] = [];
        const bolehUbah = p.UuidOutlet !== null || Izin.UbahSemuaOutlet;

        if (bolehUbah) {
            aksi.push({
                label: 'Ganti akun',
                saatPilih: () =>
                    AturForm({
                        baris: p,
                        tambahOutlet: false,
                        UuidOutlet: p.UuidOutlet ?? '',
                        UuidAkun: p.UuidAkun ?? '',
                    }),
            });
        }

        if (p.UuidOutlet === null && OpsiOutlet.length > 0) {
            aksi.push({
                label: 'Atur akun khusus outlet',
                saatPilih: () => AturForm({ baris: p, tambahOutlet: true, UuidOutlet: '', UuidAkun: '' }),
            });
        }

        if (p.UuidOutlet !== null) {
            aksi.push({
                label: 'Hapus akun khusus outlet',
                bahaya: true,
                saatPilih: () =>
                    router.delete(alamat, { data: { Kunci: p.Kunci, UuidOutlet: p.UuidOutlet }, preserveScroll: true }),
            });
        }

        return aksi.length > 0 ? <ItemAksiBaris aksi={aksi} /> : null;
    };

    const opsiAkun =
        form === null
            ? []
            : OpsiAkun.filter((a) => a.Jenis === form.baris.TipeWajib && a.Kontra === form.baris.WajibKontra).map(
                  (a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }),
              );

    return (
        <TataLetakAplikasi judul="Pemetaan akun">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Jurnal otomatis (penjualan, kas, stok) memakai akun yang dipetakan di sini. Akun khusus outlet
                menggantikan akun umum hanya untuk outlet itu. Perubahan berlaku untuk jurnal berikutnya. Jurnal yang
                sudah diposting tidak berubah.
            </p>
            {Izin.Kelola ? null : <PesanHanyaLihat izin="akuntansi.kelola" objek="pemetaan akun" />}
            {/* Audit kemudahan pakai #12: lengkapi peran yang belum dipetakan dari template sektor (aditif). */}
            {Izin.Kelola &&
            Izin.UbahSemuaOutlet &&
            Pemetaan.some((p) => p.UuidOutlet === null && p.Status === 'BelumDipetakan') ? (
                <AksiHalaman
                    keterangan={
                        <span className="text-keterangan text-teks-sekunder">Ada peran akun yang belum dipetakan.</span>
                    }
                >
                    <Tombol
                        onClick={() =>
                            router.post(
                                `${alamat}/perbaiki-otomatis`,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => AturPerbaiki(true),
                                    onFinish: () => AturPerbaiki(false),
                                },
                            )
                        }
                        memproses={perbaiki}
                    >
                        Perbaiki otomatis
                    </Tombol>
                </AksiHalaman>
            ) : null}

            <TabelData
                id="akuntansi-pemetaan-akun"
                label="Pemetaan akun"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Pemetaan }}
                ambilIdBaris={(p) => p.Id}
                cari="Cari peran atau akun"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        opsi: Object.entries(labelStatus).map(([nilai, label]) => ({ nilai, label })),
                    },
                ]}
                labelBaris={(p) => `untuk ${p.LabelPeran} ${p.NamaOutlet ?? 'semua outlet'}`}
                {...(Izin.Kelola ? { aksiBaris: AksiBarisPemetaan } : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada peran akun.' }}
            />

            {form !== null ? (
                <DialogFormulir
                    judul={`Akun untuk ${form.baris.LabelPeran}`}
                    keterangan={`Hanya akun ${form.baris.LabelTipeWajib.toLowerCase()}${form.baris.WajibKontra ? ' kontra' : ''} yang aktif yang bisa dipilih.`}
                    galatUmum={galat.Umum}
                    saatTutup={() => AturForm(null)}
                >
                    <form
                        onSubmit={Simpan}
                        className="flex flex-col gap-4"
                        aria-label="Formulir pemetaan akun"
                        noValidate
                    >
                        {form.tambahOutlet ? (
                            <BidangOutlet
                                nilai={form.UuidOutlet}
                                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                                saatBerubah={(nilai) => AturForm({ ...form, UuidOutlet: nilai })}
                                galat={galat.UuidOutlet}
                            />
                        ) : (
                            <p className="text-isi text-teks-sekunder">
                                Berlaku untuk: {form.baris.NamaOutlet ?? 'semua outlet'}
                            </p>
                        )}
                        <BidangPilihan
                            label="Akun"
                            nilai={form.UuidAkun}
                            kosong="Pilih akun"
                            opsi={opsiAkun}
                            saatBerubah={(nilai) => AturForm({ ...form, UuidAkun: nilai })}
                            galat={galat.UuidAkun}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturForm(null)}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" memproses={memproses}>
                                Simpan pemetaan
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
