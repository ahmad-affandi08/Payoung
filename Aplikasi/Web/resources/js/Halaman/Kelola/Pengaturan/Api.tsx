import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import DialogRahasia, { NilaiRahasia, useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsHalamanTokenApi, TokenApi } from '@/Tipe/ApiPublik';

const kolom: KolomTabel<TokenApi>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <>
                <span className="block text-teks-utama">{t.Nama}</span>
                <span className="block font-mono text-label text-teks-sekunder">{t.Prefiks}…</span>
            </>
        ),
    },
    {
        id: 'Aktif',
        accessorFn: (t) => (t.Aktif ? 'Aktif' : t.DicabutPada ? 'Dicabut' : 'Kedaluwarsa'),
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: t } }) =>
            t.Aktif ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks={t.DicabutPada ? 'Dicabut' : 'Kedaluwarsa'} />
            ),
    },
    {
        id: 'Cakupan',
        accessorFn: (t) => t.Cakupan.join(', '),
        header: 'Akses',
        enableSorting: false,
        meta: { label: 'Akses', prioritas: 'penting', kelasSel: 'font-mono text-label text-teks-sekunder' },
    },
    {
        id: 'TerakhirDipakaiPada',
        accessorKey: 'TerakhirDipakaiPada',
        header: 'Terakhir dipakai',
        meta: { label: 'Terakhir dipakai', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.TerakhirDipakaiPada ? FormatTanggalWaktu(row.original.TerakhirDipakaiPada) : 'Belum pernah',
    },
    {
        id: 'KedaluwarsaPada',
        accessorKey: 'KedaluwarsaPada',
        header: 'Kedaluwarsa',
        meta: { label: 'Kedaluwarsa', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.KedaluwarsaPada ? FormatTanggalWaktu(row.original.KedaluwarsaPada) : 'Tidak ada',
    },
];

/**
 * X7 Open API v1: Pengaturan › Token API (khusus Owner). Token dipakai aplikasi lain (akuntansi, marketplace, BI)
 * untuk membaca data lewat `/api/v1`. Token asli hanya tampil sekali setelah dibuat; cabut berlaku seketika.
 */
export default function HalamanTokenApi({ Token, OpsiCakupan, TokenBaru, AlamatApi }: PropsHalamanTokenApi) {
    const rahasia = useRahasiaSekali(TokenBaru);
    const [buat, AturBuat] = useState(false);
    const [cabut, AturCabut] = useState<TokenApi | null>(null);

    return (
        <TataLetakAplikasi judul="Token API">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Token API mengizinkan aplikasi lain membaca data usaha Anda lewat{' '}
                <span className="font-mono">{AlamatApi}</span> dengan header{' '}
                <span className="font-mono">Authorization: Bearer &lt;token&gt;</span>. Berikan akses seperlunya saja
                dan cabut token yang tidak dipakai. Batas 120 permintaan per menit per token.{' '}
                <AjakanTambahBatas teksLisensi={null}>
                    Panduan lengkap untuk pengembang ada di{' '}
                    <a className="font-semibold text-brand underline" href="/pengembang">
                        dokumentasi API
                    </a>
                    .
                </AjakanTambahBatas>
            </p>

            <AksiHalaman>
                <Tombol onClick={() => AturBuat(true)}>Buat token</Tombol>
            </AksiHalaman>

            {buat ? <FormBuat opsi={OpsiCakupan} saatSelesai={() => AturBuat(false)} /> : null}
            {cabut ? <KonfirmasiCabut token={cabut} saatSelesai={() => AturCabut(null)} /> : null}

            <TabelData
                id="pengaturan-token-api"
                label="Daftar token API"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Token }}
                ambilIdBaris={(t) => t.Uuid}
                cari="Cari nama token"
                labelBaris={(t) => `token ${t.Nama}`}
                aksiBaris={(t) =>
                    t.Aktif ? (
                        <ItemAksiBaris aksi={[{ label: 'Cabut', bahaya: true, saatPilih: () => AturCabut(t) }]} />
                    ) : null
                }
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada token API. Buat token untuk menyambungkan aplikasi lain.',
                }}
            />
            {rahasia.aktif ? (
                <DialogTokenBaru nama={rahasia.aktif.Nama} token={rahasia.aktif.Token} saatSelesai={rahasia.selesai} />
            ) : null}
        </TataLetakAplikasi>
    );
}

function DialogTokenBaru({ nama, token, saatSelesai }: { nama: string; token: string; saatSelesai: () => void }) {
    return (
        <DialogRahasia
            judul={`Token ${nama} dibuat`}
            peringatan="Salin token ini sekarang dan simpan di tempat aman. Token tidak bisa ditampilkan lagi. Bila hilang, cabut lalu buat token baru."
            saatSelesai={saatSelesai}
        >
            <NilaiRahasia nilai={token} jenis="token" />
        </DialogRahasia>
    );
}

function FormBuat({ opsi, saatSelesai }: { opsi: PropsHalamanTokenApi['OpsiCakupan']; saatSelesai: () => void }) {
    const formulir = useForm<{ Nama: string; Cakupan: string[]; KedaluwarsaPada: string }>({
        Nama: '',
        Cakupan: [],
        KedaluwarsaPada: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pengaturan/api', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul="Buat token API" saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Nama token"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    keterangan='Nama aplikasi yang memakai, misal "Aplikasi akuntansi"'
                    maxLength={60}
                    autoFocus
                    required
                />
                <GrupCentang
                    legenda="Akses"
                    opsi={opsi.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                    terpilih={formulir.data.Cakupan}
                    saatBerubah={(nilai) => formulir.setData('Cakupan', nilai)}
                    galat={formulir.errors.Cakupan}
                    required
                />
                <PemilihTanggal
                    label="Kedaluwarsa (opsional)"
                    nilai={formulir.data.KedaluwarsaPada}
                    saatBerubah={(nilai) => formulir.setData('KedaluwarsaPada', nilai)}
                    galat={formulir.errors.KedaluwarsaPada}
                    keterangan="Kosongkan bila token berlaku sampai dicabut."
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Buat token
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function KonfirmasiCabut({ token, saatSelesai }: { token: TokenApi; saatSelesai: () => void }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Cabut token ${token.Nama}?`}
            labelAksi="Cabut token"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.delete(`/kelola/pengaturan/api/${token.Uuid}`, {
                    preserveScroll: true,
                    onStart: () => AturMemproses(true),
                    onFinish: () => AturMemproses(false),
                    onSuccess: saatSelesai,
                })
            }
        >
            <p>Aplikasi yang memakai token ini langsung ditolak. Pencabutan tidak bisa dibatalkan.</p>
        </DialogKonfirmasi>
    );
}
