import { Head, useForm } from '@inertiajs/react';
import { CheckIcon, ClockIcon } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import KerangkaReservasi from '@/Komponen/Reservasi/KerangkaReservasi';
import PemilihHari, { SusunJajaranHari } from '@/Komponen/Reservasi/PemilihHari';
import PemilihSlot from '@/Komponen/Reservasi/PemilihSlot';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import type { LayananReservasi } from '@/Tipe/Reservasi';

type PropsReservasiPublik = {
    Aktif: boolean;
    Slug: string;
    Toko?: { Nama: string; UrlLogo?: string | null };
    Outlet?: { Uuid: string; Nama: string }[];
    Layanan?: LayananReservasi[];
    Staf?: { Uuid: string; Nama: string }[];
    HariIni?: string;
    TanggalTerakhir?: string;
    KonfirmasiOtomatis?: boolean;
};

/** Satu langkah di kartu: nomor bulat gelap + judul, supaya urutan pesan terbaca tanpa petunjuk tambahan. */
function Langkah({ nomor, judul, children }: { nomor: number; judul: string; children: ReactNode }) {
    return (
        <section aria-label={judul} className="flex flex-col gap-3 rounded-3xl bg-permukaan p-5">
            <h2 className="flex items-center gap-3 text-subjudul font-extrabold text-teks-utama">
                <span
                    aria-hidden="true"
                    className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-gelap text-label font-bold text-permukaan"
                >
                    {nomor}
                </span>
                {judul}
            </h2>
            {children}
        </section>
    );
}

/** Kartu pilihan tunggal (layanan atau staf): radio sungguhan tersembunyi, kartunya yang tampil. */
function KartuPilihan({
    nama,
    dipilih,
    saatPilih,
    children,
}: {
    nama: string;
    dipilih: boolean;
    saatPilih: () => void;
    children: ReactNode;
}) {
    return (
        <label
            className={cn(
                'flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 py-3 has-focus-visible:ring-2 has-focus-visible:ring-brand',
                dipilih ? 'border-brand-gelap bg-brand-lembut' : 'border-garis',
            )}
        >
            <input type="radio" name={nama} className="sr-only" checked={dipilih} onChange={saatPilih} />
            <span
                aria-hidden="true"
                className={cn(
                    'grid size-6 shrink-0 place-items-center rounded-full border-2',
                    dipilih ? 'border-brand-gelap bg-brand-gelap text-permukaan' : 'border-garis-input',
                )}
            >
                {dipilih ? <CheckIcon className="size-3.5" /> : null}
            </span>
            {children}
        </label>
    );
}

function CariNamaHari(tanggal: string, awal: string, akhir: string): string {
    return SusunJajaranHari(awal, akhir).find((h) => h.Nilai === tanggal)?.Penuh ?? tanggal;
}

/**
 * F-07 mode service (SLS-07): reservasi online pelanggan tanpa login. Jam kosong dihitung server dari jadwal staf,
 * setelah terkirim pelanggan dibawa ke halaman status (bisa dibatalkan dari sana). Tampilan D-240: kepala bermerek
 * tenant, empat langkah berkartu (layanan, staf, tanggal dan jam, data diri), dan bilah ringkasan yang menempel di bawah.
 */
export default function HalamanReservasiPublik(props: PropsReservasiPublik) {
    const outlet = props.Outlet ?? [];
    const layanan = props.Layanan ?? [];
    const staf = props.Staf ?? [];
    const formulir = useForm({
        Outlet: '',
        UuidLayanan: '',
        Tanggal: props.HariIni ?? '',
        UuidStaf: '',
        Jam: '',
        NamaPelanggan: '',
        NoHp: '',
        Catatan: '',
        Setuju: false,
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const Kirim = (e: FormEvent) => {
        e.preventDefault();
        formulir.post(`/${props.Slug}/reservasi`);
    };

    if (!props.Aktif) {
        return (
            <KerangkaReservasi toko={props.Toko} label="Reservasi online" judul="Reservasi">
                <Head title="Reservasi" />
                <PanelHasil jenis="netral" judul="Reservasi online belum dibuka">
                    Hubungi toko langsung untuk membuat janji.
                </PanelHasil>
            </KerangkaReservasi>
        );
    }

    const dipilih = layanan.find((l) => l.Uuid === d.UuidLayanan);
    const awal = props.HariIni ?? '';
    const akhir = props.TanggalTerakhir ?? awal;
    const ringkasan =
        dipilih && d.Jam !== ''
            ? `${dipilih.Nama} | ${CariNamaHari(d.Tanggal, awal, akhir)} | ${d.Jam}`
            : 'Pilih layanan, tanggal, dan jam.';

    return (
        <KerangkaReservasi
            toko={props.Toko}
            label="Reservasi online"
            judul={props.Toko?.Nama ?? 'Reservasi'}
            keterangan={
                props.KonfirmasiOtomatis
                    ? 'Pilih waktu, langsung terkonfirmasi.'
                    : 'Pilih waktu, toko akan mengonfirmasi lewat WhatsApp.'
            }
        >
            <Head title={`Reservasi ${props.Toko?.Nama ?? ''}`} />
            {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
            {layanan.length === 0 ? (
                <PanelHasil jenis="netral" judul="Belum ada layanan">
                    Layanan yang bisa dipesan online belum tersedia. Hubungi toko langsung.
                </PanelHasil>
            ) : (
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <Langkah nomor={1} judul="Pilih layanan">
                        <BidangOutlet
                            nilai={d.Outlet}
                            opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                            saatBerubah={(nilai) => formulir.setData({ ...d, Outlet: nilai, Jam: '' })}
                            galat={galat.Outlet}
                            sembunyiBilaTunggal
                        />
                        <div role="radiogroup" aria-label="Layanan" className="flex flex-col gap-2">
                            {layanan.map((l) => (
                                <KartuPilihan
                                    key={l.Uuid}
                                    nama="layanan"
                                    dipilih={d.UuidLayanan === l.Uuid}
                                    saatPilih={() => formulir.setData({ ...d, UuidLayanan: l.Uuid, Jam: '' })}
                                >
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="text-subjudul font-semibold break-words text-teks-utama">
                                            {l.Nama}
                                        </span>
                                        <span className="inline-flex items-center gap-1 text-keterangan text-teks-sekunder">
                                            <ClockIcon aria-hidden="true" className="size-3.5" />
                                            {l.DurasiMenit} menit
                                        </span>
                                    </span>
                                    {l.Harga ? (
                                        <span className="shrink-0 font-bold text-teks-utama tabular-nums">
                                            {FormatRupiah(l.Harga)}
                                        </span>
                                    ) : null}
                                </KartuPilihan>
                            ))}
                        </div>
                        {galat.UuidLayanan ? <p className="text-keterangan text-bahaya">{galat.UuidLayanan}</p> : null}
                    </Langkah>
                    {staf.length > 0 ? (
                        <Langkah nomor={2} judul="Pilih staf">
                            <div role="radiogroup" aria-label="Staf" className="flex flex-wrap gap-2">
                                {[{ Uuid: '', Nama: 'Siapa saja' }, ...staf].map((s) => (
                                    <label
                                        key={s.Uuid}
                                        className={cn(
                                            'flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 px-4 text-isi font-semibold has-focus-visible:ring-2 has-focus-visible:ring-brand',
                                            d.UuidStaf === s.Uuid
                                                ? 'border-brand-gelap bg-brand-gelap text-permukaan'
                                                : 'border-garis text-teks-utama',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="staf"
                                            className="sr-only"
                                            checked={d.UuidStaf === s.Uuid}
                                            onChange={() => formulir.setData({ ...d, UuidStaf: s.Uuid, Jam: '' })}
                                        />
                                        {s.Nama}
                                    </label>
                                ))}
                            </div>
                        </Langkah>
                    ) : null}
                    <Langkah nomor={staf.length > 0 ? 3 : 2} judul="Pilih tanggal dan jam">
                        <PemilihHari
                            awal={awal}
                            akhir={akhir}
                            nilai={d.Tanggal}
                            saatPilih={(tanggal) => formulir.setData({ ...d, Tanggal: tanggal, Jam: '' })}
                            galat={galat.Tanggal}
                        />
                        <PemilihSlot
                            alamat={`/${props.Slug}/reservasi/slot`}
                            outlet={d.Outlet}
                            layanan={d.UuidLayanan}
                            tanggal={d.Tanggal}
                            staf={d.UuidStaf}
                            nilai={d.Jam}
                            saatPilih={(jam) => formulir.setData('Jam', jam)}
                            galat={galat.Jam}
                            gaya="pil"
                        />
                    </Langkah>
                    <Langkah nomor={staf.length > 0 ? 4 : 3} judul="Data kamu">
                        <BidangTeks
                            label="Nama"
                            nilai={d.NamaPelanggan}
                            saatBerubah={(nilai) => formulir.setData('NamaPelanggan', nilai)}
                            galat={galat.NamaPelanggan}
                            required
                        />
                        <BidangTeks
                            label="Nomor WhatsApp"
                            nilai={d.NoHp}
                            saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                            galat={galat.NoHp}
                            inputMode="tel"
                            keterangan="Pengingat dikirim ke nomor ini sehari sebelumnya."
                            required
                        />
                        <BidangTeks
                            label="Catatan (opsional)"
                            nilai={d.Catatan}
                            saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                            galat={galat.Catatan}
                        />
                        <KotakCentang
                            label="Saya setuju nama & nomor WhatsApp dipakai toko untuk reservasi dan pengingat."
                            nilai={d.Setuju}
                            saatBerubah={(nilai) => formulir.setData('Setuju', nilai)}
                        />
                        {galat.Setuju ? <p className="text-keterangan text-bahaya">{galat.Setuju}</p> : null}
                    </Langkah>
                    <div className="tepi-bawah-aman sticky bottom-0 -mx-4 flex flex-col gap-2 border-t border-garis bg-permukaan px-4 py-3 sm:mx-0 sm:rounded-3xl sm:border">
                        <p className="text-keterangan break-words text-teks-sekunder" aria-live="polite">
                            {ringkasan}
                            {dipilih?.Harga ? ` | ${FormatRupiah(dipilih.Harga)}` : ''}
                        </p>
                        <Tombol
                            type="submit"
                            ukuran="besar"
                            bentuk="pil"
                            memproses={formulir.processing}
                            disabled={d.Jam === '' || !d.Setuju}
                        >
                            {props.KonfirmasiOtomatis ? 'Pesan sekarang' : 'Kirim permintaan reservasi'}
                        </Tombol>
                    </div>
                </form>
            )}
        </KerangkaReservasi>
    );
}
