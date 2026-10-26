import { useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabKatalog from '@/Komponen/Pengelola/TabKatalog';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Fitur = { Kunci: string; Nama: string; Modul: string; Keterangan: string | null };

const kolom: KolomTabel<Fitur>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: fitur } }) => (
            <>
                <span className="block text-teks-utama">{fitur.Nama}</span>
                {fitur.Keterangan ? (
                    <span className="block text-keterangan font-normal text-teks-sekunder">{fitur.Keterangan}</span>
                ) : null}
            </>
        ),
    },
    {
        id: 'Kunci',
        accessorKey: 'Kunci',
        header: 'Kunci',
        meta: { label: 'Kunci', prioritas: 'penting', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'Modul',
        accessorKey: 'Modul',
        header: 'Modul',
        meta: { label: 'Modul', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
    },
];

/** Katalog fitur (P-04). Kunci fitur dipakai kode aplikasi dan tidak bisa diubah. */
export default function HalamanFitur({ Fitur }: { Fitur: Fitur[] }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.KatalogFiturKelola);
    const [sunting, AturSunting] = useState<Fitur | 'baru' | null>(null);

    return (
        <TataLetakPengelola judul="Katalog">
            <TabKatalog />
            {sunting !== null ? (
                <FormFitur
                    key={sunting === 'baru' ? 'baru' : sunting.Kunci}
                    fitur={sunting === 'baru' ? null : sunting}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            <AksiHalaman>
                {bolehKelola && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Tambah fitur</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-katalog-fitur"
                label="Katalog fitur"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Fitur }}
                ambilIdBaris={(fitur) => fitur.Kunci}
                urutBawaan="Kunci"
                cari="Cari kunci, nama, atau modul"
                saring={[
                    {
                        id: 'Modul',
                        label: 'Modul',
                        jenis: 'pilihanBanyak',
                        opsi: [...new Set(Fitur.map((fitur) => fitur.Modul))].map((modul) => ({
                            nilai: modul,
                            label: modul,
                        })),
                    },
                ]}
                {...(bolehKelola
                    ? {
                          aksiBaris: (fitur: Fitur) => (
                              <DropdownMenuItem onSelect={() => AturSunting(fitur)}>Ubah fitur</DropdownMenuItem>
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada fitur. Tambahkan fitur pertama. Kunci fitur dipakai kode aplikasi, jadi tulis sesuai modul yang sudah dibangun.',
                }}
            />
        </TataLetakPengelola>
    );
}

function FormFitur({ fitur, saatSelesai }: { fitur: Fitur | null; saatSelesai: () => void }) {
    const formulir = useForm({
        Kunci: fitur?.Kunci ?? '',
        Nama: fitur?.Nama ?? '',
        Modul: fitur?.Modul ?? '',
        Keterangan: fitur?.Keterangan ?? '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (fitur === null) {
            formulir.post('/katalog/fitur', opsi);
        } else {
            formulir.put(`/katalog/fitur/${encodeURIComponent(fitur.Kunci)}`, opsi);
        }
    };

    return (
        <DialogFormulir judul={fitur === null ? 'Tambah fitur' : `Ubah ${fitur.Nama}`} saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangTeks
                    label="Kunci"
                    kode
                    keterangan="Huruf kecil dipisah titik, misal pos.mode-meja. Tidak bisa diubah."
                    nilai={formulir.data.Kunci}
                    saatBerubah={(nilai) => formulir.setData('Kunci', nilai.toLowerCase())}
                    galat={formulir.errors.Kunci}
                    required
                    disabled={fitur !== null}
                />
                <BidangTeks
                    label="Nama"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    required
                />
                <BidangTeks
                    label="Modul"
                    nilai={formulir.data.Modul}
                    saatBerubah={(nilai) => formulir.setData('Modul', nilai)}
                    galat={formulir.errors.Modul}
                    required
                />
                <BidangTeks
                    label="Keterangan (opsional)"
                    nilai={formulir.data.Keterangan}
                    saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                    galat={formulir.errors.Keterangan}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan fitur
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
