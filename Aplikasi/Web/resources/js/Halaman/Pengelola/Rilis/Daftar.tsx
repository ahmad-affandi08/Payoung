import { router, useForm, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import TabRilis from '@/Komponen/Pengelola/TabRilis';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import {
    IzinPengelola,
    PunyaIzin,
    type DampakVersiMinimum,
    type PropsBersamaPengelola,
    type RilisAplikasi,
} from '@/Tipe/Pengelola';

const opsiAplikasi = [
    { Nilai: 'Pos', Label: 'Aplikasi POS' },
    { Nilai: 'Pemilik', Label: 'Aplikasi Owner' },
];
const opsiPlatform = [
    { Nilai: 'Android', Label: 'Android' },
    { Nilai: 'Ios', Label: 'iOS / iPadOS' },
    { Nilai: 'Windows', Label: 'Windows' },
];
const opsiKanal = [
    { Nilai: 'Stabil', Label: 'Stabil (semua tenant, bertahap)' },
    { Nilai: 'Beta', Label: 'Beta (tenant Uji & Internal)' },
];

/** Status rilis: draf, aktif dengan persen rollout, atau dihentikan. */
export function AmbilStatusRilis(r: RilisAplikasi): {
    teks: string;
    jenis: 'sukses' | 'peringatan' | 'bahaya' | 'netral';
} {
    if (r.Status === 'Draf') {
        return { teks: 'Draf', jenis: 'netral' };
    }
    if (r.Status === 'Dihentikan') {
        return { teks: 'Dihentikan', jenis: 'bahaya' };
    }
    if (r.Kanal === 'Beta') {
        return { teks: 'Aktif | Beta', jenis: 'sukses' };
    }
    return r.PersenRollout >= 100
        ? { teks: 'Aktif | 100%', jenis: 'sukses' }
        : { teks: `Aktif | ${String(r.PersenRollout)}%`, jenis: 'peringatan' };
}

function CekMinimumBelumBerlaku(r: RilisAplikasi): boolean {
    return (
        r.VersiMinimum !== null &&
        r.VersiMinimumBerlakuPada !== null &&
        new Date(r.VersiMinimumBerlakuPada) > new Date()
    );
}

const kolom: KolomTabel<RilisAplikasi>[] = [
    {
        id: 'Versi',
        accessorKey: 'Versi',
        header: 'Versi',
        meta: { label: 'Versi', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: r } }) => (
            <>
                <span className="block font-mono text-teks-utama">
                    {r.Versi}
                    {r.Build !== null ? `+${String(r.Build)}` : ''}
                </span>
                <span className="block text-keterangan text-teks-sekunder">{r.LabelAplikasi}</span>
            </>
        ),
    },
    {
        id: 'Platform',
        accessorKey: 'Platform',
        header: 'Platform',
        meta: { label: 'Platform', prioritas: 'penting' },
        cell: ({ row }) => opsiPlatform.find((o) => o.Nilai === row.original.Platform)?.Label ?? row.original.Platform,
    },
    {
        id: 'Kanal',
        accessorKey: 'Kanal',
        header: 'Kanal',
        meta: { label: 'Kanal', prioritas: 'rendah' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => {
            const status = AmbilStatusRilis(row.original);
            return <LabelStatus jenis={status.jenis} teks={status.teks} />;
        },
    },
    {
        id: 'VersiMinimum',
        accessorKey: 'VersiMinimumBerlakuPada',
        header: 'Versi minimum',
        meta: { label: 'Versi minimum', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: r } }) =>
            r.VersiMinimum === null
                ? '—'
                : `${CekMinimumBelumBerlaku(r) ? 'Mulai' : 'Berlaku sejak'} ${FormatTanggal(r.VersiMinimumBerlakuPada)}${r.PerbaikanKeamanan ? ' | keamanan' : ''}`,
    },
    {
        id: 'DiterbitkanPada',
        accessorKey: 'DiterbitkanPada',
        header: 'Diterbitkan',
        meta: { label: 'Diterbitkan', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => (row.original.DiterbitkanPada ? FormatTanggalWaktu(row.original.DiterbitkanPada) : '—'),
    },
];

type Dialog =
    | { jenis: 'draf'; rilis: RilisAplikasi | null }
    | { jenis: 'terbitkan' | 'rollout' | 'hentikan' | 'minimum'; rilis: RilisAplikasi };

/**
 * Rilis aplikasi (P-10): catat build sebagai draf, terbitkan ke kanal Beta atau Stabil dengan rollout bertahap
 * (10% → 50% → 100%), hentikan rollout yang bermasalah, dan naikkan versi minimum (BR-P10.1 diumumkan ≥ 7 hari,
 * BR-P10.2 dampak ke perangkat lama ditampilkan). Perangkat di bawah versi minimum tetap boleh mengirim outbox.
 */
export default function HalamanRilis({ Rilis }: { Rilis: RilisAplikasi[] }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.RilisKelola);
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);

    return (
        <TataLetakPengelola judul="Rilis aplikasi">
            <TabRilis />
            <AksiHalaman>
                {bolehKelola ? (
                    <Tombol onClick={() => AturDialog({ jenis: 'draf', rilis: null })}>Catat draf rilis</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-rilis"
                label="Daftar rilis aplikasi"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Rilis }}
                ambilIdBaris={(r) => r.Uuid}
                labelBaris={(r) => `${r.LabelAplikasi} ${r.Platform} ${r.Versi}`}
                cari="Cari versi"
                saring={[
                    {
                        id: 'Platform',
                        label: 'Platform',
                        jenis: 'pilihanBanyak',
                        opsi: opsiPlatform.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: ['Draf', 'Aktif', 'Dihentikan'].map((s) => ({ nilai: s, label: s })),
                    },
                ]}
                {...(bolehKelola
                    ? {
                          aksiBaris: (r: RilisAplikasi) => <AksiRilis rilis={r} buka={AturDialog} />,
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada rilis. Catat build pertama sebagai draf.' }}
            />
            {dialog?.jenis === 'draf' ? <FormDraf rilis={dialog.rilis} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'terbitkan' || dialog?.jenis === 'rollout' ? (
                <FormPersen rilis={dialog.rilis} jenis={dialog.jenis} saatSelesai={Tutup} />
            ) : null}
            {dialog?.jenis === 'hentikan' ? <FormHentikan rilis={dialog.rilis} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'minimum' ? <FormVersiMinimum rilis={dialog.rilis} saatSelesai={Tutup} /> : null}
        </TataLetakPengelola>
    );
}

function AksiRilis({ rilis: r, buka }: { rilis: RilisAplikasi; buka: (d: Dialog) => void }) {
    return (
        <>
            {r.Status === 'Draf' ? (
                <>
                    <DropdownMenuItem onSelect={() => buka({ jenis: 'draf', rilis: r })}>Ubah draf</DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => buka({ jenis: 'terbitkan', rilis: r })}>
                        Terbitkan
                    </DropdownMenuItem>
                </>
            ) : null}
            {r.Status === 'Aktif' && r.Kanal === 'Stabil' ? (
                <>
                    <DropdownMenuItem onSelect={() => buka({ jenis: 'rollout', rilis: r })}>
                        Ubah rollout
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => buka({ jenis: 'minimum', rilis: r })}>
                        Jadikan versi minimum
                    </DropdownMenuItem>
                </>
            ) : null}
            {r.Status === 'Aktif' ? (
                <DropdownMenuItem onSelect={() => buka({ jenis: 'hentikan', rilis: r })}>
                    Hentikan rollout
                </DropdownMenuItem>
            ) : null}
            {CekMinimumBelumBerlaku(r) ? (
                <DropdownMenuItem
                    onSelect={() => router.delete(`/rilis/${r.Uuid}/versi-minimum`, { preserveScroll: true })}
                >
                    Batalkan versi minimum
                </DropdownMenuItem>
            ) : null}
        </>
    );
}

function FormDraf({ rilis, saatSelesai }: { rilis: RilisAplikasi | null; saatSelesai: () => void }) {
    const formulir = useForm({
        Aplikasi: rilis?.Aplikasi ?? 'Pos',
        Platform: rilis?.Platform ?? 'Android',
        Kanal: rilis?.Kanal ?? 'Stabil',
        Versi: rilis?.Versi ?? '',
        Build: rilis?.Build === null || rilis === null ? '' : String(rilis.Build),
        UrlUnduh: rilis?.UrlUnduh ?? '',
        Sha256Unduhan: rilis?.Sha256Unduhan ?? '',
        CatatanRilis: rilis?.CatatanRilis ?? '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };
        if (rilis === null) {
            formulir.post('/rilis', opsi);
        } else {
            formulir.put(`/rilis/${rilis.Uuid}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={rilis === null ? 'Catat draf rilis' : `Ubah draf ${rilis.Versi}`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangPilihan
                    label="Aplikasi"
                    nilai={formulir.data.Aplikasi}
                    opsi={opsiAplikasi}
                    saatBerubah={(nilai) => formulir.setData('Aplikasi', nilai as RilisAplikasi['Aplikasi'])}
                    galat={formulir.errors.Aplikasi}
                    required
                />
                <BidangPilihan
                    label="Platform"
                    nilai={formulir.data.Platform}
                    opsi={opsiPlatform}
                    saatBerubah={(nilai) => formulir.setData('Platform', nilai as RilisAplikasi['Platform'])}
                    galat={formulir.errors.Platform}
                    required
                />
                <BidangPilihan
                    label="Kanal"
                    nilai={formulir.data.Kanal}
                    opsi={opsiKanal}
                    saatBerubah={(nilai) => formulir.setData('Kanal', nilai as RilisAplikasi['Kanal'])}
                    galat={formulir.errors.Kanal}
                    required
                />
                <BidangTeks
                    label="Versi"
                    kode
                    keterangan="MAJOR.MINOR.PATCH, misal 1.4.0."
                    nilai={formulir.data.Versi}
                    saatBerubah={(nilai) => formulir.setData('Versi', nilai.trim())}
                    galat={formulir.errors.Versi}
                    required
                />
                <BidangTeks
                    label="Nomor build (opsional)"
                    inputMode="numeric"
                    nilai={formulir.data.Build}
                    saatBerubah={(nilai) => formulir.setData('Build', nilai.replace(/\D/g, ''))}
                    galat={formulir.errors.Build}
                />
                <BidangTeks
                    label="Tautan unduh (opsional)"
                    keterangan="Untuk APK atau installer Windows. Kosongkan bila lewat toko aplikasi."
                    nilai={formulir.data.UrlUnduh}
                    saatBerubah={(nilai) => formulir.setData('UrlUnduh', nilai)}
                    galat={formulir.errors.UrlUnduh}
                />
                <BidangTeks
                    label="Checksum SHA-256 (wajib untuk Windows)"
                    kode
                    keterangan="64 karakter heksadesimal dari SHA256SUMS.txt hasil build. Aplikasi kasir Windows hanya memasang pembaruan otomatis bila unduhannya cocok; Android dan iOS tidak memerlukannya."
                    nilai={formulir.data.Sha256Unduhan}
                    saatBerubah={(nilai) => formulir.setData('Sha256Unduhan', nilai.trim())}
                    galat={formulir.errors.Sha256Unduhan}
                />
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Catatan rilis (opsional)"
                        nilai={formulir.data.CatatanRilis}
                        saatBerubah={(nilai) => formulir.setData('CatatanRilis', nilai)}
                        keterangan="Tampil ke kasir sebagai 'Yang baru'."
                        maksimal={5000}
                        baris={4}
                        galat={formulir.errors.CatatanRilis}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan draf
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormPersen({
    rilis,
    jenis,
    saatSelesai,
}: {
    rilis: RilisAplikasi;
    jenis: 'terbitkan' | 'rollout';
    saatSelesai: () => void;
}) {
    const awal = jenis === 'terbitkan' ? 10 : Math.min(100, rilis.PersenRollout < 50 ? 50 : 100);
    const formulir = useForm({ PersenRollout: String(awal) });
    const beta = rilis.Kanal === 'Beta';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/rilis/${rilis.Uuid}/${jenis === 'terbitkan' ? 'terbitkan' : 'rollout'}`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <DialogFormulir
            judul={jenis === 'terbitkan' ? `Terbitkan ${rilis.Versi}` : `Ubah rollout ${rilis.Versi}`}
            keterangan={
                beta
                    ? 'Rilis Beta langsung ditawarkan ke semua perangkat tenant Uji & Internal.'
                    : 'Persen perangkat yang ditawari versi ini. Naikkan bertahap, misal 10% → 50% → 100%.'
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                {beta ? null : (
                    <BidangTeks
                        label="Persen rollout"
                        inputMode="numeric"
                        nilai={formulir.data.PersenRollout}
                        saatBerubah={(nilai) => formulir.setData('PersenRollout', nilai.replace(/\D/g, '').slice(0, 3))}
                        galat={formulir.errors.PersenRollout}
                        required
                    />
                )}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        {jenis === 'terbitkan' ? 'Terbitkan rilis' : 'Simpan rollout'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormHentikan({ rilis, saatSelesai }: { rilis: RilisAplikasi; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/rilis/${rilis.Uuid}/hentikan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Hentikan rollout ${rilis.Versi}`}
            keterangan="Perangkat yang belum memasang tidak lagi ditawari versi ini. Perangkat yang sudah memasang tetap berjalan; matikan fitur bermasalah lewat flag fitur."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan menghentikan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Hentikan rollout
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

async function AmbilDampak(uuid: string, sinyal: AbortSignal): Promise<DampakVersiMinimum> {
    const respons = await fetch(`/rilis/${uuid}/dampak-versi-minimum`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });
    if (!respons.ok) {
        throw new Error(`Dampak versi minimum gagal dibaca (${String(respons.status)})`);
    }
    return (await respons.json()) as DampakVersiMinimum;
}

function FormVersiMinimum({ rilis, saatSelesai }: { rilis: RilisAplikasi; saatSelesai: () => void }) {
    const formulir = useForm({ BerlakuPada: '', PerbaikanKeamanan: false, Alasan: '' });
    const dampak = useQuery({
        queryKey: KunciKueri.Pengelola.DampakVersiMinimum(rilis.Uuid),
        queryFn: ({ signal }) => AmbilDampak(rilis.Uuid, signal),
        staleTime: 0,
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/rilis/${rilis.Uuid}/versi-minimum`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Jadikan ${rilis.Versi} versi minimum`}
            keterangan={`Perangkat ${rilis.Platform} di bawah ${rilis.Versi} tetap boleh mengirim transaksi tertunda, lalu layar jual terkunci sampai aplikasi diperbarui.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                {dampak.isPending ? (
                    <p className="text-isi text-teks-sekunder">Menghitung perangkat terdampak…</p>
                ) : dampak.isError ? (
                    <Pemberitahuan jenis="bahaya" judul="Jumlah perangkat terdampak gagal dimuat">
                        Coba buka dialog ini lagi.
                    </Pemberitahuan>
                ) : (
                    <Pemberitahuan
                        jenis={dampak.data.PerangkatDiBawahDenganOutbox > 0 ? 'peringatan' : 'info'}
                        judul={`${String(dampak.data.PerangkatDiBawah)} perangkat masih di bawah ${rilis.Versi}`}
                    >
                        {dampak.data.PerangkatDiBawahDenganOutbox} di antaranya masih punya {dampak.data.OutboxTertunda}{' '}
                        transaksi belum terkirim.
                    </Pemberitahuan>
                )}
                <PemilihTanggal
                    label="Mulai berlaku"
                    nilai={formulir.data.BerlakuPada}
                    saatBerubah={(nilai) => formulir.setData('BerlakuPada', nilai)}
                    keterangan="Paling cepat 7 hari dari hari ini, kecuali perbaikan keamanan."
                    galat={formulir.errors.BerlakuPada}
                    required
                />
                <KotakCentang
                    label="Perbaikan keamanan (boleh berlaku segera)"
                    nilai={formulir.data.PerbaikanKeamanan}
                    saatBerubah={(nilai) => formulir.setData('PerbaikanKeamanan', nilai)}
                />
                <BidangTeksPanjang
                    label="Alasan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Jadikan versi minimum
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
