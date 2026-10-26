import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import NavigasiKampanye from '@/Komponen/Pengelola/KampanyeProspek/NavigasiKampanye';
import TabTenant from '@/Komponen/Pengelola/Tenant/TabTenant';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { BarisKontakProspek, RingkasKontakProspek } from '@/Tipe/KampanyeProspek';

type PropsKontak = {
    Kontak: BarisKontakProspek[];
    Ringkasan: RingkasKontakProspek;
    MaksBarisDaftar: number;
    MaksBarisImpor: number;
    PilihanSektor: { Nilai: string; Label: string }[];
    PilihanStatus: { Nilai: string; Label: string }[];
};

const kolom: KolomTabel<BarisKontakProspek>[] = [
    {
        id: 'Email',
        accessorKey: 'Email',
        header: 'Email',
        meta: { label: 'Email', prioritas: 'utama', wajib: true, kelasSel: 'font-mono' },
    },
    {
        id: 'Sektor',
        accessorKey: 'LabelSektor',
        header: 'Sektor',
        meta: { label: 'Sektor', prioritas: 'utama' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: k } }) => (
            <LabelStatus jenis={k.Status === 'Aktif' ? 'sukses' : 'netral'} teks={k.LabelStatus} />
        ),
    },
    {
        id: 'Sumber',
        accessorKey: 'Sumber',
        header: 'Sumber data',
        meta: { label: 'Sumber data', prioritas: 'rendah', kelasSel: 'max-w-[24rem] break-words text-teks-sekunder' },
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Diimpor',
        meta: { label: 'Diimpor', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row: { original: k } }) => FormatTanggalWaktu(k.DibuatPada),
    },
];

/**
 * Kontak prospek (D-216): daftar calon pelanggan untuk kampanye email. Impor CSV hanya membaca kolom Email dan Sektor.
 * Kontak yang sudah berhenti tidak bisa dimasukkan lagi lewat impor. Hanya Super Admin.
 */
export default function Kontak({
    Kontak,
    Ringkasan,
    MaksBarisDaftar,
    MaksBarisImpor,
    PilihanSektor,
    PilihanStatus,
}: PropsKontak) {
    const formulir = useForm<{ Berkas: File | null; Sumber: string }>({ Berkas: null, Sumber: '' });
    const [berkas, AturBerkas] = useState<File[]>([]);
    const [hentikan, AturHentikan] = useState<BarisKontakProspek | null>(null);
    const [hapus, AturHapus] = useState<BarisKontakProspek | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const Impor = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kampanye-prospek/kontak/impor', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                formulir.reset();
                AturBerkas([]);
            },
        });
    };

    const Selesaikan = () => {
        AturMemproses(false);
        AturHentikan(null);
        AturHapus(null);
    };

    return (
        <TataLetakPengelola judul="Tenant">
            <TabTenant />
            <NavigasiKampanye />
            <Panel tingkat="h3" judul="Impor kontak">
                <form onSubmit={Impor} noValidate className="flex flex-col gap-4">
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Berkas CSV dengan dua kolom bernama <strong>Email</strong> dan <strong>Sektor</strong> (kode
                        atau nama sektor Payoung). Kolom lain diabaikan dan tidak disimpan. Alamat yang sudah ada,
                        termasuk yang pernah berhenti berlangganan, tidak dimasukkan lagi. Paling banyak{' '}
                        {MaksBarisImpor.toLocaleString('id-ID')} baris per berkas.
                    </p>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <BidangBerkas
                            label="Berkas CSV"
                            berkas={berkas}
                            saatBerubah={(daftar) => {
                                AturBerkas(daftar);
                                formulir.setData('Berkas', daftar[0] ?? null);
                            }}
                            ekstensi={['csv', 'txt']}
                            maksimal={1}
                            ukuranMaksimalKb={2048}
                            galat={formulir.errors.Berkas}
                            wajib
                        />
                        <BidangTeks
                            label="Sumber data"
                            keterangan="Dicantumkan di setiap email sebagai asal alamat, misal: Data Pelaku Ekonomi Kreatif di Kota Pontianak (data.go.id)."
                            nilai={formulir.data.Sumber}
                            saatBerubah={(nilai) => formulir.setData('Sumber', nilai)}
                            galat={formulir.errors.Sumber}
                            maxLength={160}
                            required
                        />
                    </div>
                    <div>
                        <Tombol
                            type="submit"
                            memproses={formulir.processing}
                            disabled={formulir.data.Berkas === null || formulir.data.Sumber.trim().length < 5}
                        >
                            Impor kontak
                        </Tombol>
                    </div>
                </form>
            </Panel>
            <p className="text-isi text-teks-sekunder tabular-nums">
                {Ringkasan.Total.toLocaleString('id-ID')} kontak | {Ringkasan.Aktif.toLocaleString('id-ID')} aktif |{' '}
                {Ringkasan.Berhenti.toLocaleString('id-ID')} berhenti atau tidak aktif
                {Ringkasan.Total > MaksBarisDaftar
                    ? ` | daftar menampilkan ${MaksBarisDaftar.toLocaleString('id-ID')} kontak terbaru`
                    : ''}
            </p>
            <TabelData
                id="pengelola-kontak-prospek"
                label="Kontak prospek"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Kontak }}
                ambilIdBaris={(k) => k.Uuid}
                cari="Cari alamat email"
                saring={[
                    {
                        id: 'Sektor',
                        label: 'Sektor',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanSektor.map((s) => ({ nilai: s.Label, label: s.Label })),
                    },
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanStatus.map((s) => ({ nilai: s.Nilai, label: s.Label })),
                    },
                ]}
                aksiBaris={(k) => (
                    <>
                        {k.Status === 'Aktif' ? (
                            <DropdownMenuItem onSelect={() => AturHentikan(k)}>Tandai berhenti</DropdownMenuItem>
                        ) : null}
                        <DropdownMenuItem onSelect={() => AturHapus(k)}>Hapus kontak</DropdownMenuItem>
                    </>
                )}
                labelBaris={(k) => `Aksi ${k.Email}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kontak. Impor berkas CSV berisi Email dan Sektor untuk memulai.',
                }}
            />
            {hentikan ? (
                <DialogKonfirmasi
                    judul="Tandai berhenti?"
                    labelAksi="Tandai berhenti"
                    memproses={memproses}
                    saatKonfirmasi={() =>
                        router.put(
                            `/kampanye-prospek/kontak/${hentikan.Uuid}/henti`,
                            {},
                            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: Selesaikan },
                        )
                    }
                    saatBatal={() => AturHentikan(null)}
                >
                    {hentikan.Email} tidak akan dikirimi kampanye lagi dan tidak bisa dimasukkan kembali lewat impor.
                </DialogKonfirmasi>
            ) : null}
            {hapus ? (
                <DialogKonfirmasi
                    judul="Hapus kontak?"
                    labelAksi="Hapus kontak"
                    varian="bahaya"
                    memproses={memproses}
                    saatKonfirmasi={() =>
                        router.delete(`/kampanye-prospek/kontak/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: Selesaikan,
                        })
                    }
                    saatBatal={() => AturHapus(null)}
                >
                    {hapus.Email} dihapus beserta riwayat kirimnya. Pakai ini hanya bila orangnya meminta datanya
                    dihapus: alamat yang dihapus bisa masuk lagi lewat impor, sedangkan yang ditandai berhenti tidak.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakPengelola>
    );
}
