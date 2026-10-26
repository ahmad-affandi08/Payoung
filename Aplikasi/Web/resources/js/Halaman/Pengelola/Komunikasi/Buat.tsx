import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilHasilCari, useNilaiTertunda } from '@/Komponen/Katalog/PemilihProduk';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggalWaktu from '@/Komponen/Tanggal/PemilihTanggalWaktu';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { Pilihan } from '@/Tipe/Pengelola';

type PropsBuat = {
    PilihanStatus: Pilihan[];
    PilihanKesehatan: Pilihan[];
    PilihanPaket: Pilihan[];
    TenantAwal: { Uuid: string; Nama: string }[];
    Whatsapp: { Tersedia: boolean; Alasan: string | null; JamMulai: number; JamSelesai: number };
};

type Estimasi = { TenantCocok: number; Penerima: number; TanpaKontak: number; Contoh: string[] };

const MAKS_ISI = 1000;

function BuatQuery(kanal: string, status: string[], paket: string[], kesehatan: string[], tenant: string[]): string {
    const parameter = new URLSearchParams({ Kanal: kanal });

    for (const [kunci, nilai] of [
        ['Status', status],
        ['Paket', paket],
        ['Kesehatan', kesehatan],
        ['Tenant', tenant],
    ] as const) {
        nilai.forEach((satu) => parameter.append(`${kunci}[]`, satu));
    }

    return parameter.toString();
}

/**
 * Siaran baru (D-210): isi pesan, kanal, segmen penerima, dan jadwal. Estimasi penerima dihitung server saat segmen
 * berubah, supaya petugas tahu berapa pemilik yang akan menerima sebelum menjadwalkan.
 */
export default function Buat({ PilihanStatus, PilihanKesehatan, PilihanPaket, TenantAwal, Whatsapp }: PropsBuat) {
    const tenant = useMemo(() => TenantAwal.map((t) => t.Uuid), [TenantAwal]);
    const formulir = useForm({
        Judul: '',
        Isi: '',
        Kanal: Whatsapp.Tersedia ? 'WhatsApp' : 'Email',
        Status: [] as string[],
        Paket: [] as string[],
        Kesehatan: [] as string[],
        Jadwal: '',
    });
    const { data } = formulir;
    const galat: Record<string, string | undefined> = formulir.errors;
    const kunciSegmen = useNilaiTertunda(BuatQuery(data.Kanal, data.Status, data.Paket, data.Kesehatan, tenant), 300);
    const estimasi = useQuery({
        queryKey: KunciKueri.SiaranEstimasi(data.Kanal, kunciSegmen),
        queryFn: ({ signal }) => AmbilHasilCari<Estimasi>(`/komunikasi/estimasi?${kunciSegmen}`, signal),
        placeholderData: keepPreviousData,
        staleTime: 0,
    });
    const hasil = estimasi.data;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((d) => ({
            Judul: d.Judul,
            Isi: d.Isi,
            Kanal: d.Kanal,
            Segmen: { Status: d.Status, Paket: d.Paket, Kesehatan: d.Kesehatan, Tenant: tenant },
            JadwalKirimPada: d.Jadwal === '' ? null : new Date(d.Jadwal).toISOString(),
        }));
        formulir.post('/komunikasi');
    };

    const opsiKanal = [
        { Nilai: 'WhatsApp', Label: Whatsapp.Tersedia ? 'WhatsApp' : 'WhatsApp (tidak tersedia)' },
        { Nilai: 'Email', Label: 'Email' },
    ];

    return (
        <TataLetakPengelola judul="Buat siaran" jejak={[{ label: 'Komunikasi', href: '/komunikasi' }]}>
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4">
                {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
                <Panel tingkat="h3" judul="Pesan">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <BidangTeks
                            label="Judul"
                            nilai={data.Judul}
                            saatBerubah={(nilai) => formulir.setData('Judul', nilai)}
                            galat={formulir.errors.Judul}
                            maxLength={120}
                            required
                        />
                        <BidangPilihan
                            label="Kanal"
                            nilai={data.Kanal}
                            opsi={opsiKanal}
                            saatBerubah={(nilai) => {
                                if (nilai !== 'WhatsApp' || Whatsapp.Tersedia) {
                                    formulir.setData('Kanal', nilai);
                                }
                            }}
                            galat={formulir.errors.Kanal}
                            required
                        />
                    </div>
                    {!Whatsapp.Tersedia && Whatsapp.Alasan ? (
                        <Pemberitahuan jenis="info">{Whatsapp.Alasan}</Pemberitahuan>
                    ) : null}
                    <BidangTeksPanjang
                        label="Isi pesan"
                        keterangan={`Teks biasa, baris kosong memisahkan paragraf. ${String(data.Isi.length)} dari ${String(MAKS_ISI)} huruf.`}
                        nilai={data.Isi}
                        saatBerubah={(nilai) => formulir.setData('Isi', nilai)}
                        galat={formulir.errors.Isi}
                        baris={7}
                        maksimal={MAKS_ISI}
                        required
                    />
                </Panel>
                <Panel tingkat="h3" judul="Penerima">
                    {TenantAwal.length > 0 ? (
                        <Pemberitahuan jenis="info">
                            Siaran hanya untuk {TenantAwal.length} tenant terpilih:{' '}
                            {TenantAwal.map((t) => t.Nama).join(', ')}.{' '}
                            <Link href="/komunikasi/buat" className="underline">
                                Pilih berdasarkan segmen
                            </Link>
                        </Pemberitahuan>
                    ) : (
                        <p className="text-isi text-teks-sekunder">
                            Tanpa pilihan, siaran dikirim ke Owner semua tenant aktif. Tenant Uji, Demo, dan Internal
                            tidak pernah menerima siaran.
                        </p>
                    )}
                    {TenantAwal.length === 0 ? (
                        <div className="grid gap-4 lg:grid-cols-3">
                            <GrupCentang
                                legenda="Status langganan"
                                opsi={PilihanStatus.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                                terpilih={data.Status}
                                saatBerubah={(nilai) => formulir.setData('Status', nilai)}
                            />
                            <GrupCentang
                                legenda="Paket"
                                opsi={PilihanPaket.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                                terpilih={data.Paket}
                                saatBerubah={(nilai) => formulir.setData('Paket', nilai)}
                            />
                            <GrupCentang
                                legenda="Kesehatan"
                                opsi={PilihanKesehatan.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                                terpilih={data.Kesehatan}
                                saatBerubah={(nilai) => formulir.setData('Kesehatan', nilai)}
                            />
                        </div>
                    ) : null}
                    {galat.Segmen ? <Pemberitahuan jenis="bahaya">{galat.Segmen}</Pemberitahuan> : null}
                    <p role="status" className="text-isi font-semibold text-teks-utama tabular-nums">
                        {hasil === undefined
                            ? 'Menghitung penerima…'
                            : `${hasil.Penerima.toLocaleString('id-ID')} penerima dari ${hasil.TenantCocok.toLocaleString('id-ID')} tenant` +
                              (hasil.TanpaKontak > 0
                                  ? ` (${hasil.TanpaKontak.toLocaleString('id-ID')} tenant tanpa kontak ${data.Kanal === 'Email' ? 'email' : 'WhatsApp'})`
                                  : '')}
                    </p>
                    {hasil !== undefined && hasil.Contoh.length > 0 ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Contoh penerima: {hasil.Contoh.join(', ')}.
                        </p>
                    ) : null}
                </Panel>
                <Panel tingkat="h3" judul="Jadwal">
                    <PemilihTanggalWaktu
                        label="Waktu kirim"
                        keterangan={
                            data.Kanal === 'WhatsApp'
                                ? `Kosongkan untuk kirim segera. WhatsApp hanya dikirim jam ${String(Whatsapp.JamMulai)}.00–${String(Whatsapp.JamSelesai)}.00 WIB. Di luar jam itu pengiriman menunggu pagi.`
                                : 'Kosongkan untuk kirim segera. Email dikirim bertahap tiap lima menit.'
                        }
                        nilai={data.Jadwal}
                        saatBerubah={(nilai) => formulir.setData('Jadwal', nilai)}
                        galat={galat.JadwalKirimPada}
                    />
                </Panel>
                <BilahAksiForm>
                    <Tombol
                        type="submit"
                        memproses={formulir.processing}
                        disabled={data.Judul.trim() === '' || data.Isi.trim().length < 10 || hasil?.Penerima === 0}
                    >
                        {data.Jadwal === '' ? 'Kirim siaran' : 'Jadwalkan siaran'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={() => window.history.back()}>
                        Batal
                    </Tombol>
                </BilahAksiForm>
            </form>
        </TataLetakPengelola>
    );
}
