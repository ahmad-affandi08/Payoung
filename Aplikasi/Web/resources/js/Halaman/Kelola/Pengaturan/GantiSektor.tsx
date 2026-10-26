import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import PanelMenuSektor from '@/Komponen/Kelola/PanelMenuSektor';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

type OutletSektor = { Uuid: string; Nama: string; KodeTemplate: string | null; NamaTemplate: string | null };
type TemplateSektor = { Kode: string; Nama: string; Keterangan: string | null };
type Dipertahankan = { Jenis: string; Nama: string; Alasan: string };

export type PratinjauGantiSektor = {
    UuidOutlet: string;
    NamaOutlet: string;
    Lama: { Kode: string; Nama: string; Versi: number } | null;
    Baru: {
        Kode: string;
        Nama: string;
        Versi: number;
        JumlahAkun: number;
        JumlahKategori: number;
        JumlahFitur: number;
        ModeKasir: string[];
    };
    Dibersihkan: { Akun: string[]; Kategori: string[]; StasiunDapur: string[]; Fitur: string[] };
    Dipertahankan: Dipertahankan[];
};

type PropsGantiSektor = {
    Outlet: OutletSektor[];
    Template: TemplateSektor[];
    Pratinjau: PratinjauGantiSektor | null;
};

const alamat = '/kelola/pengaturan/ganti-sektor';

function BarisDibersihkan({ judul, butir }: { judul: string; butir: string[] }) {
    if (butir.length === 0) {
        return null;
    }

    return (
        <li className="flex flex-col gap-1">
            <span className="text-label font-semibold text-teks-utama">
                {judul} ({String(butir.length)})
            </span>
            <span className="text-keterangan text-teks-sekunder">{butir.join(' | ')}</span>
        </li>
    );
}

/**
 * Pengaturan › Ganti jenis usaha (D-165, khusus Pemilik): salah pilih sektor saat daftar? Pilih outlet dan jenis
 * usaha yang benar, lihat pratinjaunya, lalu terapkan. Sisa jenis usaha lama dibersihkan hanya bila belum dipakai.
 */
export default function HalamanGantiSektor({ Outlet, Template, Pratinjau }: PropsGantiSektor) {
    const [uuidOutlet, AturUuidOutlet] = useState(Pratinjau?.UuidOutlet ?? '');
    const [kodeTemplate, AturKodeTemplate] = useState(Pratinjau?.Baru.Kode ?? '');
    const [bersihkan, AturBersihkan] = useState(true);
    const [konfirmasi, AturKonfirmasi] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    // Daftar sektor tepat sebelum penggantian terakhir: dipakai panel menu untuk menunjukkan apa yang berubah.
    const [sektorSebelum, AturSektorSebelum] = useState<string[] | null>(null);
    const { props } = usePage<PropsBersamaAplikasi>();

    const MuatPratinjau = (outlet: string, kode: string) => {
        if (outlet === '' || kode === '') {
            return;
        }

        router.get(
            alamat,
            { UuidOutlet: outlet, KodeTemplate: kode },
            { only: ['Pratinjau'], preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const PilihOutlet = (nilai: string) => {
        AturSektorSebelum(null);
        AturUuidOutlet(nilai);
        MuatPratinjau(nilai, kodeTemplate);
    };
    const PilihTemplate = (nilai: string) => {
        AturSektorSebelum(null);
        AturKodeTemplate(nilai);
        MuatPratinjau(uuidOutlet, nilai);
    };
    const Terapkan = () => {
        const sektorSaatKirim = props.SektorOutlet ?? [];

        router.post(
            alamat,
            { UuidOutlet: uuidOutlet, KodeTemplate: kodeTemplate, Bersihkan: bersihkan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onSuccess: () => AturSektorSebelum(sektorSaatKirim),
                onFinish: () => {
                    AturMemproses(false);
                    AturKonfirmasi(false);
                },
            },
        );
    };

    const outlet = Outlet.find((satu) => satu.Uuid === uuidOutlet);
    const opsiOutlet = Outlet.map((satu) => ({
        Nilai: satu.Uuid,
        Label: satu.Nama,
        Keterangan: satu.NamaTemplate ? `Sekarang: ${satu.NamaTemplate}` : 'Belum memilih jenis usaha',
    }));
    const opsiTemplate = Template.filter((satu) => satu.Kode !== outlet?.KodeTemplate).map((satu) => ({
        Nilai: satu.Kode,
        Label: satu.Nama,
        Keterangan: satu.Keterangan,
    }));
    const pratinjau =
        Pratinjau !== null && Pratinjau.UuidOutlet === uuidOutlet && Pratinjau.Baru.Kode === kodeTemplate
            ? Pratinjau
            : null;

    return (
        <TataLetakAplikasi judul="Ganti jenis usaha">
            <div className="grid gap-4">
                <Pemberitahuan jenis="info" judul="Aman, tidak ada transaksi yang berubah">
                    Salah memilih jenis usaha saat mendaftar? Pilih yang benar di sini. Produk, transaksi, jurnal, dan
                    data pelanggan Anda tidak disentuh. Data bawaan jenis usaha lama (akun, kategori, fitur) hanya
                    dibersihkan bila belum pernah dipakai, yang sudah dipakai tetap ada.
                </Pemberitahuan>
                <Panel judul="Pilih outlet dan jenis usaha baru" tingkat="h2">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <BidangOutlet nilai={uuidOutlet} opsi={opsiOutlet} saatBerubah={PilihOutlet} />
                        <BidangPilihan
                            label="Jenis usaha baru"
                            nilai={kodeTemplate}
                            opsi={opsiTemplate}
                            saatBerubah={PilihTemplate}
                            kosong="Pilih jenis usaha"
                            required
                        />
                    </div>
                </Panel>
                {pratinjau ? (
                    <Panel
                        judul={`${pratinjau.Lama?.Nama ?? 'Belum ada jenis usaha'} menjadi ${pratinjau.Baru.Nama}`}
                        tingkat="h2"
                        keterangan={`Outlet ${pratinjau.NamaOutlet}`}
                    >
                        <ul className="grid gap-3" aria-label="Rincian perubahan">
                            <li className="flex flex-col gap-1">
                                <span className="text-label font-semibold text-teks-utama">Ditambahkan</span>
                                <span className="text-keterangan text-teks-sekunder">
                                    Dari {pratinjau.Baru.Nama}: {String(pratinjau.Baru.JumlahAkun)} akun,{' '}
                                    {String(pratinjau.Baru.JumlahKategori)} kategori, dan{' '}
                                    {String(pratinjau.Baru.JumlahFitur)} fitur. Yang sudah Anda miliki tidak diubah.
                                </span>
                            </li>
                            {pratinjau.Baru.ModeKasir.length > 0 ? (
                                <li className="flex flex-col gap-1">
                                    <span className="text-label font-semibold text-teks-utama">Tampilan kasir</span>
                                    <span className="text-keterangan text-teks-sekunder">
                                        Mengikuti jenis usaha baru: {pratinjau.Baru.ModeKasir.join(' | ')}.
                                    </span>
                                </li>
                            ) : null}
                        </ul>
                        <div className="mt-4 grid gap-3">
                            <KotakCentang
                                label="Bersihkan sisa jenis usaha lama yang belum dipakai"
                                nilai={bersihkan}
                                saatBerubah={AturBersihkan}
                            />
                            {bersihkan ? (
                                <ul className="grid gap-3" aria-label="Yang dibersihkan">
                                    <BarisDibersihkan judul="Akun dihapus" butir={pratinjau.Dibersihkan.Akun} />
                                    <BarisDibersihkan judul="Kategori dihapus" butir={pratinjau.Dibersihkan.Kategori} />
                                    <BarisDibersihkan
                                        judul="Stasiun dapur diarsipkan"
                                        butir={pratinjau.Dibersihkan.StasiunDapur}
                                    />
                                    <BarisDibersihkan judul="Fitur dilepas" butir={pratinjau.Dibersihkan.Fitur} />
                                    {pratinjau.Dibersihkan.Akun.length +
                                        pratinjau.Dibersihkan.Kategori.length +
                                        pratinjau.Dibersihkan.StasiunDapur.length +
                                        pratinjau.Dibersihkan.Fitur.length ===
                                    0 ? (
                                        <li className="text-keterangan text-teks-sekunder">
                                            Tidak ada sisa jenis usaha lama yang perlu dibersihkan.
                                        </li>
                                    ) : null}
                                </ul>
                            ) : (
                                <p className="text-keterangan text-teks-sekunder">
                                    Sisa data jenis usaha lama dibiarkan apa adanya. Anda bisa merapikannya sendiri
                                    nanti.
                                </p>
                            )}
                            {pratinjau.Dipertahankan.length > 0 ? (
                                <div className="flex flex-col gap-1">
                                    <span className="text-label font-semibold text-teks-utama">
                                        Tetap dipertahankan ({String(pratinjau.Dipertahankan.length)})
                                    </span>
                                    <ul className="grid gap-1 text-keterangan text-teks-sekunder">
                                        {pratinjau.Dipertahankan.map((satu) => (
                                            <li key={`${satu.Jenis}-${satu.Nama}`}>
                                                {satu.Nama}: {satu.Alasan}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </div>
                        <div className="mt-4">
                            <Tombol onClick={() => AturKonfirmasi(true)}>Ganti jenis usaha</Tombol>
                        </div>
                    </Panel>
                ) : null}
                <PanelMenuSektor sebelum={sektorSebelum} />
            </div>
            {konfirmasi && pratinjau ? (
                <DialogKonfirmasi
                    judul={`Ganti jenis usaha ${pratinjau.NamaOutlet}?`}
                    labelAksi="Ganti jenis usaha"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={Terapkan}
                    saatBatal={() => AturKonfirmasi(false)}
                >
                    <p>
                        Jenis usaha berganti menjadi {pratinjau.Baru.Nama}.{' '}
                        {bersihkan
                            ? 'Sisa data jenis usaha lama yang belum dipakai ikut dibersihkan.'
                            : 'Data jenis usaha lama dibiarkan.'}{' '}
                        Transaksi dan produk Anda tidak berubah.
                    </p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
