import { router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris, type AksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import type { Pilihan, StatusOrganisasi } from '@/Tipe/Organisasi';

import DenahMeja from './DenahMeja';
import { DialogQrMeja, SakelarPesanSendiri, type PesanSendiriOutlet } from './PesanSendiriMeja';

export type AreaMeja = {
    Uuid: string;
    Nama: string;
    Urutan: number;
    Status: StatusOrganisasi;
    JumlahMeja: number;
    /** Minimum charge area (D-144), string desimal server; null = tanpa minimum. */
    MinimumCharge?: string | null;
};

export type Meja = {
    Uuid: string;
    Nama: string;
    UuidArea: string | null;
    NamaArea: string | null;
    Kapasitas: number;
    Bentuk: string;
    Urutan: number;
    /** POS-03: titik tengah meja pada kanvas denah relatif 0–1000; null = belum ditata. */
    PosisiX?: number | null;
    PosisiY?: number | null;
    Status: StatusOrganisasi;
    /** Minimum charge khusus meja ini; null = ikut area. */
    MinimumCharge?: string | null;
    /** Minimum yang berlaku (meja, atau area bila meja tidak mengaturnya). */
    MinimumChargeEfektif?: string | null;
};

export type ModeMejaOutlet = { Aktif: boolean; Area: AreaMeja[]; Meja: Meja[] };

type PropsBagianMeja = {
    alamatOutlet: string;
    modeMeja: ModeMejaOutlet;
    bentuk: Pilihan[];
    bolehKelola: boolean;
    /** F-17: sakelar & QR pesan sendiri. */
    pesanSendiri: PesanSendiriOutlet;
};

function LabelStatusOrganisasi({ status }: { status: StatusOrganisasi }) {
    return status === 'Aktif' ? (
        <LabelStatus jenis="sukses" teks="Aktif" />
    ) : (
        <LabelStatus jenis="netral" teks="Diarsipkan" />
    );
}

function TindakanStatus(alamat: string, status: StatusOrganisasi) {
    return {
        label: status === 'Aktif' ? 'Arsipkan' : 'Pulihkan',
        bahaya: status === 'Aktif',
        saatPilih: () =>
            router.post(`${alamat}/${status === 'Aktif' ? 'arsipkan' : 'pulihkan'}`, {}, { preserveScroll: true }),
    };
}

/**
 * F-10a: area & meja outlet untuk mode meja. Tampil bila fitur mode meja aktif di outlet atau sudah ada data meja
 * (data lama tetap bisa diubah/diarsipkan setelah turun paket; menambah butuh fitur aktif).
 */
export default function BagianMeja({ alamatOutlet, modeMeja, bentuk, bolehKelola, pesanSendiri }: PropsBagianMeja) {
    const [suntingArea, AturSuntingArea] = useState<AreaMeja | 'baru' | null>(null);
    const [suntingMeja, AturSuntingMeja] = useState<Meja | 'baru' | null>(null);
    const [banyakMeja, AturBanyakMeja] = useState(false);
    const [qrMeja, AturQrMeja] = useState<Meja | null>(null);
    const bolehTambah = bolehKelola && modeMeja.Aktif;
    const labelBentuk = useMemo(() => new Map(bentuk.map((b) => [b.Nilai, b.Label])), [bentuk]);
    const opsiArea: Pilihan[] = [
        { Nilai: '', Label: 'Tanpa area' },
        ...modeMeja.Area.filter((a) => a.Status === 'Aktif').map((a) => ({ Nilai: a.Uuid, Label: a.Nama })),
    ];

    if (!modeMeja.Aktif && modeMeja.Area.length === 0 && modeMeja.Meja.length === 0) {
        return null;
    }

    const kolomArea: KolomTabel<AreaMeja>[] = [
        { id: 'Nama', accessorKey: 'Nama', header: 'Area', meta: { label: 'Area', prioritas: 'utama', wajib: true } },
        {
            id: 'JumlahMeja',
            accessorKey: 'JumlahMeja',
            header: 'Meja aktif',
            meta: { label: 'Meja aktif', prioritas: 'penting', angka: true },
        },
        {
            id: 'MinimumCharge',
            accessorFn: (a) => a.MinimumCharge ?? null,
            header: 'Minimum charge',
            meta: { label: 'Minimum charge', prioritas: 'penting', angka: true },
            cell: ({ row }) =>
                row.original.MinimumCharge ? (
                    FormatRupiah(row.original.MinimumCharge)
                ) : (
                    <span className="text-teks-sekunder">Tanpa minimum</span>
                ),
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => <LabelStatusOrganisasi status={row.original.Status} />,
        },
    ];

    const kolomMeja: KolomTabel<Meja>[] = [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Meja',
            meta: { label: 'Meja', prioritas: 'utama', wajib: true, kelasSel: 'font-semibold text-teks-utama' },
        },
        {
            id: 'NamaArea',
            accessorFn: (m) => m.NamaArea ?? 'Tanpa area',
            header: 'Area',
            meta: { label: 'Area', prioritas: 'penting' },
        },
        {
            id: 'Kapasitas',
            accessorKey: 'Kapasitas',
            header: 'Kapasitas',
            meta: { label: 'Kapasitas', prioritas: 'penting', angka: true },
            cell: ({ row }) => `${String(row.original.Kapasitas)} orang`,
        },
        {
            id: 'MinimumCharge',
            accessorFn: (m) => m.MinimumChargeEfektif ?? null,
            header: 'Minimum charge',
            meta: { label: 'Minimum charge', prioritas: 'penting', angka: true },
            cell: ({ row }) =>
                row.original.MinimumChargeEfektif ? (
                    <>
                        {FormatRupiah(row.original.MinimumChargeEfektif)}
                        {row.original.MinimumCharge ? null : (
                            <span className="block text-keterangan text-teks-sekunder">dari area</span>
                        )}
                    </>
                ) : (
                    <span className="text-teks-sekunder">Tanpa minimum</span>
                ),
        },
        {
            id: 'Bentuk',
            accessorFn: (m) => labelBentuk.get(m.Bentuk) ?? m.Bentuk,
            header: 'Bentuk',
            meta: { label: 'Bentuk', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => <LabelStatusOrganisasi status={row.original.Status} />,
        },
    ];

    return (
        <section className="flex flex-col gap-3" aria-labelledby="judul-meja-outlet">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="judul-meja-outlet" className="text-subjudul font-semibold text-teks-utama">
                    Meja & area
                </h2>
                {bolehTambah ? (
                    <div className="flex flex-wrap gap-2">
                        <Tombol varian="sekunder" onClick={() => AturSuntingArea('baru')}>
                            Tambah area
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturBanyakMeja(true)}>
                            Tambah banyak meja
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturSuntingMeja('baru')}>
                            Tambah meja
                        </Tombol>
                    </div>
                ) : null}
            </div>
            <p className="text-keterangan text-teks-sekunder">
                Meja dipilih kasir atau pelayan saat membuka pesanan makan di tempat. Area (misal Indoor, Teras, VIP)
                mengelompokkan meja di layar kasir.
            </p>
            <SakelarPesanSendiri alamatOutlet={alamatOutlet} pesanSendiri={pesanSendiri} bolehKelola={bolehKelola} />
            {qrMeja !== null ? (
                <DialogQrMeja
                    key={qrMeja.Uuid}
                    alamatOutlet={alamatOutlet}
                    uuidMeja={qrMeja.Uuid}
                    namaMeja={qrMeja.Nama}
                    bolehKelola={bolehKelola}
                    saatTutup={() => AturQrMeja(null)}
                />
            ) : null}
            {modeMeja.Aktif ? null : (
                <Pemberitahuan jenis="info" judul="Mode meja tidak aktif">
                    Paket atau template outlet ini tidak memakai mode meja. Data meja lama tetap bisa diubah atau
                    diarsipkan, tetapi meja baru tidak bisa ditambah.
                </Pemberitahuan>
            )}

            {suntingArea !== null ? (
                <DialogFormulir
                    judul={suntingArea === 'baru' ? 'Tambah area meja' : `Ubah area ${suntingArea.Nama}`}
                    saatTutup={() => AturSuntingArea(null)}
                >
                    <FormArea
                        key={suntingArea === 'baru' ? 'baru' : suntingArea.Uuid}
                        alamatOutlet={alamatOutlet}
                        area={suntingArea === 'baru' ? null : suntingArea}
                        saatSelesai={() => AturSuntingArea(null)}
                    />
                </DialogFormulir>
            ) : null}
            {banyakMeja ? (
                <DialogFormulir
                    judul="Tambah banyak meja"
                    keterangan="Buat meja bernomor berurutan sekaligus, misal Meja 1 sampai Meja 20. Semua atau tidak sama sekali: kalau ada nama yang sudah dipakai, tidak ada meja yang dibuat."
                    saatTutup={() => AturBanyakMeja(false)}
                >
                    <FormMejaBanyak
                        alamatOutlet={alamatOutlet}
                        opsiArea={opsiArea}
                        bentuk={bentuk}
                        saatSelesai={() => AturBanyakMeja(false)}
                    />
                </DialogFormulir>
            ) : null}
            {suntingMeja !== null ? (
                <DialogFormulir
                    judul={suntingMeja === 'baru' ? 'Tambah meja' : `Ubah meja ${suntingMeja.Nama}`}
                    saatTutup={() => AturSuntingMeja(null)}
                >
                    <FormMeja
                        key={suntingMeja === 'baru' ? 'baru' : suntingMeja.Uuid}
                        alamatOutlet={alamatOutlet}
                        meja={suntingMeja === 'baru' ? null : suntingMeja}
                        opsiArea={opsiArea}
                        bentuk={bentuk}
                        saatSelesai={() => AturSuntingMeja(null)}
                    />
                </DialogFormulir>
            ) : null}

            <TabelData
                id="organisasi-area-meja"
                label="Area meja"
                kolom={kolomArea}
                sumber={{ mode: 'lokal', data: modeMeja.Area }}
                ambilIdBaris={(a) => a.Uuid}
                cari={false}
                labelBaris={(a) => `area ${a.Nama}`}
                {...(bolehKelola
                    ? {
                          aksiBaris: (a: AreaMeja) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah', saatPilih: () => AturSuntingArea(a) },
                                      TindakanStatus(`${alamatOutlet}/area-meja/${a.Uuid}`, a.Status),
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{ judul: 'Belum ada area. Area opsional. Meja tanpa area tetap bisa dipakai.' }}
            />
            <DenahMeja alamatOutlet={alamatOutlet} meja={modeMeja.Meja} bolehKelola={bolehKelola} />
            <TabelData
                id="organisasi-meja"
                label="Meja"
                kolom={kolomMeja}
                sumber={{ mode: 'lokal', data: modeMeja.Meja }}
                ambilIdBaris={(m) => m.Uuid}
                cari="Cari nama meja"
                labelBaris={(m) => `meja ${m.Nama}`}
                aksiBaris={(m: Meja) => {
                    // F-17: QR pesan sendiri untuk meja aktif (semua yang bisa melihat meja); ubah & arsip butuh izin.
                    const aksi: AksiBaris[] = [
                        ...(m.Status === 'Aktif'
                            ? [{ label: 'QR pesan sendiri', saatPilih: () => AturQrMeja(m) }]
                            : []),
                        ...(bolehKelola
                            ? [
                                  { label: 'Ubah', saatPilih: () => AturSuntingMeja(m) },
                                  TindakanStatus(`${alamatOutlet}/meja/${m.Uuid}`, m.Status),
                              ]
                            : []),
                    ];

                    return aksi.length > 0 ? <ItemAksiBaris aksi={aksi} /> : null;
                }}
                kosong={{ judul: 'Belum ada meja. Tambah meja agar kasir bisa membuka pesanan per meja.' }}
            />
        </section>
    );
}

/** Minimum charge server ("150000.00") ke isian uang polos ("150000"); kosong tetap kosong. */
function KeIsianUang(nilai: string | null | undefined): string {
    if (nilai === null || nilai === undefined || nilai === '') {
        return '';
    }

    const [bulat = '0', pecahan = ''] = nilai.split('.');
    const dua = pecahan.slice(0, 2).replace(/0+$/, '');

    return dua === '' ? bulat : `${bulat}.${dua}`;
}

type PropsFormArea = { alamatOutlet: string; area: AreaMeja | null; saatSelesai: () => void };

function FormArea({ alamatOutlet, area, saatSelesai }: PropsFormArea) {
    const formulir = useForm({
        Nama: area?.Nama ?? '',
        Urutan: String(area?.Urutan ?? 0),
        MinimumCharge: KeIsianUang(area?.MinimumCharge),
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (area === null) {
            formulir.post(`${alamatOutlet}/area-meja`, opsi);
        } else {
            formulir.put(`${alamatOutlet}/area-meja/${area.Uuid}`, opsi);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Nama area"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={formulir.errors.Nama}
                keterangan="Misal Indoor, Teras, atau VIP"
                maxLength={60}
                autoFocus
                required
            />
            <BidangTeks
                label="Urutan tampil"
                nilai={formulir.data.Urutan}
                saatBerubah={(nilai) => formulir.setData('Urutan', nilai.replace(/\D/g, ''))}
                galat={formulir.errors.Urutan}
                keterangan="Angka kecil tampil lebih dulu di layar kasir"
                inputMode="numeric"
                maxLength={3}
            />
            <BidangUang
                label="Minimum charge area"
                nilai={formulir.data.MinimumCharge}
                saatBerubah={(nilai) => formulir.setData('MinimumCharge', nilai)}
                galat={formulir.errors.MinimumCharge}
                keterangan="Kosongkan bila tanpa minimum. Berlaku untuk semua meja di area ini kecuali meja yang mengatur sendiri."
            />
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan area
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}

type PropsFormMeja = {
    alamatOutlet: string;
    meja: Meja | null;
    opsiArea: Pilihan[];
    bentuk: Pilihan[];
    saatSelesai: () => void;
};

function FormMeja({ alamatOutlet, meja, opsiArea, bentuk, saatSelesai }: PropsFormMeja) {
    const formulir = useForm({
        Nama: meja?.Nama ?? '',
        Area: meja?.UuidArea ?? '',
        Kapasitas: String(meja?.Kapasitas ?? 4),
        Bentuk: meja?.Bentuk ?? 'Persegi',
        Urutan: String(meja?.Urutan ?? 0),
        MinimumCharge: KeIsianUang(meja?.MinimumCharge),
    });
    // Area meja yang diarsipkan tetap bisa dipertahankan saat mengubah meja lama.
    const opsi =
        meja?.UuidArea && !opsiArea.some((o) => o.Nilai === meja.UuidArea)
            ? [...opsiArea, { Nilai: meja.UuidArea, Label: `${meja.NamaArea ?? 'Area'} (diarsipkan)` }]
            : opsiArea;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const pilihan = { preserveScroll: true, onSuccess: saatSelesai };

        if (meja === null) {
            formulir.post(`${alamatOutlet}/meja`, pilihan);
        } else {
            formulir.put(`${alamatOutlet}/meja/${meja.Uuid}`, pilihan);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Nama atau nomor meja"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={formulir.errors.Nama}
                keterangan="Misal 7 atau VIP 2. Unik di outlet ini."
                maxLength={30}
                autoFocus
                required
            />
            <BidangPilihan
                label="Area"
                nilai={formulir.data.Area}
                opsi={opsi}
                saatBerubah={(nilai) => formulir.setData('Area', nilai)}
                galat={formulir.errors.Area}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <BidangTeks
                    label="Kapasitas (orang)"
                    nilai={formulir.data.Kapasitas}
                    saatBerubah={(nilai) => formulir.setData('Kapasitas', nilai.replace(/\D/g, ''))}
                    galat={formulir.errors.Kapasitas}
                    inputMode="numeric"
                    maxLength={2}
                    required
                />
                <BidangPilihan
                    label="Bentuk"
                    nilai={formulir.data.Bentuk}
                    opsi={bentuk}
                    saatBerubah={(nilai) => formulir.setData('Bentuk', nilai)}
                    galat={formulir.errors.Bentuk}
                    required
                />
            </div>
            <BidangTeks
                label="Urutan tampil"
                nilai={formulir.data.Urutan}
                saatBerubah={(nilai) => formulir.setData('Urutan', nilai.replace(/\D/g, ''))}
                galat={formulir.errors.Urutan}
                inputMode="numeric"
                maxLength={3}
            />
            <BidangUang
                label="Minimum charge meja"
                nilai={formulir.data.MinimumCharge}
                saatBerubah={(nilai) => formulir.setData('MinimumCharge', nilai)}
                galat={formulir.errors.MinimumCharge}
                keterangan="Kosongkan untuk mengikuti minimum areanya. Bila belanja meja kurang dari ini, kasir menagih selisihnya."
            />
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan meja
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}

function FormMejaBanyak({ alamatOutlet, opsiArea, bentuk, saatSelesai }: Omit<PropsFormMeja, 'meja'>) {
    const formulir = useForm({
        Awalan: 'Meja',
        Mulai: '1',
        Jumlah: '10',
        Area: '',
        Kapasitas: '4',
        Bentuk: 'Persegi',
    });
    // Nama yang bentrok dilaporkan server pada kunci `Nama` (bukan bidang formulir ini).
    const galatNama = (formulir.errors as Record<string, string | undefined>).Nama;
    const mulai = Number(formulir.data.Mulai);
    const jumlah = Number(formulir.data.Jumlah);
    const awalan = formulir.data.Awalan.trim();
    const Nama = (nomor: number) => (awalan === '' ? String(nomor) : `${awalan} ${String(nomor)}`);
    const pratinjau =
        Number.isInteger(mulai) && Number.isInteger(jumlah) && jumlah >= 1
            ? jumlah === 1
                ? Nama(mulai)
                : `${Nama(mulai)} sampai ${Nama(mulai + jumlah - 1)}`
            : null;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamatOutlet}/meja/massal`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Awalan nama"
                nilai={formulir.data.Awalan}
                saatBerubah={(nilai) => formulir.setData('Awalan', nilai)}
                galat={formulir.errors.Awalan}
                keterangan="Misal Meja atau VIP. Kosongkan bila nama meja hanya nomor."
                maxLength={25}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <BidangTeks
                    label="Mulai dari nomor"
                    nilai={formulir.data.Mulai}
                    saatBerubah={(nilai) => formulir.setData('Mulai', nilai.replace(/\D/g, ''))}
                    galat={formulir.errors.Mulai}
                    inputMode="numeric"
                    maxLength={4}
                    required
                />
                <BidangTeks
                    label="Jumlah meja"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai.replace(/\D/g, ''))}
                    galat={formulir.errors.Jumlah}
                    inputMode="numeric"
                    maxLength={3}
                    keterangan="Paling banyak 100 sekali buat."
                    required
                />
            </div>
            {pratinjau !== null ? (
                <p className="text-keterangan text-teks-sekunder" aria-live="polite">
                    Akan dibuat: <span className="font-semibold text-teks-utama">{pratinjau}</span>
                </p>
            ) : null}
            <BidangPilihan
                label="Area"
                nilai={formulir.data.Area}
                opsi={opsiArea}
                saatBerubah={(nilai) => formulir.setData('Area', nilai)}
                galat={formulir.errors.Area}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <BidangTeks
                    label="Kapasitas tiap meja (orang)"
                    nilai={formulir.data.Kapasitas}
                    saatBerubah={(nilai) => formulir.setData('Kapasitas', nilai.replace(/\D/g, ''))}
                    galat={formulir.errors.Kapasitas}
                    inputMode="numeric"
                    maxLength={2}
                    required
                />
                <BidangPilihan
                    label="Bentuk"
                    nilai={formulir.data.Bentuk}
                    opsi={bentuk}
                    saatBerubah={(nilai) => formulir.setData('Bentuk', nilai)}
                    galat={formulir.errors.Bentuk}
                    required
                />
            </div>
            {galatNama ? <p className="text-keterangan font-semibold text-bahaya">{galatNama}</p> : null}
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Buat meja
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
