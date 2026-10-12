import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type { IzinAksesDukungan, RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

type PropsPanel = {
    uuidTenant: string;
    data: RiwayatAksesDukunganTenant;
    bolehMasuk: boolean;
    bolehDarurat: boolean;
};

const jenisStatus = { Berlaku: 'sukses', Berakhir: 'netral', Dicabut: 'bahaya' } as const;

const opsiKategori = [
    { Nilai: 'InsidenKeamanan', Label: 'Insiden keamanan' },
    { Nilai: 'PermintaanHukum', Label: 'Permintaan hukum' },
];

const opsiCakupan = [
    { Nilai: 'BacaSaja', Label: 'Baca saja' },
    { Nilai: 'BacaUbah', Label: 'Baca & ubah' },
];

const opsiDurasi = [1, 2, 3, 4].map((jam) => ({ Nilai: String(jam), Label: `${String(jam)} jam` }));

/**
 * PGL-16 (P-09): izin akses dukungan satu tenant. Petugas hanya bisa "Masuk sebagai tenant" bila Owner memberi izin
 * (atau Super Admin membuka akses darurat dengan alasan tertulis). Panel hanya memuat nama, waktu, dan jumlah
 * aktivitas; tidak ada data pribadi pelanggan.
 */
export default function PanelAksesDukungan({ uuidTenant, data, bolehMasuk, bolehDarurat }: PropsPanel) {
    const [dialog, AturDialog] = useState<'darurat' | { cabut: string } | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);
    const aktif = data.Aktif;

    const Masuk = async () => {
        AturGalat(null);
        AturMemproses(true);
        // Jendela dibuka sinkron dari klik supaya tidak diblokir peramban; alamatnya diisi setelah server menjawab.
        const jendela = window.open('', '_blank');

        try {
            const hasil = await KirimJson<{ Url: string }>(`/tenant/${uuidTenant}/akses-dukungan/masuk`);

            if (jendela === null) {
                window.location.assign(hasil.Url);
            } else {
                jendela.location.href = hasil.Url;
            }
        } catch (kesalahan) {
            jendela?.close();
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Gagal menyiapkan akses. Coba lagi.');
        } finally {
            AturMemproses(false);
        }
    };

    return (
        <Panel judul="Akses dukungan" tingkat="h2">
            <div className="grid gap-3">
                <p className="text-isi text-teks-sekunder">
                    Tim dukungan hanya bisa masuk ke back-office tenant bila Owner memberi izin berbatas waktu. Tanpa
                    izin, hanya Super Admin yang bisa membuka akses darurat (insiden keamanan atau permintaan hukum) dan
                    Owner langsung diberi tahu.
                </p>
                {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}

                {aktif ? (
                    <div
                        className="grid gap-2 rounded-panel border border-garis p-3"
                        aria-label="Izin akses yang berlaku"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <LabelStatus jenis="sukses" teks="Berlaku" />
                            <LabelStatus
                                jenis={aktif.Jenis === 'Darurat' ? 'bahaya' : 'netral'}
                                teks={aktif.LabelJenis}
                            />
                            <span className="text-isi font-semibold text-teks-utama">{aktif.LabelCakupan}</span>
                        </div>
                        <p className="text-keterangan text-teks-sekunder">
                            Sampai {FormatTanggalWaktu(aktif.BerakhirPada)}
                            {aktif.NamaPemberi ? ` | diberi ${aktif.NamaPemberi}` : ''}
                            {aktif.DataPribadiTerbuka
                                ? ' | data pribadi pelanggan terbuka'
                                : ' | data pribadi pelanggan tersamar'}
                            {aktif.NomorTiket ? ` | tiket ${aktif.NomorTiket}` : ''}
                        </p>
                        {aktif.Alasan ? <p className="text-isi text-teks-sekunder">Alasan: {aktif.Alasan}</p> : null}
                        {aktif.SesiBerjalan.length > 0 ? (
                            <p className="text-keterangan text-teks-sekunder">
                                Sesi berjalan: {aktif.SesiBerjalan.map((sesi) => sesi.NamaPetugas).join(', ')}
                            </p>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            {bolehMasuk ? (
                                <Tombol memproses={memproses} onClick={() => void Masuk()}>
                                    Masuk sebagai tenant
                                </Tombol>
                            ) : null}
                            {bolehDarurat && aktif.Jenis === 'Darurat' ? (
                                <Tombol varian="bahaya" onClick={() => AturDialog({ cabut: aktif.Uuid })}>
                                    Tutup akses darurat
                                </Tombol>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Pemberitahuan jenis="info">
                            Tenant ini belum memberi izin akses dukungan. Minta Owner membukanya di Pengaturan › Akses
                            dukungan (lewat tiket dukungan).
                        </Pemberitahuan>
                        {bolehDarurat ? (
                            <div>
                                <Tombol varian="bahaya" onClick={() => AturDialog('darurat')}>
                                    Buka akses darurat
                                </Tombol>
                            </div>
                        ) : null}
                    </div>
                )}

                {data.Izin.length > 0 ? (
                    <ul className="grid gap-2" aria-label="Riwayat izin akses dukungan">
                        {data.Izin.map((izin) => (
                            <BarisIzin key={izin.Uuid} izin={izin} />
                        ))}
                    </ul>
                ) : null}

                {data.Sesi.length > 0 ? (
                    <div className="grid gap-1">
                        <h3 className="text-subjudul font-semibold text-teks-utama">Sesi petugas</h3>
                        <ul className="grid gap-1" aria-label="Sesi akses dukungan">
                            {data.Sesi.map((sesi) => (
                                <li key={sesi.Uuid} className="text-keterangan text-teks-sekunder">
                                    <span className="font-semibold text-teks-utama">{sesi.NamaPetugas}</span> |{' '}
                                    {FormatTanggalWaktu(sesi.MulaiPada)} |{' '}
                                    {sesi.Berjalan ? 'berjalan' : (sesi.AlasanAkhir ?? 'berakhir')} |{' '}
                                    {sesi.JumlahAktivitas} permintaan, {sesi.JumlahUbah} mengubah data,{' '}
                                    {sesi.JumlahDitolak} ditolak
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>

            {dialog === 'darurat' ? <FormDarurat uuidTenant={uuidTenant} saatSelesai={() => AturDialog(null)} /> : null}
            {dialog !== null && dialog !== 'darurat' ? (
                <FormTutupDarurat
                    uuidTenant={uuidTenant}
                    uuidAkses={dialog.cabut}
                    saatSelesai={() => AturDialog(null)}
                />
            ) : null}
        </Panel>
    );
}

function BarisIzin({ izin }: { izin: IzinAksesDukungan }) {
    return (
        <li className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder">
            <LabelStatus jenis={jenisStatus[izin.Status]} teks={izin.Status} />
            <span className="font-semibold text-teks-utama">{izin.LabelJenis}</span>
            <span>
                {izin.LabelCakupan} | {FormatTanggalWaktu(izin.MulaiPada)} sampai{' '}
                {FormatTanggalWaktu(izin.BerakhirPada)} | {izin.JumlahSesi} sesi
                {izin.KategoriDarurat ? ` | ${izin.KategoriDarurat}` : ''}
            </span>
        </li>
    );
}

function FormDarurat({ uuidTenant, saatSelesai }: { uuidTenant: string; saatSelesai: () => void }) {
    const formulir = useForm({ Kategori: 'InsidenKeamanan', Alasan: '', DurasiJam: '1', Cakupan: 'BacaSaja' });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuidTenant}/akses-dukungan/darurat`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Buka akses darurat"
            keterangan={
                <p>
                    Hanya untuk insiden keamanan atau permintaan hukum. Owner langsung diberi tahu lewat email, data
                    pribadi pelanggan tetap tersamar, dan setiap tindakan Anda tercatat. Maksimal 4 jam.
                </p>
            }
            labelKirim="Buka akses darurat"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(galat)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangPilihan
                label="Kategori"
                nilai={formulir.data.Kategori}
                opsi={opsiKategori}
                saatBerubah={(nilai) => formulir.setData('Kategori', nilai)}
                galat={galat.Kategori}
                required
            />
            <BidangTeksPanjang
                label="Alasan tertulis"
                keterangan="Jelaskan insiden atau dasar permintaan hukumnya. Alasan ini dikirim ke Owner."
                nilai={formulir.data.Alasan}
                baris={4}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={galat.Alasan}
                required
            />
            <BidangPilihan
                label="Durasi"
                nilai={formulir.data.DurasiJam}
                opsi={opsiDurasi}
                saatBerubah={(nilai) => formulir.setData('DurasiJam', nilai)}
                galat={galat.DurasiJam}
                required
            />
            <BidangPilihan
                label="Cakupan"
                nilai={formulir.data.Cakupan}
                opsi={opsiCakupan}
                saatBerubah={(nilai) => formulir.setData('Cakupan', nilai)}
                galat={galat.Cakupan}
                required
            />
        </KerangkaForm>
    );
}

function FormTutupDarurat({
    uuidTenant,
    uuidAkses,
    saatSelesai,
}: {
    uuidTenant: string;
    uuidAkses: string;
    saatSelesai: () => void;
}) {
    const formulir = useForm({});
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuidTenant}/akses-dukungan/${uuidAkses}/cabut`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <KerangkaForm
            judul="Tutup akses darurat"
            keterangan={<p>Sesi petugas yang sedang berjalan berakhir seketika.</p>}
            labelKirim="Tutup akses darurat"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <p className="text-isi text-teks-sekunder">Tutup akses darurat ini sekarang?</p>
        </KerangkaForm>
    );
}
