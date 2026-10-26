import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PermintaanPenghapusanData } from '@/Tipe/TenantPengelola';

type PropsPanel = { uuidTenant: string; slug: string; permintaan: PermintaanPenghapusanData[]; boleh: boolean };

const jenisStatus = {
    Menunggu: 'peringatan',
    Dibatalkan: 'netral',
    Ditolak: 'bahaya',
    Dilaksanakan: 'sukses',
} as const;

/**
 * UU PDP, P-07: permintaan penghapusan data dan penutupan akun tenant. Super Admin melaksanakan setelah masa tunggu 14 hari
 * (tombol baru aktif saat masa tunggu selesai) atau menolak dengan alasan. Pelaksanaan menghapus data pribadi dan
 * menutup akun; tampilan hanya memuat angka ringkasan, tidak ada data pribadi.
 */
export default function PanelPenghapusanData({ uuidTenant, slug, permintaan, boleh }: PropsPanel) {
    const [aksi, AturAksi] = useState<{ jenis: 'laksanakan' | 'tolak'; uuid: string } | null>(null);

    if (permintaan.length === 0) {
        return null;
    }

    return (
        <Panel judul="Penghapusan data (UU PDP)" tingkat="h2">
            <ul className="grid gap-3" aria-label="Permintaan penghapusan data">
                {permintaan.map((baris) => (
                    <li key={baris.Uuid} className="grid gap-2 rounded-panel border border-garis p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <LabelStatus jenis={jenisStatus[baris.Status]} teks={baris.Status} />
                            <span className="text-keterangan text-teks-sekunder">
                                Diajukan {FormatTanggalWaktu(baris.DiajukanPada)} | paling cepat{' '}
                                {FormatTanggalWaktu(baris.BerlakuPada)}
                            </span>
                        </div>
                        {baris.Alasan ? (
                            <p className="text-isi text-teks-sekunder">Alasan Owner: {baris.Alasan}</p>
                        ) : null}
                        {baris.CatatanPemutus ? (
                            <p className="text-isi text-teks-sekunder">Catatan pemutus: {baris.CatatanPemutus}</p>
                        ) : null}
                        {baris.Status === 'Dilaksanakan' ? (
                            <p className="text-keterangan text-teks-sekunder">
                                Dilaksanakan {FormatTanggalWaktu(baris.DilaksanakanPada)} | {baris.TabelDianonimkan}{' '}
                                tabel dianonimkan, {baris.TabelDihapus} tabel dihapus, {baris.PenggunaDianonimkan} akun
                                dianonimkan, {baris.BerkasDihapus} berkas dihapus
                                {baris.BerkasGagal > 0 ? `, ${baris.BerkasGagal} berkas gagal dihapus` : ''} | bukti{' '}
                                {baris.BuktiDikirimPada ? 'terkirim' : 'belum terkirim'}
                                {baris.BerkasPembukuanDihapusPada
                                    ? ` | lampiran pembukuan dihapus ${FormatTanggalWaktu(baris.BerkasPembukuanDihapusPada)}`
                                    : ''}
                            </p>
                        ) : null}
                        {baris.Status === 'Menunggu' ? (
                            <>
                                {baris.MasaTungguSelesai ? null : (
                                    <Pemberitahuan jenis="info">
                                        Masa tunggu belum berakhir. Owner masih bisa membatalkan. Pelaksanaan baru
                                        dibuka setelah {FormatTanggalWaktu(baris.BerlakuPada)}.
                                    </Pemberitahuan>
                                )}
                                {boleh ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Tombol
                                            varian="bahaya"
                                            disabled={!baris.MasaTungguSelesai}
                                            onClick={() => AturAksi({ jenis: 'laksanakan', uuid: baris.Uuid })}
                                        >
                                            Laksanakan penghapusan
                                        </Tombol>
                                        <Tombol
                                            varian="sekunder"
                                            onClick={() => AturAksi({ jenis: 'tolak', uuid: baris.Uuid })}
                                        >
                                            Tolak permintaan
                                        </Tombol>
                                    </div>
                                ) : null}
                            </>
                        ) : null}
                    </li>
                ))}
            </ul>
            {aksi?.jenis === 'laksanakan' ? (
                <FormLaksanakan
                    uuidTenant={uuidTenant}
                    uuidPermintaan={aksi.uuid}
                    slug={slug}
                    saatSelesai={() => AturAksi(null)}
                />
            ) : null}
            {aksi?.jenis === 'tolak' ? (
                <FormTolak uuidTenant={uuidTenant} uuidPermintaan={aksi.uuid} saatSelesai={() => AturAksi(null)} />
            ) : null}
        </Panel>
    );
}

type PropsForm = { uuidTenant: string; uuidPermintaan: string; saatSelesai: () => void };

function FormLaksanakan({ uuidTenant, uuidPermintaan, slug, saatSelesai }: PropsForm & { slug: string }) {
    const formulir = useForm({ Catatan: '', KonfirmasiSlug: '' });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuidTenant}/penghapusan-data/${uuidPermintaan}/laksanakan`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <KerangkaForm
            judul="Laksanakan penghapusan data"
            keterangan={
                <p>
                    Data pribadi tenant ini dihapus dan akunnya ditutup. Transaksi dan pembukuan tetap disimpan dengan
                    identitas dianonimkan. <strong>Tidak bisa dibalik.</strong> Pastikan identitas Owner sudah
                    diverifikasi dan tidak ada sengketa atau perkara hukum yang mewajibkan data dipertahankan.
                </p>
            }
            labelKirim="Laksanakan penghapusan"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(galat)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Catatan verifikasi identitas Owner"
                nilai={formulir.data.Catatan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={galat.Catatan}
                required
            />
            <BidangTeks
                label={`Ketik slug tenant (${slug}) untuk memastikan`}
                nilai={formulir.data.KonfirmasiSlug}
                saatBerubah={(nilai) => formulir.setData('KonfirmasiSlug', nilai)}
                galat={galat.KonfirmasiSlug}
                autoComplete="off"
                kode
                required
            />
        </KerangkaForm>
    );
}

function FormTolak({ uuidTenant, uuidPermintaan, saatSelesai }: PropsForm) {
    const formulir = useForm({ Alasan: '' });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuidTenant}/penghapusan-data/${uuidPermintaan}/tolak`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <KerangkaForm
            judul="Tolak permintaan penghapusan data"
            keterangan={
                <p>Alasan dikirim ke Owner lewat email supaya jelas langkah berikutnya. Akun tetap berjalan.</p>
            }
            labelKirim="Tolak permintaan"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(galat)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan penolakan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={galat.Alasan}
                required
            />
        </KerangkaForm>
    );
}
