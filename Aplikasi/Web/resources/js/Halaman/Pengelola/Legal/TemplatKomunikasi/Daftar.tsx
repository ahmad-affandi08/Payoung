import { Link } from '@inertiajs/react';

import Panel from '@/Komponen/Kelola/Panel';
import TabLegal from '@/Komponen/Pengelola/Legal/TabLegal';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { labelStatusBsp, type PeristiwaTemplat, type SelTemplat } from '@/Tipe/TemplatKomunikasi';

export function AlamatEditor(kode: string, sel: Pick<SelTemplat, 'Kanal' | 'Bahasa'>): string {
    return `/template-komunikasi/${kode}/${sel.Kanal.toLowerCase()}/${sel.Bahasa}`;
}

/** Templat pesan platform ke Owner tenant (PGL-07): sumber teks yang sedang dipakai per peristiwa, kanal, dan bahasa. */
export default function HalamanDaftarTemplatKomunikasi({ Peristiwa }: { Peristiwa: PeristiwaTemplat[] }) {
    return (
        <TataLetakPengelola judul="Templat pesan">
            <TabLegal />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Teks pesan yang dikirim Payoung ke Owner. Tanpa versi terbit, pesan memakai teks bawaan. Versi terbit
                tidak bisa diubah. Perubahan selalu menjadi versi baru.
            </p>
            {Peristiwa.map((peristiwa) => (
                <Panel key={peristiwa.Kode} judul={peristiwa.Label} keterangan={peristiwa.Keterangan}>
                    <ul className="flex flex-col divide-y divide-garis">
                        {peristiwa.Sel.map((sel) => (
                            <li
                                key={`${sel.Kanal}-${sel.Bahasa}`}
                                className="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <Link
                                    href={AlamatEditor(peristiwa.Kode, sel)}
                                    aria-label={`Ubah ${peristiwa.Label} | ${sel.KanalLabel} | ${sel.BahasaLabel}`}
                                    className="font-semibold text-teks-utama underline outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                >
                                    {sel.KanalLabel} | {sel.BahasaLabel}
                                </Link>
                                <span className="flex flex-wrap items-center gap-2">
                                    <LabelStatus
                                        jenis={sel.Sumber === 'Templat' ? 'sukses' : 'netral'}
                                        teks={sel.Sumber === 'Templat' ? `Versi ${sel.VersiTerbit}` : 'Teks bawaan'}
                                    />
                                    {sel.AdaDraf ? <LabelStatus jenis="peringatan" teks="Ada draf" /> : null}
                                    {sel.StatusBsp ? (
                                        <LabelStatus
                                            jenis={
                                                sel.StatusBsp === 'Disetujui'
                                                    ? 'sukses'
                                                    : sel.StatusBsp === 'Ditolak'
                                                      ? 'bahaya'
                                                      : 'peringatan'
                                            }
                                            teks={`BSP: ${labelStatusBsp[sel.StatusBsp]}`}
                                        />
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            ))}
        </TataLetakPengelola>
    );
}
