import { useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TabRilis from '@/Komponen/Pengelola/TabRilis';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import {
    IzinPengelola,
    PunyaIzin,
    type AturanFlagFitur,
    type PropsBersamaPengelola,
    type PropsFlagFitur,
} from '@/Tipe/Pengelola';

const opsiCakupan = [
    { Nilai: 'Global', Label: 'Global (semua tenant)' },
    { Nilai: 'Paket', Label: 'Per paket' },
    { Nilai: 'Tenant', Label: 'Per tenant' },
    { Nilai: 'Persentase', Label: 'Persentase tenant' },
];

/** Teks nilai aturan: hidup/mati, persen tenant, atau kill switch. */
export function AmbilNilaiFlag(a: AturanFlagFitur): { teks: string; jenis: 'sukses' | 'bahaya' | 'peringatan' } {
    if (a.Cakupan === 'Persentase') {
        return { teks: `Hidup untuk ${String(a.Persen ?? 0)}% tenant`, jenis: 'peringatan' };
    }
    if (a.Cakupan === 'Global' && !a.Nilai) {
        return { teks: 'Kill switch: mati untuk semua', jenis: 'bahaya' };
    }
    return a.Nilai ? { teks: 'Hidup', jenis: 'sukses' } : { teks: 'Mati', jenis: 'bahaya' };
}

const kolom: KolomTabel<AturanFlagFitur>[] = [
    {
        id: 'Kunci',
        accessorKey: 'Kunci',
        header: 'Kunci',
        meta: { label: 'Kunci', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label text-teks-utama' },
    },
    {
        id: 'Cakupan',
        accessorKey: 'Cakupan',
        header: 'Cakupan',
        meta: { label: 'Cakupan', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (a.Objek ? `${a.Cakupan}: ${a.Objek}` : a.Cakupan),
    },
    {
        id: 'Nilai',
        accessorFn: (a) => AmbilNilaiFlag(a).teks,
        header: 'Nilai',
        meta: { label: 'Nilai', prioritas: 'penting' },
        cell: ({ row }) => {
            const nilai = AmbilNilaiFlag(row.original);
            return <LabelStatus jenis={nilai.jenis} teks={nilai.teks} />;
        },
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Alasan terakhir',
        meta: { label: 'Alasan terakhir', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'DiubahPada',
        accessorKey: 'DiubahPada',
        header: 'Diubah',
        meta: { label: 'Diubah', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: a } }) =>
            `${a.DiubahPada ? FormatTanggalWaktu(a.DiubahPada) : '—'}${a.DiubahOleh ? ` | ${a.DiubahOleh}` : ''}`,
    },
];

type Dialog = { jenis: 'simpan'; awal: Partial<AturanFlagFitur> | null } | { jenis: 'hapus'; aturan: AturanFlagFitur };

/**
 * Flag fitur (P-10, PGL-18): aturan Global, per paket, per tenant, atau persentase tenant untuk peluncuran bertahap.
 * Kill switch = aturan Global bernilai mati, mengalahkan semua aturan lain. Setiap perubahan wajib beralasan dan
 * tercatat di log audit (BR-P10.3).
 */
export default function HalamanFlagFitur({ Aturan, OpsiKunci, OpsiPaket, OpsiTenant }: PropsFlagFitur) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.FlagFiturKelola);
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);

    return (
        <TataLetakPengelola judul="Rilis aplikasi">
            <TabRilis />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Urutan: kill switch (Global mati) mengalahkan semua aturan, lalu aturan tenant, paket, persentase, dan
                Global hidup. Kunci tanpa aturan dianggap hidup.
            </p>
            <AksiHalaman
                {...(bolehKelola
                    ? {
                          utama: (
                              <Tombol onClick={() => AturDialog({ jenis: 'simpan', awal: null })}>Tambah aturan</Tombol>
                          ),
                          lainnya: [
                              {
                                  label: 'Kill switch',
                                  saatKlik: () =>
                                      AturDialog({ jenis: 'simpan', awal: { Cakupan: 'Global', Nilai: false } }),
                              },
                          ],
                      }
                    : {})}
            />
            <TabelData
                id="pengelola-flag-fitur"
                label="Aturan flag fitur"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Aturan }}
                ambilIdBaris={(a) => a.Uuid}
                labelBaris={(a) => `${a.Kunci} ${a.Cakupan}${a.Objek ? ` ${a.Objek}` : ''}`}
                cari="Cari kunci"
                saring={[
                    {
                        id: 'Cakupan',
                        label: 'Cakupan',
                        jenis: 'pilihanBanyak',
                        opsi: opsiCakupan.map((o) => ({ nilai: o.Nilai, label: o.Nilai })),
                    },
                ]}
                {...(bolehKelola
                    ? {
                          aksiBaris: (a: AturanFlagFitur) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'simpan', awal: a })}>
                                      Ubah aturan
                                  </DropdownMenuItem>
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'hapus', aturan: a })}>
                                      Hapus aturan
                                  </DropdownMenuItem>
                              </>
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada aturan. Semua fitur mengikuti paket langganan tenant.' }}
            />
            {dialog?.jenis === 'simpan' ? (
                <FormAturan
                    awal={dialog.awal}
                    opsiKunci={OpsiKunci}
                    opsiPaket={OpsiPaket}
                    opsiTenant={OpsiTenant}
                    saatSelesai={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'hapus' ? <FormHapus aturan={dialog.aturan} saatSelesai={Tutup} /> : null}
        </TataLetakPengelola>
    );
}

function FormAturan({
    awal,
    opsiKunci,
    opsiPaket,
    opsiTenant,
    saatSelesai,
}: {
    awal: Partial<AturanFlagFitur> | null;
    opsiKunci: PropsFlagFitur['OpsiKunci'];
    opsiPaket: PropsFlagFitur['OpsiPaket'];
    opsiTenant: PropsFlagFitur['OpsiTenant'];
    saatSelesai: () => void;
}) {
    const ubah = awal?.Uuid !== undefined;
    const formulir = useForm({
        Kunci: awal?.Kunci ?? '',
        Cakupan: awal?.Cakupan ?? 'Global',
        Objek: ubah
            ? ((awal.Cakupan === 'Paket' ? opsiPaket : opsiTenant).find((o) => o.Label === awal.Objek)?.Nilai ?? '')
            : '',
        Nilai: awal?.Nilai ?? true,
        Persen: awal?.Persen === null || awal?.Persen === undefined ? '' : String(awal.Persen),
        Alasan: '',
    });
    const cakupan = formulir.data.Cakupan;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/flag-fitur', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={ubah ? `Ubah aturan ${awal.Kunci ?? ''}` : 'Tambah aturan flag'}
            keterangan="Perubahan langsung berlaku di back-office dan dibaca aplikasi saat memuat konfigurasi."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Kunci"
                        kode
                        keterangan={`Kunci katalog fitur atau flag aplikasi, misal ${opsiKunci[0]?.Nilai ?? 'pos.mode-meja'}.`}
                        nilai={formulir.data.Kunci}
                        saatBerubah={(nilai) => formulir.setData('Kunci', nilai.toLowerCase().trim())}
                        galat={formulir.errors.Kunci}
                        required
                        disabled={ubah}
                    />
                </div>
                <BidangPilihan
                    label="Cakupan"
                    nilai={cakupan}
                    opsi={opsiCakupan}
                    saatBerubah={(nilai) => {
                        formulir.setData('Cakupan', nilai as AturanFlagFitur['Cakupan']);
                        formulir.setData('Objek', '');
                    }}
                    galat={formulir.errors.Cakupan}
                    disabled={ubah}
                    required
                />
                {cakupan === 'Paket' || cakupan === 'Tenant' ? (
                    <BidangPilihan
                        label={cakupan === 'Paket' ? 'Paket' : 'Tenant'}
                        nilai={formulir.data.Objek}
                        opsi={cakupan === 'Paket' ? opsiPaket : opsiTenant}
                        saatBerubah={(nilai) => formulir.setData('Objek', nilai)}
                        galat={formulir.errors.Objek}
                        disabled={ubah}
                        required
                    />
                ) : null}
                {cakupan === 'Persentase' ? (
                    <BidangTeks
                        label="Persen tenant"
                        inputMode="numeric"
                        keterangan="Tenant di luar persentase ini mendapat nilai mati."
                        nilai={formulir.data.Persen}
                        saatBerubah={(nilai) => formulir.setData('Persen', nilai.replace(/\D/g, '').slice(0, 3))}
                        galat={formulir.errors.Persen}
                        required
                    />
                ) : (
                    <BidangPilihan
                        label="Nilai"
                        nilai={formulir.data.Nilai ? 'Hidup' : 'Mati'}
                        opsi={[
                            { Nilai: 'Hidup', Label: 'Hidup' },
                            { Nilai: 'Mati', Label: 'Mati' },
                        ]}
                        saatBerubah={(nilai) => formulir.setData('Nilai', nilai === 'Hidup')}
                        galat={formulir.errors.Nilai}
                        required
                    />
                )}
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Alasan perubahan"
                        nilai={formulir.data.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        maksimal={500}
                        baris={3}
                        galat={formulir.errors.Alasan}
                        required
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan aturan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormHapus({ aturan, saatSelesai }: { aturan: AturanFlagFitur; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.delete(`/flag-fitur/${aturan.Uuid}`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Hapus aturan ${aturan.Kunci}`}
            keterangan="Tanpa aturan ini, nilai flag mengikuti aturan lain atau dianggap hidup."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan menghapus"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Hapus aturan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
