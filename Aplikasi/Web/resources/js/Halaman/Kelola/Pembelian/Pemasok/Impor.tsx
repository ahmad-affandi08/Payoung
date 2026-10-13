import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsImporPemasok } from '@/Tipe/Pembelian';

const alamat = '/kelola/pembelian/pemasok/impor';

function FormatAngka(n: number): string {
    return n.toLocaleString('id-ID');
}

/**
 * Impor pemasok dari Excel/CSV (PLT-10, F31). Dua langkah supaya aman: **Periksa berkas** membaca semua baris tanpa
 * menyimpan apa pun, lalu **Impor** menyimpan pemasok baru dari berkas yang sama. Kode yang sudah terdaftar dilewati
 * (data di aplikasi tidak ditimpa); berkas tanpa kolom Kode memakai nama sebagai kunci dan kode dibuat otomatis.
 */
export default function HalamanImporPemasok({ Hasil, MaksimalBaris }: PropsImporPemasok) {
    const formulir = useForm<{ Berkas: File[]; Terapkan: boolean }>({ Berkas: [], Terapkan: false });
    const berkas = formulir.data.Berkas[0];
    const bolehTerapkan = Hasil !== null && !Hasil.Terapkan && Hasil.Baru > 0 && berkas !== undefined;

    const Kirim = (terapkan: boolean) => {
        formulir.transform((d) => ({ Berkas: d.Berkas[0] ?? null, Terapkan: terapkan }));
        formulir.post(alamat, { forceFormData: true, preserveState: true, preserveScroll: true });
    };

    const Periksa = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        Kirim(false);
    };

    return (
        <TataLetakAplikasi judul="Impor pemasok" jejak={[{ label: 'Pemasok', href: '/kelola/pembelian/pemasok' }]}>
            <Panel>
                <form onSubmit={Periksa} className="grid gap-6 lg:grid-cols-2 lg:items-start" noValidate>
                    <p className="text-isi text-teks-sekunder">
                        Baris pertama berisi judul kolom. Wajib: <strong>Nama</strong>. Opsional: Kode (tanpa kode,
                        dibuat otomatis PMS-0001 dan seterusnya), NamaKontak, NoHp, Email, Alamat, Npwp, Pkp (Ya/Tidak),
                        TerminHari (0 sampai 365 atau &quot;Tunai&quot;), NamaBank, NomorRekening, AtasNamaRekening,
                        Catatan. Paling banyak {FormatAngka(MaksimalBaris)} baris per berkas. Pemasok yang kodenya sudah
                        terdaftar dilewati, tidak ditimpa.{' '}
                        <a href={`${alamat}/templat`} className="text-brand underline">
                            Unduh templat
                        </a>
                    </p>
                    <div className="flex flex-col gap-4">
                        <BidangBerkas
                            label="Berkas pemasok (.xlsx atau .csv)"
                            berkas={formulir.data.Berkas}
                            ekstensi={['xlsx', 'csv']}
                            maksimal={1}
                            ukuranMaksimalKb={5120}
                            saatBerubah={(daftar) => formulir.setData('Berkas', daftar)}
                            galat={formulir.errors.Berkas}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Tombol
                                type="submit"
                                varian={bolehTerapkan ? 'sekunder' : 'utama'}
                                memproses={formulir.processing && !formulir.data.Terapkan}
                                disabled={berkas === undefined}
                            >
                                Periksa berkas
                            </Tombol>
                            {bolehTerapkan ? (
                                <Tombol onClick={() => Kirim(true)} memproses={formulir.processing}>
                                    {`Impor ${FormatAngka(Hasil.Baru)} pemasok baru`}
                                </Tombol>
                            ) : null}
                        </div>
                    </div>
                </form>
            </Panel>

            {Hasil !== null ? (
                <Panel judul={Hasil.Terapkan ? 'Hasil impor' : 'Hasil pemeriksaan'}>
                    <div className="flex flex-col gap-4">
                        <Pemberitahuan jenis={Hasil.Terapkan ? 'sukses' : Hasil.Bermasalah > 0 ? 'peringatan' : 'info'}>
                            {Hasil.Terapkan
                                ? `${FormatAngka(Hasil.Baru)} pemasok baru tersimpan dari ${Hasil.NamaBerkas}.`
                                : `${Hasil.NamaBerkas}: ${FormatAngka(Hasil.Baru)} pemasok siap diimpor. Belum ada yang disimpan.`}
                        </Pemberitahuan>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                            {[
                                ['Baris dibaca', Hasil.JumlahBaris],
                                [Hasil.Terapkan ? 'Pemasok baru' : 'Siap diimpor', Hasil.Baru],
                                ['Sudah terdaftar (dilewati)', Hasil.SudahAda],
                                ['Bermasalah (dilewati)', Hasil.Bermasalah],
                            ].map(([label, nilai]) => (
                                <div key={String(label)} className="flex flex-col gap-1">
                                    <dt className="text-teks-sekunder">{label}</dt>
                                    <dd className="font-semibold tabular-nums text-teks-utama">
                                        {FormatAngka(Number(nilai))}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {Hasil.Masalah.length > 0 ? (
                            <section aria-label="Baris bermasalah" className="flex flex-col gap-2">
                                <h3 className="text-subjudul font-semibold text-teks-utama">
                                    Baris bermasalah
                                    {Hasil.Bermasalah > Hasil.Masalah.length
                                        ? ` (${FormatAngka(Hasil.Masalah.length)} pertama dari ${FormatAngka(Hasil.Bermasalah)})`
                                        : ''}
                                </h3>
                                <ul className="flex flex-col divide-y divide-garis text-isi">
                                    {Hasil.Masalah.map((m) => (
                                        <li key={m.Baris} className="flex flex-wrap gap-x-3 py-2">
                                            <span className="font-mono tabular-nums text-teks-sekunder">
                                                Baris {m.Baris}
                                            </span>
                                            <span className="font-semibold break-words">{m.Nama || '—'}</span>
                                            <span className="text-teks-sekunder">{m.Pesan}</span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-keterangan text-teks-sekunder">
                                    Perbaiki baris ini di berkas lalu impor lagi: baris yang sudah masuk akan dilewati.
                                </p>
                            </section>
                        ) : null}
                    </div>
                </Panel>
            ) : null}
        </TataLetakAplikasi>
    );
}
