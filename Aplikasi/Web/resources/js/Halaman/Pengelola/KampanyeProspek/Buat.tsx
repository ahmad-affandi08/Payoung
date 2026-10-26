import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { PilihanSektorProspek } from '@/Tipe/KampanyeProspek';

type PropsBuat = {
    PilihanSektor: PilihanSektorProspek[];
    EmailKampanyeAktif: boolean;
    BatasBawaan: number;
    MaksBatas: number;
    PenandaSektor: string;
};

const MAKS_ISI = 3000;

/** Buat kampanye prospek (D-216): isi email, sektor penerima dengan hitungan kontak aktif, dan batas kirim per hari. */
export default function Buat({ PilihanSektor, EmailKampanyeAktif, BatasBawaan, MaksBatas, PenandaSektor }: PropsBuat) {
    const formulir = useForm({
        Nama: '',
        Subjek: '',
        Isi: '',
        Sektor: [] as string[],
        BatasPerHari: String(BatasBawaan),
    });
    const { data } = formulir;
    const penerima = PilihanSektor.filter((s) => data.Sektor.includes(s.Nilai)).reduce(
        (jumlah, s) => jumlah + s.Aktif,
        0,
    );
    const batas = Number(data.BatasPerHari);
    const hari = batas > 0 && penerima > 0 ? Math.ceil(penerima / batas) : 0;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((d) => ({ ...d, BatasPerHari: Number(d.BatasPerHari) }));
        formulir.post('/kampanye-prospek');
    };

    return (
        <TataLetakPengelola judul="Buat kampanye" jejak={[{ label: 'Kampanye prospek', href: '/kampanye-prospek' }]}>
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4">
                {!EmailKampanyeAktif ? (
                    <Pemberitahuan jenis="peringatan" judul="Email kampanye belum aktif">
                        Draf tetap bisa disimpan, tetapi baru bisa dijalankan setelah ada pengirim email di{' '}
                        <Link href="/integrasi">Integrasi › Email</Link> (atau Email kampanye untuk domain terpisah).
                    </Pemberitahuan>
                ) : null}
                <Panel tingkat="h3" judul="Email">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <BidangTeks
                            label="Nama kampanye"
                            keterangan="Hanya untuk Anda, tidak tampil di email."
                            nilai={data.Nama}
                            saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                            galat={formulir.errors.Nama}
                            maxLength={120}
                            required
                        />
                        <BidangTeks
                            label="Subjek email"
                            keterangan={`Boleh memakai ${PenandaSektor} untuk nama sektor penerima.`}
                            nilai={data.Subjek}
                            saatBerubah={(nilai) => formulir.setData('Subjek', nilai)}
                            galat={formulir.errors.Subjek}
                            maxLength={150}
                            required
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Isi email"
                        keterangan={`Teks biasa, baris kosong memisahkan paragraf. ${PenandaSektor} diganti nama sektor penerima. Sumber alamat dan tautan berhenti berlangganan ditambahkan otomatis di bagian bawah. ${String(data.Isi.length)} dari ${String(MAKS_ISI)} huruf.`}
                        nilai={data.Isi}
                        saatBerubah={(nilai) => formulir.setData('Isi', nilai)}
                        galat={formulir.errors.Isi}
                        baris={9}
                        maksimal={MAKS_ISI}
                        required
                    />
                </Panel>
                <Panel tingkat="h3" judul="Penerima">
                    {PilihanSektor.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            Belum ada kontak. <Link href="/kampanye-prospek/kontak">Impor kontak</Link> dulu, lalu
                            kembali ke sini.
                        </p>
                    ) : (
                        <GrupCentang
                            legenda="Sektor penerima"
                            opsi={PilihanSektor.map((s) => ({
                                nilai: s.Nilai,
                                label: `${s.Label} (${s.Aktif.toLocaleString('id-ID')} kontak aktif)`,
                            }))}
                            terpilih={data.Sektor}
                            saatBerubah={(nilai) => formulir.setData('Sektor', nilai)}
                            galat={formulir.errors.Sektor}
                            required
                        />
                    )}
                    <p role="status" className="text-isi font-semibold text-teks-utama tabular-nums">
                        {data.Sektor.length === 0
                            ? 'Pilih sedikitnya satu sektor.'
                            : `${penerima.toLocaleString('id-ID')} penerima aktif saat ini. Kontak yang sudah berhenti berlangganan tidak dihitung.`}
                    </p>
                </Panel>
                <Panel tingkat="h3" judul="Kecepatan kirim">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <BidangTeks
                            label="Batas kirim per hari"
                            keterangan={`Paling banyak ${String(MaksBatas)}. Mulai kecil lalu naikkan bila tidak ada keluhan.`}
                            nilai={data.BatasPerHari}
                            saatBerubah={(nilai) => formulir.setData('BatasPerHari', nilai.replace(/\D/g, ''))}
                            galat={formulir.errors.BatasPerHari}
                            inputMode="numeric"
                            required
                        />
                        <p className="text-isi text-teks-sekunder">
                            {hari > 0
                                ? `Dengan batas ini, ${penerima.toLocaleString('id-ID')} penerima selesai dalam sekitar ${String(hari)} hari kerja. Email dikirim sedikit demi sedikit pada pukul 08.00–17.00 WIB.`
                                : 'Email dikirim sedikit demi sedikit pada pukul 08.00–17.00 WIB.'}
                        </p>
                    </div>
                </Panel>
                <BilahAksiForm>
                    <Tombol
                        type="submit"
                        memproses={formulir.processing}
                        disabled={
                            data.Nama.trim().length < 3 ||
                            data.Subjek.trim().length < 3 ||
                            data.Isi.trim().length < 20 ||
                            data.Sektor.length === 0
                        }
                    >
                        Simpan sebagai draf
                    </Tombol>
                    <Tombol varian="sekunder" onClick={() => window.history.back()}>
                        Batal
                    </Tombol>
                </BilahAksiForm>
            </form>
        </TataLetakPengelola>
    );
}
