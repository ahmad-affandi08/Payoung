import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { BarisKampanyeProspek, PenerimaKampanyeProspekTampil } from '@/Tipe/KampanyeProspek';
import { JenisStatusKampanye } from './Daftar';

type PropsTampil = {
    Kampanye: BarisKampanyeProspek & { Isi: string };
    EmailKampanyeAktif: boolean;
    Penerima: PenerimaKampanyeProspekTampil[];
};

type AksiStatus = {
    status: 'Berjalan' | 'Dijeda' | 'Dibatalkan';
    judul: string;
    labelAksi: string;
    varian: 'utama' | 'bahaya';
    isi: string;
};

const JENIS_PENERIMA: Record<PenerimaKampanyeProspekTampil['Status'], 'sukses' | 'bahaya' | 'peringatan' | 'netral'> = {
    Menunggu: 'netral',
    Diproses: 'netral',
    Terkirim: 'sukses',
    Gagal: 'bahaya',
    Dilewati: 'peringatan',
};

const kolom: KolomTabel<PenerimaKampanyeProspekTampil>[] = [
    {
        id: 'Email',
        accessorKey: 'Email',
        header: 'Penerima',
        meta: { label: 'Penerima', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="font-mono">{p.Email}</span>
                <span className="block text-keterangan font-normal text-teks-sekunder">{p.Sektor}</span>
            </>
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Hasil',
        meta: { label: 'Hasil', prioritas: 'utama' },
        cell: ({ row: { original: p } }) => <LabelStatus jenis={JENIS_PENERIMA[p.Status]} teks={p.Status} />,
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: p } }) => p.Alasan ?? (p.DikirimPada ? FormatTanggalWaktu(p.DikirimPada) : '—'),
    },
];

/**
 * Rincian kampanye prospek (D-216): isi, hasil, email uji ke diri sendiri, jalankan, jeda, lanjutkan, dan batalkan, serta
 * hasil per penerima (gagal dan dilewati di atas).
 */
export default function Tampil({ Kampanye, EmailKampanyeAktif, Penerima }: PropsTampil) {
    const [aksi, AturAksi] = useState<AksiStatus | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const Selesaikan = () => {
        AturMemproses(false);
        AturAksi(null);
    };

    const UbahStatus = (tujuan: AksiStatus['status']) =>
        router.put(
            `/kampanye-prospek/${Kampanye.Uuid}/status`,
            { Status: tujuan },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: Selesaikan },
        );

    const KirimUji = () =>
        router.post(
            `/kampanye-prospek/${Kampanye.Uuid}/uji`,
            {},
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakPengelola judul={Kampanye.Nama} jejak={[{ label: 'Kampanye prospek', href: '/kampanye-prospek' }]}>
            {Kampanye.Status === 'Dijeda' && Kampanye.AlasanJeda ? (
                <Pemberitahuan jenis="peringatan" judul="Kampanye dijeda">
                    {Kampanye.AlasanJeda}
                </Pemberitahuan>
            ) : null}
            {!EmailKampanyeAktif && (Kampanye.Status === 'Draf' || Kampanye.Status === 'Dijeda') ? (
                <Pemberitahuan jenis="peringatan" judul="Email kampanye belum aktif">
                    Atur pengirim email di Integrasi › Email (atau Email kampanye untuk domain terpisah) sebelum
                    menjalankan kampanye.
                </Pemberitahuan>
            ) : null}
            <Panel tingkat="h3" judul="Ringkasan">
                <div className="flex flex-wrap items-center gap-3">
                    <LabelStatus jenis={JenisStatusKampanye(Kampanye.Status)} teks={Kampanye.LabelStatus} />
                    <span className="text-isi text-teks-sekunder">
                        {Kampanye.Sektor.join(', ')} | Batas {String(Kampanye.BatasPerHari)} email per hari
                        {Kampanye.MulaiKirimPada ? ` | Mulai ${FormatTanggalWaktu(Kampanye.MulaiKirimPada)}` : ''}
                    </span>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {Kampanye.Status === 'Draf' ||
                        Kampanye.Status === 'Dijeda' ||
                        Kampanye.Status === 'Berjalan' ? (
                            <Tombol
                                varian="sekunder"
                                onClick={KirimUji}
                                memproses={memproses}
                                disabled={!EmailKampanyeAktif}
                            >
                                Kirim email uji ke saya
                            </Tombol>
                        ) : null}
                        {Kampanye.Status === 'Draf' ? (
                            <Tombol
                                disabled={!EmailKampanyeAktif}
                                onClick={() =>
                                    AturAksi({
                                        status: 'Berjalan',
                                        judul: 'Jalankan kampanye?',
                                        labelAksi: 'Jalankan kampanye',
                                        varian: 'utama',
                                        isi: `Email dikirim ke semua kontak aktif di sektor terpilih, sedikit demi sedikit (paling banyak ${String(Kampanye.BatasPerHari)} per hari, pukul 08.00–17.00 WIB). Daftar penerima ditetapkan sekarang, yang berhenti berlangganan sesudahnya tidak dikirimi.`,
                                    })
                                }
                            >
                                Jalankan kampanye
                            </Tombol>
                        ) : null}
                        {Kampanye.Status === 'Dijeda' ? (
                            <Tombol
                                disabled={!EmailKampanyeAktif}
                                onClick={() =>
                                    AturAksi({
                                        status: 'Berjalan',
                                        judul: 'Lanjutkan kampanye?',
                                        labelAksi: 'Lanjutkan kampanye',
                                        varian: 'utama',
                                        isi: 'Pengiriman dilanjutkan dari penerima yang masih menunggu.',
                                    })
                                }
                            >
                                Lanjutkan kampanye
                            </Tombol>
                        ) : null}
                        {Kampanye.Status === 'Berjalan' ? (
                            <Tombol
                                varian="sekunder"
                                onClick={() =>
                                    AturAksi({
                                        status: 'Dijeda',
                                        judul: 'Jeda kampanye?',
                                        labelAksi: 'Jeda kampanye',
                                        varian: 'utama',
                                        isi: 'Tidak ada email baru yang dikirim sampai kampanye dilanjutkan. Yang sudah terkirim tidak bisa ditarik.',
                                    })
                                }
                            >
                                Jeda kampanye
                            </Tombol>
                        ) : null}
                        {Kampanye.DapatDibatalkan ? (
                            <Tombol
                                varian="bahaya"
                                onClick={() =>
                                    AturAksi({
                                        status: 'Dibatalkan',
                                        judul: 'Batalkan kampanye?',
                                        labelAksi: 'Batalkan kampanye',
                                        varian: 'bahaya',
                                        isi: 'Penerima yang belum dikirimi dilewati dan kampanye tidak bisa dijalankan lagi. Yang sudah terkirim tidak bisa ditarik.',
                                    })
                                }
                            >
                                Batalkan kampanye
                            </Tombol>
                        ) : null}
                    </div>
                </div>
                <p className="text-isi tabular-nums text-teks-utama">
                    {Kampanye.JumlahTerkirim.toLocaleString('id-ID')} terkirim | {String(Kampanye.JumlahGagal)} gagal |{' '}
                    {String(Kampanye.JumlahDilewati)} dilewati dari {Kampanye.JumlahPenerima.toLocaleString('id-ID')}{' '}
                    penerima
                </p>
            </Panel>
            <Panel tingkat="h3" judul="Isi email">
                <p className="text-isi font-semibold text-teks-utama">{Kampanye.Subjek}</p>
                <p className="max-w-3xl whitespace-pre-line text-isi text-teks-sekunder">{Kampanye.Isi}</p>
            </Panel>
            <TabelData
                id="pengelola-kampanye-prospek-penerima"
                label="Penerima kampanye"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Penerima }}
                ambilIdBaris={(p) => String(p.Id)}
                cari="Cari alamat email"
                saring={[
                    {
                        id: 'Status',
                        label: 'Hasil',
                        jenis: 'pilihanBanyak',
                        opsi: ['Terkirim', 'Gagal', 'Dilewati', 'Menunggu', 'Diproses'].map((nilai) => ({
                            nilai,
                            label: nilai,
                        })),
                    },
                ]}
                kosong={{ judul: 'Penerima ditetapkan saat kampanye dijalankan.' }}
            />
            {aksi ? (
                <DialogKonfirmasi
                    judul={aksi.judul}
                    labelAksi={aksi.labelAksi}
                    varian={aksi.varian}
                    memproses={memproses}
                    saatKonfirmasi={() => UbahStatus(aksi.status)}
                    saatBatal={() => AturAksi(null)}
                >
                    {aksi.isi}
                </DialogKonfirmasi>
            ) : null}
        </TataLetakPengelola>
    );
}
