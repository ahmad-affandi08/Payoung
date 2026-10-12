import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabKatalog from '@/Komponen/Pengelola/TabKatalog';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Paket = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    Status: 'Draf' | 'Aktif' | 'Diarsipkan';
    HargaNegosiasi: boolean;
    MasaTrialHari: number;
    Urutan: number;
    Batas: Record<string, number | null>;
    KunciFitur: string[];
    HargaBulananBerlaku: string | null;
};

type Fitur = { Kunci: string; Nama: string; Modul: string };

type PropsPaket = { Paket: Paket[]; Fitur: Fitur[]; KolomBatas: string[] };

const labelBatas: Record<string, string> = {
    BatasOutlet: 'Outlet',
    BatasPerangkatPerOutlet: 'Perangkat per outlet',
    BatasPengguna: 'Pengguna',
    BatasSku: 'SKU',
    KuotaPesanWaBulanan: 'Pesan WA per bulan',
    BatasPenyimpananMb: 'Penyimpanan berkas (MB)',
};

const labelStatus = {
    Draf: { jenis: 'netral', teks: 'Draf' },
    Aktif: { jenis: 'sukses', teks: 'Aktif' },
    Diarsipkan: { jenis: 'peringatan', teks: 'Diarsipkan' },
} as const;

function BuatKolom(kolomBatas: string[]): KolomTabel<Paket>[] {
    return [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Paket',
            meta: { label: 'Paket', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: paket } }) => (
                <>
                    <Link
                        href={`/katalog/paket/${paket.Uuid}/harga`}
                        className="block font-semibold text-teks-utama underline-offset-2 hover:underline"
                    >
                        {paket.Nama}
                    </Link>
                    <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{paket.Kode}</span>
                    <span className="block text-keterangan font-normal text-teks-sekunder">
                        {paket.KunciFitur.length} fitur | trial {paket.MasaTrialHari} hari
                    </span>
                </>
            ),
        },
        {
            id: 'HargaBulanan',
            header: 'Harga/bulan',
            enableSorting: false,
            meta: { label: 'Harga/bulan', angka: true, prioritas: 'penting', kelasSel: 'text-teks-utama' },
            cell: ({ row: { original: paket } }) =>
                paket.HargaNegosiasi
                    ? 'Negosiasi'
                    : paket.HargaBulananBerlaku !== null
                      ? FormatRupiah(paket.HargaBulananBerlaku)
                      : 'Belum ada harga berlaku',
        },
        {
            id: 'Batas',
            header: 'Batas',
            enableSorting: false,
            meta: { label: 'Batas', prioritas: 'rendah', kelasSel: 'text-keterangan text-teks-sekunder' },
            cell: ({ row: { original: paket } }) =>
                kolomBatas.map((kolom) => (
                    <span key={kolom} className="block">
                        {labelBatas[kolom] ?? kolom}: {paket.Batas[kolom] ?? 'tak terbatas'}
                    </span>
                )),
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => (
                <LabelStatus
                    jenis={labelStatus[row.original.Status].jenis}
                    teks={labelStatus[row.original.Status].teks}
                />
            ),
        },
    ];
}

/** Paket langganan: isi, batas, fitur, status (P-04, BR-P04.2, BR-P04.6), TabelData D-16. */
export default function HalamanPaket({ Paket, Fitur, KolomBatas }: PropsPaket) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehAjukan = PunyaIzin(props.Pengguna, IzinPengelola.KatalogPaketAjukan);
    const bolehSetujui = PunyaIzin(props.Pengguna, IzinPengelola.KatalogPaketSetujui);
    const [sunting, AturSunting] = useState<Paket | 'baru' | null>(null);
    const [arsip, AturArsip] = useState<Paket | null>(null);
    const Aktifkan = (paket: Paket) =>
        router.post(`/katalog/paket/${paket.Uuid}/aktifkan`, {}, { preserveScroll: true });
    const kolom = useMemo(() => BuatKolom(KolomBatas), [KolomBatas]);

    return (
        <TataLetakPengelola judul="Katalog">
            <TabKatalog />
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {sunting !== null ? (
                <FormPaket
                    key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                    paket={sunting === 'baru' ? null : sunting}
                    fitur={Fitur}
                    kolomBatas={KolomBatas}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            {arsip !== null ? <FormArsip key={arsip.Uuid} paket={arsip} saatSelesai={() => AturArsip(null)} /> : null}

            <AksiHalaman>
                {bolehAjukan && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Buat paket</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-katalog-paket"
                label="Daftar paket langganan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Paket }}
                ambilIdBaris={(paket) => paket.Uuid}
                cari="Cari nama atau kode paket"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: (['Draf', 'Aktif', 'Diarsipkan'] as const).map((status) => ({
                            nilai: status,
                            label: labelStatus[status].teks,
                        })),
                    },
                ]}
                alamatDetail={(paket) => `/katalog/paket/${paket.Uuid}/harga`}
                aksiBaris={(paket) => (
                    <>
                        <DropdownMenuItem asChild>
                            <Link href={`/katalog/paket/${paket.Uuid}/harga`}>Kelola harga</Link>
                        </DropdownMenuItem>
                        {bolehAjukan && (paket.Status === 'Draf' || bolehSetujui) ? (
                            <DropdownMenuItem onSelect={() => AturSunting(paket)}>Ubah paket</DropdownMenuItem>
                        ) : null}
                        {bolehSetujui && paket.Status !== 'Aktif' ? (
                            <DropdownMenuItem onSelect={() => Aktifkan(paket)}>Aktifkan paket</DropdownMenuItem>
                        ) : null}
                        {bolehSetujui && paket.Status === 'Aktif' ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" onSelect={() => AturArsip(paket)}>
                                    Arsipkan paket
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </>
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada paket. Buat paket pertama; paket baru tersimpan sebagai draf sampai harganya terbit dan paket diaktifkan.',
                }}
            />
        </TataLetakPengelola>
    );
}

type PropsFormPaket = { paket: Paket | null; fitur: Fitur[]; kolomBatas: string[]; saatSelesai: () => void };

function FormPaket({ paket, fitur, kolomBatas, saatSelesai }: PropsFormPaket) {
    const formulir = useForm<{
        Kode: string;
        Nama: string;
        Keterangan: string;
        HargaNegosiasi: boolean;
        MasaTrialHari: string;
        Urutan: string;
        Batas: Record<string, string>;
        KunciFitur: string[];
        Alasan: string;
    }>({
        Kode: paket?.Kode ?? '',
        Nama: paket?.Nama ?? '',
        Keterangan: paket?.Keterangan ?? '',
        HargaNegosiasi: paket?.HargaNegosiasi ?? false,
        MasaTrialHari: String(paket?.MasaTrialHari ?? 14),
        Urutan: String(paket?.Urutan ?? 0),
        Batas: Object.fromEntries(
            kolomBatas.map((kolom) => [kolom, paket?.Batas[kolom] == null ? '' : String(paket.Batas[kolom])]),
        ),
        KunciFitur: paket?.KunciFitur ?? [],
        Alasan: '',
    });
    const perluAlasan = paket !== null && paket.Status !== 'Draf';
    const galat = formulir.errors as Record<string, string | undefined>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (paket === null) {
            formulir.post('/katalog/paket', opsi);
        } else {
            formulir.put(`/katalog/paket/${paket.Uuid}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={paket === null ? 'Buat paket' : `Ubah ${paket.Nama}`}
            saatTutup={saatSelesai}
            lebar="lebar"
            galatUmum={galat.Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                {perluAlasan ? (
                    <div className="sm:col-span-2">
                        <Pemberitahuan jenis="peringatan" judul="Paket ini sudah dipakai">
                            Perubahan fitur dan batas langsung berlaku untuk tenant yang memakai paket ini. Harga diubah
                            lewat halaman Harga.
                        </Pemberitahuan>
                    </div>
                ) : null}
                <BidangTeks
                    label="Kode"
                    kode
                    keterangan="Huruf besar, misal PRO. Tidak bisa diubah."
                    nilai={formulir.data.Kode}
                    saatBerubah={(nilai) => formulir.setData('Kode', nilai.toUpperCase())}
                    galat={formulir.errors.Kode}
                    required
                    disabled={paket !== null}
                />
                <BidangTeks
                    label="Nama"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    required
                />
                <BidangTeks
                    label="Masa trial (hari)"
                    inputMode="numeric"
                    nilai={formulir.data.MasaTrialHari}
                    saatBerubah={(nilai) => formulir.setData('MasaTrialHari', nilai)}
                    galat={formulir.errors.MasaTrialHari}
                    required
                />
                <BidangTeks
                    label="Urutan tampil"
                    inputMode="numeric"
                    nilai={formulir.data.Urutan}
                    saatBerubah={(nilai) => formulir.setData('Urutan', nilai)}
                    galat={formulir.errors.Urutan}
                    required
                />
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Keterangan (opsional)"
                        nilai={formulir.data.Keterangan}
                        saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                        galat={formulir.errors.Keterangan}
                    />
                </div>
                <KotakCentang
                    label="Harga negosiasi (tanpa harga tetap, misal Enterprise)"
                    nilai={formulir.data.HargaNegosiasi}
                    saatBerubah={(nilai) => formulir.setData('HargaNegosiasi', nilai)}
                />
                <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                    <legend className="mb-2 text-label font-semibold text-teks-utama">
                        Batas (kosongkan untuk tak terbatas)
                    </legend>
                    {kolomBatas.map((kolom) => (
                        <BidangTeks
                            key={kolom}
                            label={labelBatas[kolom] ?? kolom}
                            inputMode="numeric"
                            nilai={formulir.data.Batas[kolom] ?? ''}
                            saatBerubah={(nilai) =>
                                formulir.setData('Batas', { ...formulir.data.Batas, [kolom]: nilai })
                            }
                            galat={galat[`Batas.${kolom}`] ?? galat[kolom]}
                        />
                    ))}
                </fieldset>
                <div className="sm:col-span-2">
                    <GrupCentang
                        legenda="Fitur termasuk"
                        opsi={fitur.map((item) => ({ nilai: item.Kunci, label: `${item.Nama} (${item.Modul})` }))}
                        terpilih={formulir.data.KunciFitur}
                        saatBerubah={(terpilih) => formulir.setData('KunciFitur', terpilih)}
                        galat={formulir.errors.KunciFitur}
                    />
                </div>
                {perluAlasan ? (
                    <div className="sm:col-span-2">
                        <BidangTeks
                            label="Alasan perubahan"
                            nilai={formulir.data.Alasan}
                            saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                            galat={formulir.errors.Alasan}
                            maxLength={500}
                            required
                        />
                    </div>
                ) : null}
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan paket
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormArsip({ paket, saatSelesai }: { paket: Paket; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/katalog/paket/${paket.Uuid}/arsipkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Arsipkan ${paket.Nama}?`}
            keterangan="Tenant baru tidak bisa memilih paket ini lagi. Tenant yang sudah memakainya tidak terdampak."
            saatTutup={saatSelesai}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Alasan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={formulir.errors.Alasan}
                    autoFocus
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Arsipkan paket
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
