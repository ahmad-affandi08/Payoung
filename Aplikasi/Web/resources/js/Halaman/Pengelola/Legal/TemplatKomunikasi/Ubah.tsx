import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabLegal from '@/Komponen/Pengelola/Legal/TabLegal';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { labelStatusBsp, type EditorTemplat, type VariabelTemplat } from '@/Tipe/TemplatKomunikasi';

/** Mengganti `{Nama}` dengan contoh nilainya; variabel yang tidak dikenal dibiarkan apa adanya (seperti di server). */
export function IsiPratinjau(teks: string, variabel: VariabelTemplat[]): string {
    const contoh = new Map(variabel.map((v) => [v.Nama, v.Contoh]));

    return teks.replace(/\{([A-Za-z]+)\}/g, (cocok, nama: string) => contoh.get(nama) ?? cocok);
}

const opsiBsp = [
    { Nilai: '', Label: 'Belum diajukan' },
    { Nilai: 'MenungguPersetujuan', Label: labelStatusBsp.MenungguPersetujuan },
    { Nilai: 'Disetujui', Label: labelStatusBsp.Disetujui },
    { Nilai: 'Ditolak', Label: labelStatusBsp.Ditolak },
];

/** Menyusun draf, menerbitkan versi baru, dan mencatat status BSP satu templat komunikasi (PGL-07). */
export default function HalamanUbahTemplatKomunikasi({
    Editor,
    Izin,
}: {
    Editor: EditorTemplat;
    Izin: { Kelola: boolean };
}) {
    const email = Editor.Kanal.Nilai === 'Email';
    const notifikasi = Editor.Kanal.Nilai === 'Notifikasi';
    const adaJudul = email || notifikasi;
    const sumber = Editor.Draf ?? Editor.Terbit ?? { ...Editor.Bawaan, Uuid: '', Versi: 0 };
    const formulir = useForm({ Subjek: sumber.Subjek ?? '', Judul: sumber.Judul ?? '', Isi: sumber.Isi });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [konfirmasi, AturKonfirmasi] = useState<'terbit' | 'hapus' | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const alamat = `/template-komunikasi/${Editor.Peristiwa.Kode}/${Editor.Kanal.Nilai.toLowerCase()}/${Editor.Bahasa.Nilai}`;
    const terakhirTerbit = Editor.Terbit;
    const bsp = useForm({ StatusBsp: terakhirTerbit?.StatusBsp ?? '', CatatanBsp: terakhirTerbit?.CatatanBsp ?? '' });
    const galatBsp = bsp.errors as Record<string, string | undefined>;

    const Simpan = (p: FormEvent) => {
        p.preventDefault();
        formulir.put(alamat, { preserveScroll: true, onSuccess: () => formulir.setDefaults() });
    };
    const Jalankan = (metode: 'post' | 'delete', url: string) => {
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => {
                AturMemproses(false);
                AturKonfirmasi(null);
            },
        };

        if (metode === 'post') {
            router.post(url, {}, opsi);
        } else {
            router.delete(url, opsi);
        }
    };
    const SimpanBsp = (p: FormEvent) => {
        p.preventDefault();

        if (terakhirTerbit) {
            bsp.put(`/template-komunikasi/${terakhirTerbit.Uuid}/status-bsp`, { preserveScroll: true });
        }
    };
    const PakaiBawaan = () => {
        formulir.setData({
            Subjek: Editor.Bawaan.Subjek ?? '',
            Judul: Editor.Bawaan.Judul ?? '',
            Isi: Editor.Bawaan.Isi,
        });
    };

    return (
        <TataLetakPengelola
            judul={`${Editor.Peristiwa.Label} | ${Editor.Kanal.Label} | ${Editor.Bahasa.Label}`}
            jejak={[{ label: 'Templat pesan', href: '/template-komunikasi' }]}
        >
            <TabLegal />
            <p className="max-w-3xl text-isi text-teks-sekunder">{Editor.Peristiwa.Keterangan}</p>
            <div className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder">
                <LabelStatus
                    jenis={Editor.Terbit ? 'sukses' : 'netral'}
                    teks={Editor.Terbit ? `Dipakai: versi ${Editor.Terbit.Versi}` : 'Dipakai: teks bawaan'}
                />
                {Editor.Draf ? <LabelStatus jenis="peringatan" teks={`Draf versi ${Editor.Draf.Versi}`} /> : null}
            </div>
            {konfirmasi === 'terbit' && Editor.Draf ? (
                <DialogKonfirmasi
                    judul={`Terbitkan versi ${Editor.Draf.Versi}?`}
                    labelAksi="Terbitkan"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Jalankan('post', `/template-komunikasi/${Editor.Draf?.Uuid}/terbitkan`)}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>
                        Pesan berikutnya ke Owner memakai teks ini. Versi yang terbit tidak bisa diubah atau dihapus;
                        perubahan lanjutan menjadi versi baru.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'hapus' && Editor.Draf ? (
                <DialogKonfirmasi
                    judul={`Hapus draf versi ${Editor.Draf.Versi}?`}
                    labelAksi="Hapus draf"
                    memproses={memproses}
                    saatKonfirmasi={() => Jalankan('delete', `/template-komunikasi/${Editor.Draf?.Uuid}`)}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>Draf dihapus permanen. Versi terbit tidak terpengaruh.</p>
                </DialogKonfirmasi>
            ) : null}
            <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <form onSubmit={Simpan} className="flex flex-col gap-4" noValidate>
                    <Panel
                        judul={Editor.Draf ? `Draf versi ${Editor.Draf.Versi}` : 'Draf baru'}
                        className="flex flex-col gap-4"
                    >
                        {email ? (
                            <BidangTeks
                                label="Subjek email"
                                nilai={d.Subjek}
                                saatBerubah={(v) => formulir.setData('Subjek', v)}
                                galat={galat.Subjek}
                                maxLength={Editor.Batas.Subjek}
                                required
                            />
                        ) : null}
                        {adaJudul ? (
                            <BidangTeks
                                label={notifikasi ? 'Judul notifikasi' : 'Judul di badan email'}
                                nilai={d.Judul}
                                saatBerubah={(v) => formulir.setData('Judul', v)}
                                galat={galat.Judul}
                                maxLength={Editor.Batas.Judul}
                                required
                            />
                        ) : null}
                        <BidangTeksPanjang
                            label={email ? 'Isi email' : notifikasi ? 'Isi notifikasi' : 'Isi pesan WhatsApp'}
                            keterangan={`${d.Isi.length}/${Editor.Batas.Isi}. Tulis variabel dengan kurung kurawal, misalnya {NamaPemilik}.`}
                            nilai={d.Isi}
                            saatBerubah={(v) => formulir.setData('Isi', v)}
                            galat={galat.Isi}
                            maksimal={Editor.Batas.Isi}
                            baris={email ? 8 : 6}
                            required
                        />
                        {Izin.Kelola ? (
                            <BilahAksiForm>
                                <Tombol type="submit" memproses={formulir.processing}>
                                    Simpan draf
                                </Tombol>
                                <Tombol varian="sekunder" onClick={PakaiBawaan}>
                                    Isi dengan teks bawaan
                                </Tombol>
                                {Editor.Draf ? (
                                    <>
                                        <Tombol
                                            varian="sekunder"
                                            disabled={formulir.isDirty}
                                            title={formulir.isDirty ? 'Simpan dulu' : undefined}
                                            onClick={() => AturKonfirmasi('terbit')}
                                        >
                                            Terbitkan draf
                                        </Tombol>
                                        <Tombol varian="sekunder" onClick={() => AturKonfirmasi('hapus')}>
                                            Hapus draf
                                        </Tombol>
                                    </>
                                ) : null}
                            </BilahAksiForm>
                        ) : null}
                    </Panel>
                    <Panel judul="Pratinjau dengan data contoh">
                        {email ? (
                            <p className="text-isi font-semibold text-teks-utama">
                                {IsiPratinjau(d.Subjek, Editor.Variabel)}
                            </p>
                        ) : null}
                        {adaJudul ? (
                            <p className="text-subjudul font-semibold text-teks-utama">
                                {IsiPratinjau(d.Judul, Editor.Variabel)}
                            </p>
                        ) : null}
                        <p className="whitespace-pre-line text-isi text-teks-utama">
                            {IsiPratinjau(d.Isi, Editor.Variabel)}
                        </p>
                    </Panel>
                </form>
                <div className="flex flex-col gap-4">
                    <Panel judul="Variabel">
                        <ul className="flex flex-col divide-y divide-garis">
                            {Editor.Variabel.map((v) => (
                                <li key={v.Nama} className="flex flex-col gap-1 py-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <code className="font-mono text-isi text-teks-utama">{`{${v.Nama}}`}</code>
                                        {v.Wajib ? <LabelStatus jenis="peringatan" teks="Wajib" /> : null}
                                    </span>
                                    <span className="text-keterangan text-teks-sekunder">{v.Keterangan}</span>
                                    <span className="text-keterangan text-teks-sekunder">Contoh: {v.Contoh}</span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    {!email && terakhirTerbit ? (
                        <Panel
                            judul="Status persetujuan BSP"
                            keterangan={`Versi ${terakhirTerbit.Versi}. Dicatat setelah templat diajukan ke penyedia WhatsApp.`}
                        >
                            <form onSubmit={SimpanBsp} className="flex flex-col gap-3" noValidate>
                                <BidangPilihan
                                    label="Status"
                                    nilai={bsp.data.StatusBsp}
                                    opsi={opsiBsp}
                                    saatBerubah={(v) => bsp.setData('StatusBsp', v)}
                                    galat={galatBsp.StatusBsp}
                                    disabled={!Izin.Kelola}
                                />
                                <BidangTeksPanjang
                                    label="Catatan"
                                    nilai={bsp.data.CatatanBsp}
                                    saatBerubah={(v) => bsp.setData('CatatanBsp', v)}
                                    galat={galatBsp.CatatanBsp}
                                    maksimal={500}
                                    baris={3}
                                />
                                {Izin.Kelola ? (
                                    <Tombol type="submit" varian="sekunder" memproses={bsp.processing}>
                                        Catat status BSP
                                    </Tombol>
                                ) : null}
                            </form>
                        </Panel>
                    ) : null}
                    <Panel judul="Riwayat versi terbit">
                        {Editor.Riwayat.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">
                                Belum ada versi terbit. Pesan memakai teks bawaan.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-garis">
                                {Editor.Riwayat.map((versi) => (
                                    <li key={versi.Uuid} className="flex flex-col gap-1 py-2">
                                        <span className="font-semibold text-teks-utama">Versi {versi.Versi}</span>
                                        <span className="text-keterangan text-teks-sekunder">
                                            Terbit {FormatTanggalWaktu(versi.DiterbitkanPada)}
                                            {versi.StatusBsp ? ` | BSP: ${labelStatusBsp[versi.StatusBsp]}` : ''}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </TataLetakPengelola>
    );
}
