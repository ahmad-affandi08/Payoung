import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import KerangkaPelanggan from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';

type PengirimanKurir = {
    Uuid: string;
    Nomor: string;
    Status: 'Dikemas' | 'Dikirim' | 'Diterima' | 'Gagal' | 'Dibatalkan' | 'SiapKemas';
    LabelStatus: string;
    NamaPelanggan: string;
    /** Hanya untuk pengiriman yang masih berjalan. */
    NoHp: string | null;
    Alamat: string | null;
    Catatan: string | null;
    MetodePembayaran: string;
    Total: string;
    SudahDibayar: boolean;
    Baris: { NamaProduk: string; Jumlah: string }[];
    NamaPenerima: string | null;
    AdaBukti: boolean;
};

type PropsPortalKurir = {
    NamaToko: string;
    UrlLogo?: string | null;
    NamaKurir: string;
    AlamatDasar: string;
    Pengiriman: PengirimanKurir[];
};

const UKURAN_FOTO_KB = 8192;

function JenisStatus(status: PengirimanKurir['Status']): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    if (status === 'Diterima') return 'sukses';
    if (status === 'Gagal' || status === 'Dibatalkan') return 'bahaya';
    if (status === 'Dikirim') return 'peringatan';
    return 'netral';
}

/**
 * F-10 (v3.49) portal kurir: halaman HP untuk kurir tanpa akun Payoung, dibuka lewat tautan rahasia dari toko. Kurir
 * menandai berangkat, menyerahkan barang (nama penerima + foto bukti opsional), atau gagal mengirim (alasan).
 */
export default function HalamanPortalKurir({
    NamaToko,
    UrlLogo,
    NamaKurir,
    AlamatDasar,
    Pengiriman,
}: PropsPortalKurir) {
    const { props } = usePage<{ Kilat?: string | null; errors: Record<string, string> }>();
    const berjalan = Pengiriman.filter((p) => p.Status === 'Dikemas' || p.Status === 'Dikirim');
    const selesai = Pengiriman.filter((p) => p.Status !== 'Dikemas' && p.Status !== 'Dikirim');

    return (
        <KerangkaPelanggan toko={NamaToko} urlLogo={UrlLogo}>
            <Head title="Portal kurir" />
            <header className="border-b border-garis pb-4">
                <JudulHalaman>Pengiriman {NamaKurir}</JudulHalaman>
            </header>
            {props.Kilat ? <Pemberitahuan jenis="sukses">{props.Kilat}</Pemberitahuan> : null}
            {props.errors.Umum || props.errors.Status ? (
                <Pemberitahuan jenis="bahaya">{props.errors.Umum ?? props.errors.Status}</Pemberitahuan>
            ) : null}
            {berjalan.length === 0 ? (
                <p className="text-teks-sekunder">
                    Belum ada pengiriman untuk Anda. Tarik ke bawah untuk memuat ulang.
                </p>
            ) : (
                berjalan.map((p) => <KartuPengiriman key={p.Uuid} p={p} alamatDasar={AlamatDasar} />)
            )}
            {selesai.length > 0 ? (
                <section className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold">Selesai 24 jam terakhir</h2>
                    <ul className="divide-y divide-garis rounded-lg border border-garis bg-permukaan">
                        {selesai.map((p) => (
                            <li key={p.Uuid} className="flex flex-wrap items-center justify-between gap-2 p-3">
                                <span>
                                    <span className="font-mono">{p.Nomor}</span> | {p.NamaPelanggan}
                                    {p.NamaPenerima ? ` | diterima ${p.NamaPenerima}` : ''}
                                    {p.AdaBukti ? ' | ada foto' : ''}
                                </span>
                                <LabelStatus jenis={JenisStatus(p.Status)} teks={p.LabelStatus} />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </KerangkaPelanggan>
    );
}

function KartuPengiriman({ p, alamatDasar }: { p: PengirimanKurir; alamatDasar: string }) {
    const { props } = usePage<{ errors: Record<string, string> }>();
    const [mode, AturMode] = useState<'diam' | 'terima' | 'gagal'>('diam');
    const [penerima, AturPenerima] = useState('');
    const [foto, AturFoto] = useState<File[]>([]);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const alamat = `${alamatDasar}/pengiriman/${p.Uuid}`;
    const opsi = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onSuccess: () => AturMode('diam'),
    };
    const tagihCod = p.MetodePembayaran === 'Cod' && !p.SudahDibayar;

    return (
        <section
            aria-label={`Pesanan ${p.Nomor}`}
            className="flex flex-col gap-3 rounded-lg border border-garis bg-permukaan p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-mono text-label">{p.Nomor}</span>
                <LabelStatus jenis={JenisStatus(p.Status)} teks={p.LabelStatus} />
            </div>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-teks-sekunder">Penerima</dt>
                <dd>{p.NamaPelanggan}</dd>
                {p.NoHp ? (
                    <>
                        <dt className="text-teks-sekunder">Telepon</dt>
                        <dd>
                            <a className="text-brand underline" href={`tel:+${p.NoHp}`}>
                                +{p.NoHp}
                            </a>
                        </dd>
                    </>
                ) : null}
                {p.Alamat ? (
                    <>
                        <dt className="text-teks-sekunder">Alamat</dt>
                        <dd>{p.Alamat}</dd>
                    </>
                ) : null}
                {p.Catatan ? (
                    <>
                        <dt className="text-teks-sekunder">Catatan</dt>
                        <dd>{p.Catatan}</dd>
                    </>
                ) : null}
                <dt className="text-teks-sekunder">Pembayaran</dt>
                <dd>{tagihCod ? `Tagih tunai ${FormatRupiah(p.Total)}` : 'Sudah dibayar'}</dd>
            </dl>
            <ul className="text-keterangan text-teks-sekunder">
                {p.Baris.map((b, i) => (
                    <li key={`${b.NamaProduk}-${String(i)}`}>
                        {FormatJumlahStok(b.Jumlah)} × {b.NamaProduk}
                    </li>
                ))}
            </ul>
            {mode === 'terima' ? (
                <form
                    noValidate
                    className="flex flex-col gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `${alamat}/terima`,
                            { NamaPenerima: penerima, Foto: foto[0] ?? null },
                            { ...opsi, forceFormData: true },
                        );
                    }}
                >
                    <BidangTeks
                        label="Nama penerima"
                        nilai={penerima}
                        saatBerubah={AturPenerima}
                        galat={props.errors.NamaPenerima}
                        required
                    />
                    <BidangBerkas
                        label="Foto bukti serah terima"
                        berkas={foto}
                        saatBerubah={AturFoto}
                        ekstensi={['jpg', 'jpeg', 'png', 'webp']}
                        maksimal={1}
                        ukuranMaksimalKb={UKURAN_FOTO_KB}
                        galat={props.errors.Foto}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" memproses={memproses}>
                            Simpan serah terima
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturMode('diam')}>
                            Batal
                        </Tombol>
                    </div>
                </form>
            ) : mode === 'gagal' ? (
                <form
                    noValidate
                    className="flex flex-col gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`${alamat}/gagal`, { Alasan: alasan }, opsi);
                    }}
                >
                    <BidangTeks
                        label="Alasan gagal dikirim"
                        nilai={alasan}
                        saatBerubah={AturAlasan}
                        galat={props.errors.Alasan}
                        required
                    />
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" varian="bahaya" memproses={memproses}>
                            Tandai gagal
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturMode('diam')}>
                            Batal
                        </Tombol>
                    </div>
                </form>
            ) : p.Status === 'Dikemas' ? (
                <div>
                    <Tombol memproses={memproses} onClick={() => router.post(`${alamat}/kirim`, {}, opsi)}>
                        Berangkat antar
                    </Tombol>
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Tombol onClick={() => AturMode('terima')}>Sudah diterima</Tombol>
                    <Tombol varian="sekunder" onClick={() => AturMode('gagal')}>
                        Gagal dikirim
                    </Tombol>
                </div>
            )}
        </section>
    );
}
