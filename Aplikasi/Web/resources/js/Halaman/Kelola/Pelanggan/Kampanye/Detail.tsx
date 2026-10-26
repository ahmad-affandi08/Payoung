import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggalWaktu from '@/Komponen/Tanggal/PemilihTanggalWaktu';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { UbahWaktuLokalKeIsoUtc as UbahKeIsoUtc } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisPenerimaKampanye, PropsDetailKampanye, StatusPenerimaKampanye } from '@/Tipe/Kampanye';

import { AlamatKampanye, JenisStatusKampanye } from './Daftar';

const JenisStatusPenerima: Record<StatusPenerimaKampanye, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Diantrekan: 'netral',
    Terkirim: 'sukses',
    Gagal: 'bahaya',
    Dilewati: 'peringatan',
};

const kolomPenerima: KolomTabel<BarisPenerimaKampanye>[] = [
    {
        id: 'NamaPelanggan',
        accessorKey: 'NamaPelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={JenisStatusPenerima[p.Status]} teks={p.LabelStatus} />
                {p.PesanGalat ? (
                    <span className="text-keterangan break-words text-teks-sekunder">{p.PesanGalat}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'TerkirimPada',
        accessorKey: 'TerkirimPada',
        header: 'Terkirim',
        meta: { label: 'Terkirim', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => (row.original.TerkirimPada ? FormatTanggalWaktu(row.original.TerkirimPada) : '–'),
    },
];

export { UbahWaktuLokalKeIsoUtc as UbahKeIsoUtc } from '@/Pustaka/Tanggal';

/**
 * CRM-07 rincian kampanye: isi & contoh pesan, segmen, progres kirim, daftar penerima (tanpa nomor/email), serta aksi
 * kirim sekarang, jadwalkan, ubah draf, dan batalkan.
 */
export default function HalamanDetailKampanye({
    Kampanye: k,
    Penerima,
    OpsiStatusPenerima,
    Aturan,
}: PropsDetailKampanye) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [konfirmasi, AturKonfirmasi] = useState<'kirim' | 'jadwal' | 'batal' | null>(null);
    const [jadwal, AturJadwal] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const draf = k.Status === 'Draf';
    const bisaBatal = k.Status === 'Draf' || k.Status === 'Dijadwalkan' || k.Status === 'Berjalan';
    const opsi = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onSuccess: () => AturKonfirmasi(null),
    };
    const ringkasan: [string, string][] = [
        ['Kanal', k.LabelKanal],
        ['Penerima', k.JumlahPenerima === 0 ? '–' : String(k.JumlahPenerima)],
        ['Terkirim', String(k.JumlahTerkirim)],
        ['Gagal', String(k.JumlahGagal)],
        ['Dilewati', String(k.JumlahDilewati)],
    ];

    return (
        <TataLetakAplikasi judul={k.Nama} jejak={[{ label: 'Kampanye pesan', href: AlamatKampanye }]}>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {props.errors.Kanal ? <Pemberitahuan jenis="bahaya">{props.errors.Kanal}</Pemberitahuan> : null}

            <div className="flex flex-wrap items-center gap-2">
                <LabelStatus jenis={JenisStatusKampanye[k.Status]} teks={k.LabelStatus} />
                {k.Status === 'Dijadwalkan' && k.DijadwalkanPada ? (
                    <span className="text-label text-teks-sekunder">
                        Dikirim mulai {FormatTanggalWaktu(k.DijadwalkanPada)}
                    </span>
                ) : null}
                {k.MulaiPada ? (
                    <span className="text-label text-teks-sekunder">Mulai {FormatTanggalWaktu(k.MulaiPada)}</span>
                ) : null}
                {k.SelesaiPada ? (
                    <span className="text-label text-teks-sekunder">Berakhir {FormatTanggalWaktu(k.SelesaiPada)}</span>
                ) : null}
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-5">
                {ringkasan.map(([label, nilai]) => (
                    <div key={label} className="min-w-0">
                        <dt className="text-label text-teks-sekunder">{label}</dt>
                        <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">{nilai}</dd>
                    </div>
                ))}
            </dl>

            <Panel judul="Pesan" idJudul="judul-pesan-kampanye" keterangan={k.Segmen.join(' | ')}>
                {k.Judul ? <p className="text-isi font-semibold text-teks-utama">{k.Judul}</p> : null}
                <p className="text-label text-teks-sekunder">Contoh yang diterima pelanggan bernama Budi:</p>
                <p className="rounded-kontrol border border-garis bg-latar p-3 text-isi break-words whitespace-pre-line text-teks-utama">
                    {k.Contoh}
                </p>
                {k.Status === 'Berjalan' || draf ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Dikirim bertahap {String(Aturan.UkuranGiliran)} pesan tiap {String(Aturan.JedaDetik / 60)}{' '}
                        menit, hanya pukul {String(Aturan.JamMulai).padStart(2, '0')}.00–
                        {String(Aturan.JamSelesai).padStart(2, '0')}.00 waktu usaha. Pelanggan yang berhenti
                        berlangganan sebelum gilirannya dilewati.
                    </p>
                ) : null}
            </Panel>

            {draf || bisaBatal ? (
                <BilahAksiForm>
                    {draf ? (
                        <>
                            <Tombol onClick={() => AturKonfirmasi('kirim')}>Kirim sekarang</Tombol>
                            <Tombol varian="sekunder" onClick={() => AturKonfirmasi('jadwal')}>
                                Jadwalkan
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => router.visit(`${AlamatKampanye}/${k.Uuid}/ubah`)}>
                                Ubah draf
                            </Tombol>
                        </>
                    ) : null}
                    {bisaBatal ? (
                        <Tombol varian="bahaya" onClick={() => AturKonfirmasi('batal')}>
                            Batalkan kampanye
                        </Tombol>
                    ) : null}
                </BilahAksiForm>
            ) : null}

            {k.JumlahPenerima > 0 ? (
                <section aria-labelledby="judul-daftar-penerima" className="flex flex-col gap-2">
                    <h2 id="judul-daftar-penerima" className="text-subjudul font-semibold text-teks-utama">
                        Penerima
                    </h2>
                    <TabelData
                        id="kampanye-penerima"
                        label="Penerima kampanye"
                        kolom={kolomPenerima}
                        sumber={{ mode: 'server', alamat: `${AlamatKampanye}/${k.Uuid}`, awal: Penerima }}
                        ambilIdBaris={(p) => p.Kunci}
                        urutBawaan="TerkirimPada"
                        cari={false}
                        saring={[
                            {
                                id: 'Status',
                                label: 'Status',
                                jenis: 'pilihanBanyak',
                                opsi: OpsiStatusPenerima.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                            },
                        ]}
                        kosong={{ judul: 'Tidak ada penerima dengan status ini.' }}
                    />
                </section>
            ) : null}

            {konfirmasi === 'kirim' ? (
                <DialogKonfirmasi
                    judul="Kirim kampanye sekarang?"
                    labelAksi="Kirim sekarang"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => router.post(`${AlamatKampanye}/${k.Uuid}/jalankan`, {}, opsi)}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    Daftar penerima dikunci sekarang (pelanggan yang bergabung sesudahnya tidak ikut) dan pesan mulai
                    dikirim bertahap. Pesan yang sudah terkirim tidak bisa ditarik.
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'jadwal' ? (
                <DialogKonfirmasi
                    judul="Jadwalkan kampanye"
                    labelAksi="Jadwalkan"
                    varian="utama"
                    memproses={memproses}
                    nonaktif={UbahKeIsoUtc(jadwal) === null}
                    saatKonfirmasi={() =>
                        router.post(
                            `${AlamatKampanye}/${k.Uuid}/jalankan`,
                            { DijadwalkanPada: UbahKeIsoUtc(jadwal) },
                            opsi,
                        )
                    }
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <span className="flex flex-col gap-3">
                        <span>Penerima dipilih saat jadwal tiba, jadi pelanggan baru yang cocok ikut menerima.</span>
                        <PemilihTanggalWaktu
                            label="Mulai kirim"
                            nilai={jadwal}
                            saatBerubah={AturJadwal}
                            jamBawaan="09:00"
                            galat={props.errors.DijadwalkanPada}
                        />
                    </span>
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'batal' ? (
                <DialogKonfirmasi
                    judul="Batalkan kampanye?"
                    labelAksi="Batalkan kampanye"
                    memproses={memproses}
                    saatKonfirmasi={() => router.post(`${AlamatKampanye}/${k.Uuid}/batal`, {}, opsi)}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    Pesan yang belum terkirim tidak akan dikirim. Yang sudah terkirim tetap tercatat.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
