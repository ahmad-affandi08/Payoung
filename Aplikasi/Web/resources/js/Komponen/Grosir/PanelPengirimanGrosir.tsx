import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { KartuKeteranganGrosir, KeteranganGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import Panel from '@/Komponen/Kelola/Panel';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import PemilihTanggalWaktu from '@/Komponen/Tanggal/PemilihTanggalWaktu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailSuratJalan, StatusPengirimanGrosir } from '@/Tipe/Grosir';

type Langkah = 'berangkat' | 'diterima' | 'gagal' | 'batal-berangkat';

export function JenisLabelPengiriman(status: StatusPengirimanGrosir): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Diterima':
            return 'sukses';
        case 'DalamPerjalanan':
            return 'peringatan';
        case 'Gagal':
            return 'bahaya';
        default:
            return 'netral';
    }
}

/**
 * Kabar pengiriman satu surat jalan grosir (F-12 §9.7): status lacak, armada, waktu berangkat/diterima, dan tombol
 * langkah berikutnya. Ini catatan lapangan, bukan perubahan dokumen akuntansi — angka, stok, dan jurnal surat jalan
 * tidak tersentuh.
 */
export default function PanelPengirimanGrosir({
    suratJalan,
    opsiArmada,
    riwayat,
    boleh,
}: {
    suratJalan: PropsDetailSuratJalan['SuratJalan'];
    opsiArmada: PropsDetailSuratJalan['OpsiArmada'];
    riwayat: PropsDetailSuratJalan['RiwayatPengiriman'];
    boleh: boolean;
}) {
    const [langkah, AturLangkah] = useState<Langkah | null>(null);
    const status = suratJalan.StatusPengiriman;
    const alamat = `/kelola/grosir/surat-jalan/${suratJalan.Uuid}/pengiriman`;

    const tombol: { langkah: Langkah; label: string; varian?: 'sekunder' | 'bahaya' }[] = [];

    if (boleh) {
        if (status === 'Disiapkan' || status === 'Gagal') {
            tombol.push({ langkah: 'berangkat', label: status === 'Gagal' ? 'Kirim ulang' : 'Berangkatkan' });
        }

        if (status !== 'Diterima') {
            tombol.push({ langkah: 'diterima', label: 'Tandai diterima' });
        }

        if (status === 'DalamPerjalanan') {
            tombol.push({ langkah: 'gagal', label: 'Gagal kirim', varian: 'bahaya' });
            tombol.push({ langkah: 'batal-berangkat', label: 'Batal berangkat', varian: 'sekunder' });
        }
    }

    return (
        <Panel judul="Pengiriman">
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatus jenis={JenisLabelPengiriman(status)} teks={suratJalan.LabelStatusPengiriman} />
                {tombol.map((t) => (
                    <Tombol key={t.langkah} varian={t.varian ?? 'utama'} onClick={() => AturLangkah(t.langkah)}>
                        {t.label}
                    </Tombol>
                ))}
            </div>
            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Armada">{suratJalan.NamaArmada ?? '—'}</KeteranganGrosir>
                <KeteranganGrosir label="Berangkat">{FormatTanggalWaktu(suratJalan.BerangkatPada)}</KeteranganGrosir>
                <KeteranganGrosir label="Diterima">{FormatTanggalWaktu(suratJalan.DiterimaPada)}</KeteranganGrosir>
                <KeteranganGrosir label="Catatan">{suratJalan.CatatanPengiriman ?? '—'}</KeteranganGrosir>
            </KartuKeteranganGrosir>
            {riwayat.length > 0 ? (
                <ol aria-label="Jejak pengiriman" className="flex flex-col gap-1 text-isi">
                    {riwayat.map((r, i) => (
                        <li key={`${r.Pada}-${String(i)}`} className="break-words text-teks-sekunder">
                            <span className="font-semibold text-teks-utama">{r.StatusKe}</span> |{' '}
                            {FormatTanggalWaktu(r.Pada)}
                            {r.Oleh ? ` oleh ${r.Oleh}` : ''}
                            {r.Alasan ? ` — ${r.Alasan}` : ''}
                        </li>
                    ))}
                </ol>
            ) : null}

            {langkah !== null ? (
                <DialogPengiriman
                    langkah={langkah}
                    alamat={`${alamat}/${langkah}`}
                    opsiArmada={opsiArmada}
                    saatTutup={() => AturLangkah(null)}
                />
            ) : null}
        </Panel>
    );
}

const judulLangkah: Record<Langkah, { judul: string; keterangan: string; aksi: string }> = {
    berangkat: {
        judul: 'Berangkatkan barang',
        keterangan: 'Pilih armada dan pengemudinya. Nomor kendaraan dan nama pengemudi ikut tercetak di surat jalan.',
        aksi: 'Berangkat',
    },
    diterima: {
        judul: 'Barang diterima pembeli',
        keterangan: 'Catat siapa yang menerima. Barang yang sudah diterima dan perlu kembali diproses lewat retur.',
        aksi: 'Simpan penerimaan',
    },
    gagal: {
        judul: 'Gagal kirim',
        keterangan:
            'Tulis sebabnya (toko tutup, ditolak, alamat salah). Surat jalan tetap berlaku. Bisa dikirim ulang.',
        aksi: 'Catat gagal kirim',
    },
    'batal-berangkat': {
        judul: 'Batalkan keberangkatan',
        keterangan: 'Status kembali ke Disiapkan. Armada yang tadi dipilih dikosongkan saat berangkat lagi.',
        aksi: 'Batalkan keberangkatan',
    },
};

function DialogPengiriman({
    langkah,
    alamat,
    opsiArmada,
    saatTutup,
}: {
    langkah: Langkah;
    alamat: string;
    opsiArmada: PropsDetailSuratJalan['OpsiArmada'];
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [uuidArmada, AturArmada] = useState('');
    const [pengemudi, AturPengemudi] = useState('');
    const [penerima, AturPenerima] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [waktu, AturWaktu] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const teks = judulLangkah[langkah];

    const PilihArmada = (uuid: string) => {
        AturArmada(uuid);
        const armada = opsiArmada.find((a) => a.Uuid === uuid);

        if (armada?.NamaPengemudi && pengemudi === '') {
            AturPengemudi(armada.NamaPengemudi);
        }
    };

    const Kirim = () =>
        router.post(
            alamat,
            {
                UuidArmada: uuidArmada === '' ? null : uuidArmada,
                NamaPengemudi: pengemudi === '' ? null : pengemudi,
                NamaPenerima: penerima === '' ? null : penerima,
                Catatan: catatan === '' ? null : catatan,
                Waktu: waktu === '' ? null : waktu.replace('T', ' '),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );

    const siap =
        langkah === 'diterima' ? penerima.trim() !== '' : langkah === 'gagal' ? catatan.trim().length >= 5 : true;

    return (
        <DialogFormulir
            judul={teks.judul}
            keterangan={teks.keterangan}
            galatUmum={props.errors.Umum}
            saatTutup={saatTutup}
        >
            <div className="flex flex-col gap-4">
                {langkah === 'berangkat' ? (
                    <>
                        <BidangPilihan
                            label="Armada"
                            nilai={uuidArmada}
                            kosong="Tanpa armada terdaftar"
                            opsi={opsiArmada.map((a) => ({ Nilai: a.Uuid, Label: `${a.Nama} (${a.NomorKendaraan})` }))}
                            saatBerubah={PilihArmada}
                            galat={props.errors.UuidArmada}
                        />
                        <BidangTeks
                            label="Nama pengemudi"
                            nilai={pengemudi}
                            saatBerubah={AturPengemudi}
                            maxLength={100}
                            galat={props.errors.NamaPengemudi}
                        />
                    </>
                ) : null}
                {langkah === 'diterima' ? (
                    <>
                        <BidangTeks
                            label="Nama penerima"
                            nilai={penerima}
                            saatBerubah={AturPenerima}
                            maxLength={100}
                            required
                            galat={props.errors.NamaPenerima}
                        />
                        <PemilihTanggalWaktu
                            label="Waktu diterima"
                            nilai={waktu}
                            saatBerubah={AturWaktu}
                            keterangan="Kosongkan bila baru saja diterima."
                            galat={props.errors.Waktu}
                        />
                    </>
                ) : null}
                {langkah !== 'batal-berangkat' ? (
                    <BidangTeksPanjang
                        label={langkah === 'gagal' ? 'Alasan' : 'Catatan'}
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        maksimal={500}
                        required={langkah === 'gagal'}
                        galat={props.errors.Catatan}
                    />
                ) : null}
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Kembali
                    </Tombol>
                    <Tombol
                        type="button"
                        varian={langkah === 'gagal' ? 'bahaya' : 'utama'}
                        memproses={memproses}
                        disabled={!siap}
                        onClick={Kirim}
                    >
                        {teks.aksi}
                    </Tombol>
                </div>
            </div>
        </DialogFormulir>
    );
}
