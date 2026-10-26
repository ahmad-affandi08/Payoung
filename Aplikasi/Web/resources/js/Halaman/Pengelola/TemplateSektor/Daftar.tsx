import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type RingkasanTemplate = {
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    DinonaktifkanPada: string | null;
    VersiTerbit: { Versi: number; DiterbitkanPada: string | null } | null;
    VersiDraf: { Versi: number; Lolos: boolean; SudahDivalidasi: boolean } | null;
    VersiTerbaru: number | null;
};

/** Alamat editor versi yang dibuka: draf bila ada, selain itu versi terbit/terbaru. */
function AlamatVersiBuka(template: RingkasanTemplate): string {
    const versi = template.VersiDraf?.Versi ?? template.VersiTerbit?.Versi ?? template.VersiTerbaru;

    return versi ? `/template-sektor/${encodeURIComponent(template.Kode)}/versi/${versi}` : '';
}

const kolom: KolomTabel<RingkasanTemplate>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Template',
        meta: { label: 'Template', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: template } }) => (
            <>
                <span className="block font-semibold text-teks-utama">{template.Nama}</span>
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{template.Kode}</span>
                {template.Keterangan ? (
                    <span className="block text-keterangan font-normal text-teks-sekunder">{template.Keterangan}</span>
                ) : null}
            </>
        ),
    },
    {
        id: 'VersiTerbit',
        accessorKey: 'VersiTerbit',
        header: 'Versi terbit',
        enableSorting: false,
        meta: { label: 'Versi terbit', prioritas: 'penting' },
        cell: ({ row: { original: template } }) =>
            template.VersiTerbit ? (
                <>
                    <span className="block tabular-nums">Versi {template.VersiTerbit.Versi}</span>
                    <span className="block text-keterangan text-teks-sekunder">
                        Terbit {FormatTanggal(template.VersiTerbit.DiterbitkanPada)}
                    </span>
                </>
            ) : (
                <LabelStatus jenis="peringatan" teks="Belum terbit" />
            ),
    },
    {
        id: 'Status',
        accessorFn: (template) => (template.DinonaktifkanPada === null ? 'Aktif' : 'Dinonaktifkan'),
        header: 'Di pilihan tenant',
        enableSorting: false,
        meta: { label: 'Di pilihan tenant', prioritas: 'penting' },
        cell: ({ row: { original: template } }) =>
            template.DinonaktifkanPada === null ? (
                template.VersiTerbit ? (
                    <LabelStatus jenis="sukses" teks="Ditawarkan" />
                ) : (
                    <span className="text-teks-sekunder">Belum terbit</span>
                )
            ) : (
                <div className="flex flex-col items-start gap-1">
                    <LabelStatus jenis="peringatan" teks="Dinonaktifkan" />
                    <span className="text-keterangan text-teks-sekunder">
                        Sejak {FormatTanggal(template.DinonaktifkanPada)}
                    </span>
                </div>
            ),
    },
    {
        id: 'Draf',
        header: 'Draf',
        enableSorting: false,
        meta: { label: 'Draf', prioritas: 'penting' },
        cell: ({ row: { original: template } }) =>
            template.VersiDraf ? (
                <div className="flex flex-col items-start gap-1">
                    <span className="tabular-nums">Versi {template.VersiDraf.Versi}</span>
                    <LabelStatus
                        jenis={template.VersiDraf.Lolos ? 'sukses' : 'bahaya'}
                        teks={
                            template.VersiDraf.Lolos
                                ? 'Lolos validasi'
                                : template.VersiDraf.SudahDivalidasi
                                  ? 'Belum lolos validasi'
                                  : 'Belum divalidasi'
                        }
                    />
                </div>
            ) : (
                <span className="text-teks-sekunder">Tidak ada</span>
            ),
    },
];

/** Daftar template sektor berversi (P-03), TabelData D-16. */
export default function HalamanDaftarTemplateSektor({ Template }: { Template: RingkasanTemplate[] }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehBuat = PunyaIzin(props.Pengguna, IzinPengelola.TemplateIsiUbah);
    const bolehTerbitkan = PunyaIzin(props.Pengguna, IzinPengelola.TemplateTerbitkan);
    const [buatBaru, AturBuatBaru] = useState(false);
    const [nonaktifkan, AturNonaktifkan] = useState<RingkasanTemplate | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const KirimKeaktifan = (template: RingkasanTemplate, tindakan: 'nonaktifkan' | 'aktifkan') => {
        router.post(
            `/template-sektor/${encodeURIComponent(template.Kode)}/${tindakan}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturNonaktifkan(null),
            },
        );
    };

    return (
        <TataLetakPengelola judul="Template sektor">
            <p className="text-isi text-teks-sekunder">
                Paket konfigurasi yang diterapkan saat tenant onboarding. Versi terbit tidak diubah. Perbaikan dibuat
                sebagai draf versi baru. Tenant lama tidak berubah tanpa persetujuannya.
            </p>
            {nonaktifkan ? (
                <DialogKonfirmasi
                    judul={`Nonaktifkan ${nonaktifkan.Nama}?`}
                    labelAksi="Nonaktifkan"
                    memproses={memproses}
                    saatKonfirmasi={() => KirimKeaktifan(nonaktifkan, 'nonaktifkan')}
                    saatBatal={() => AturNonaktifkan(null)}
                >
                    <p>
                        Sektor ini tidak lagi muncul di pilihan tenant baru. Tenant yang sudah memakainya tidak berubah,
                        dan datanya tetap tersimpan. Anda bisa mengaktifkannya kembali kapan saja.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {buatBaru ? <FormBuatTemplate template={Template} saatSelesai={() => AturBuatBaru(false)} /> : null}
            <AksiHalaman>
                {bolehBuat && !buatBaru ? <Tombol onClick={() => AturBuatBaru(true)}>Buat template</Tombol> : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-template-sektor"
                label="Daftar template sektor"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Template }}
                ambilIdBaris={(template) => template.Kode}
                urutBawaan="Nama"
                cari="Cari nama atau kode template"
                saring={[{ id: 'VersiTerbit', label: 'Versi terbit', jenis: 'ya', labelAktif: 'Sudah terbit' }]}
                alamatDetail={AlamatVersiBuka}
                aksiBaris={(template) => (
                    <>
                        {AlamatVersiBuka(template) ? (
                            <DropdownMenuItem asChild>
                                <Link href={AlamatVersiBuka(template)}>Buka template</Link>
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem disabled>Belum ada versi</DropdownMenuItem>
                        )}
                        {bolehTerbitkan && template.DinonaktifkanPada === null && template.VersiTerbit ? (
                            <DropdownMenuItem onSelect={() => AturNonaktifkan(template)}>
                                Nonaktifkan sektor
                            </DropdownMenuItem>
                        ) : null}
                        {bolehTerbitkan && template.DinonaktifkanPada !== null ? (
                            <DropdownMenuItem onSelect={() => KirimKeaktifan(template, 'aktifkan')}>
                                Aktifkan kembali
                            </DropdownMenuItem>
                        ) : null}
                    </>
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada template sektor. Buat template pertama, misal Retail umum (RTL-GEN).',
                }}
            />
        </TataLetakPengelola>
    );
}

type PropsFormBuat = { template: RingkasanTemplate[]; saatSelesai: () => void };

function FormBuatTemplate({ template, saatSelesai }: PropsFormBuat) {
    const formulir = useForm({ Kode: '', Nama: '', Keterangan: '', KodeTemplateDasar: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/template-sektor', { preserveScroll: true });
    };

    return (
        <DialogFormulir
            judul="Buat template sektor"
            saatTutup={saatSelesai}
            lebar="lebar"
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangTeks
                    label="Kode sektor"
                    kode
                    keterangan="Tiga huruf kelompok dan tiga huruf sektor, misal FNB-RST. Tidak bisa diubah."
                    nilai={formulir.data.Kode}
                    saatBerubah={(nilai) => formulir.setData('Kode', nilai.toUpperCase())}
                    galat={formulir.errors.Kode}
                    required
                />
                <BidangTeks
                    label="Nama"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    required
                />
                <BidangTeks
                    label="Keterangan (opsional)"
                    nilai={formulir.data.Keterangan}
                    saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                    galat={formulir.errors.Keterangan}
                />
                <BidangPilihan
                    label="Salin isi dari"
                    nilai={formulir.data.KodeTemplateDasar}
                    kosong="Mulai kosong"
                    opsi={template.map((item) => ({ Nilai: item.Kode, Label: `${item.Nama} (${item.Kode})` }))}
                    saatBerubah={(nilai) => formulir.setData('KodeTemplateDasar', nilai)}
                    galat={formulir.errors.KodeTemplateDasar}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Buat draf versi 1
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
