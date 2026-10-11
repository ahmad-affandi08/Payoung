import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import DialogRahasia, { NilaiRahasia, useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { KirimanWebhook, PropsHalamanWebhook, WebhookTenant } from '@/Tipe/ApiPublik';

const kolomWebhook: KolomTabel<WebhookTenant>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: w } }) => (
            <>
                <span className="block text-teks-utama">{w.Nama}</span>
                <span className="block font-mono text-label break-all text-teks-sekunder">{w.Url}</span>
            </>
        ),
    },
    {
        id: 'Aktif',
        accessorFn: (w) => (w.Aktif ? 'Aktif' : 'Nonaktif'),
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: w } }) =>
            w.Aktif ? <LabelStatus jenis="sukses" teks="Aktif" /> : <LabelStatus jenis="netral" teks="Nonaktif" />,
    },
    {
        id: 'Peristiwa',
        accessorFn: (w) => w.Peristiwa.join(', '),
        header: 'Peristiwa',
        enableSorting: false,
        meta: { label: 'Peristiwa', prioritas: 'penting', kelasSel: 'font-mono text-label text-teks-sekunder' },
    },
];

const labelStatus: Record<KirimanWebhook['Status'], { jenis: 'sukses' | 'peringatan' | 'bahaya'; teks: string }> = {
    Terkirim: { jenis: 'sukses', teks: 'Terkirim' },
    Menunggu: { jenis: 'peringatan', teks: 'Menunggu' },
    Gagal: { jenis: 'bahaya', teks: 'Gagal' },
};

const kolomKiriman: KolomTabel<KirimanWebhook>[] = [
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.DibuatPada ? FormatTanggalWaktu(row.original.DibuatPada) : '-'),
    },
    {
        id: 'Peristiwa',
        accessorKey: 'Peristiwa',
        header: 'Peristiwa',
        meta: { label: 'Peristiwa', prioritas: 'penting', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'NamaWebhook',
        accessorKey: 'NamaWebhook',
        header: 'Webhook',
        meta: { label: 'Webhook', prioritas: 'rendah' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: k } }) => <LabelStatus {...labelStatus[k.Status]} />,
    },
    {
        id: 'Hasil',
        accessorFn: (k) => k.KodeRespons ?? '',
        header: 'Hasil',
        enableSorting: false,
        meta: { label: 'Hasil', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: k } }) => <RingkasanHasil kiriman={k} />,
    },
];

function RingkasanHasil({ kiriman: k }: { kiriman: KirimanWebhook }) {
    const kode = k.KodeRespons ? `HTTP ${k.KodeRespons}` : null;
    const percobaan = k.Percobaan > 0 ? `${k.Percobaan} kali gagal` : null;
    const berikutnya =
        k.Status === 'Menunggu' && k.BerikutnyaPada ? `coba lagi ${FormatTanggalWaktu(k.BerikutnyaPada)}` : null;

    return (
        <>
            <span className="block">{[kode, percobaan, berikutnya].filter(Boolean).join(' | ') || '-'}</span>
            {k.CuplikanRespons ? (
                <span className="block max-w-md truncate font-mono text-label" title={k.CuplikanRespons}>
                    {k.CuplikanRespons}
                </span>
            ) : null}
        </>
    );
}

/**
 * X7 bagian 2: Pengaturan › Webhook (khusus Owner). Payoung mengirim POST JSON bertanda tangan HMAC-SHA256 ke alamat
 * HTTPS milik aplikasi lain saat penjualan selesai, di-void, atau diretur. Gagal dicoba lagi 1 menit, 5 menit,
 * 30 menit, 2 jam, lalu 12 jam; log 30 hari terakhir bisa dikirim ulang.
 */
export default function HalamanWebhook({ Webhook, Kiriman, OpsiPeristiwa, RahasiaBaru }: PropsHalamanWebhook) {
    const rahasia = useRahasiaSekali(RahasiaBaru);
    const [buat, AturBuat] = useState(false);
    const [hapus, AturHapus] = useState<WebhookTenant | null>(null);

    const UbahStatus = (w: WebhookTenant) =>
        router.put(`/kelola/pengaturan/webhook/${w.Uuid}/status`, { Aktif: !w.Aktif }, { preserveScroll: true });
    const KirimUlang = (k: KirimanWebhook) =>
        router.post(`/kelola/pengaturan/webhook/kiriman/${k.Uuid}/kirim-ulang`, {}, { preserveScroll: true });

    return (
        <TataLetakAplikasi judul="Webhook">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Webhook memberi tahu aplikasi lain secara otomatis saat sesuatu terjadi di toko Anda. Tiap kiriman
                membawa header <span className="font-mono">X-Id-Peristiwa</span> untuk mencegah dobel dan{' '}
                <span className="font-mono">X-Tanda-Tangan: sha256=…</span>, yaitu HMAC-SHA256 dari{' '}
                <span className="font-mono">X-Waktu-Kirim</span>, titik, lalu isi kiriman, memakai rahasia webhook.
                Kiriman yang gagal dicoba lagi 1 menit, 5 menit, 30 menit, 2 jam, lalu 12 jam kemudian.{' '}
                <AjakanTambahBatas teksLisensi={null}>
                    Contoh kode pemeriksaan tanda tangan ada di{' '}
                    <a className="font-semibold text-brand underline" href="/pengembang#webhook">
                        dokumentasi API
                    </a>
                    .
                </AjakanTambahBatas>
            </p>

            <AksiHalaman>
                <Tombol onClick={() => AturBuat(true)}>Tambah webhook</Tombol>
            </AksiHalaman>

            {buat ? <FormBuat opsi={OpsiPeristiwa} saatSelesai={() => AturBuat(false)} /> : null}
            {hapus ? <KonfirmasiHapus webhook={hapus} saatSelesai={() => AturHapus(null)} /> : null}

            <TabelData
                id="pengaturan-webhook"
                label="Daftar webhook"
                kolom={kolomWebhook}
                sumber={{ mode: 'lokal', data: Webhook }}
                ambilIdBaris={(w) => w.Uuid}
                cari="Cari nama webhook"
                labelBaris={(w) => `webhook ${w.Nama}`}
                aksiBaris={(w) => (
                    <ItemAksiBaris
                        aksi={[
                            { label: w.Aktif ? 'Nonaktifkan' : 'Aktifkan', saatPilih: () => UbahStatus(w) },
                            { label: 'Hapus', bahaya: true, saatPilih: () => AturHapus(w) },
                        ]}
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada webhook. Tambahkan alamat HTTPS aplikasi yang ingin diberi tahu.',
                }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Kiriman terakhir</h2>
            <TabelData
                id="pengaturan-webhook-kiriman"
                label="Log kiriman webhook"
                kolom={kolomKiriman}
                sumber={{ mode: 'lokal', data: Kiriman }}
                ambilIdBaris={(k) => k.Uuid}
                cari="Cari peristiwa atau webhook"
                labelBaris={(k) => `kiriman ${k.Peristiwa}`}
                aksiBaris={(k) =>
                    k.BisaKirimUlang ? (
                        <ItemAksiBaris aksi={[{ label: 'Kirim ulang', saatPilih: () => KirimUlang(k) }]} />
                    ) : null
                }
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kiriman. Kiriman muncul setelah ada penjualan, void, atau retur.',
                }}
            />
            {rahasia.aktif ? (
                <DialogRahasiaBaru
                    nama={rahasia.aktif.Nama}
                    rahasia={rahasia.aktif.Rahasia}
                    saatSelesai={rahasia.selesai}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}

function DialogRahasiaBaru({ nama, rahasia, saatSelesai }: { nama: string; rahasia: string; saatSelesai: () => void }) {
    return (
        <DialogRahasia
            judul={`Webhook ${nama} dibuat`}
            peringatan="Salin rahasia penandatangan ini sekarang dan pasang di aplikasi penerima untuk memeriksa tanda tangan. Rahasia tidak bisa ditampilkan lagi; bila hilang, hapus lalu tambahkan webhook baru."
            saatSelesai={saatSelesai}
        >
            <NilaiRahasia nilai={rahasia} jenis="rahasia" />
        </DialogRahasia>
    );
}

function FormBuat({ opsi, saatSelesai }: { opsi: PropsHalamanWebhook['OpsiPeristiwa']; saatSelesai: () => void }) {
    const formulir = useForm<{ Nama: string; Url: string; Peristiwa: string[] }>({
        Nama: '',
        Url: '',
        Peristiwa: [],
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pengaturan/webhook', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul="Tambah webhook" saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Nama webhook"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    keterangan='Nama aplikasi penerima, misal "Sistem gudang"'
                    maxLength={60}
                    autoFocus
                    required
                />
                <BidangTeks
                    label="Alamat URL"
                    kode
                    inputMode="url"
                    nilai={formulir.data.Url}
                    saatBerubah={(nilai) => formulir.setData('Url', nilai)}
                    galat={formulir.errors.Url}
                    keterangan="Wajib https:// dan bisa diakses dari internet, misal https://contoh.co.id/webhook/payoung."
                    maxLength={500}
                    required
                />
                <GrupCentang
                    legenda="Peristiwa"
                    opsi={opsi.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                    terpilih={formulir.data.Peristiwa}
                    saatBerubah={(nilai) => formulir.setData('Peristiwa', nilai)}
                    galat={formulir.errors.Peristiwa}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan webhook
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function KonfirmasiHapus({ webhook, saatSelesai }: { webhook: WebhookTenant; saatSelesai: () => void }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Hapus webhook ${webhook.Nama}?`}
            labelAksi="Hapus webhook"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.delete(`/kelola/pengaturan/webhook/${webhook.Uuid}`, {
                    preserveScroll: true,
                    onStart: () => AturMemproses(true),
                    onFinish: () => AturMemproses(false),
                    onSuccess: saatSelesai,
                })
            }
        >
            <p>Aplikasi penerima tidak lagi diberi tahu. Kiriman yang masih menunggu ditandai gagal.</p>
        </DialogKonfirmasi>
    );
}
