import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { DialogAlasanGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihPembeliFakturPajak from '@/Komponen/Penjualan/PemilihPembeliFakturPajak';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { FakturPajakPenjualan } from '@/Tipe/Penjualan';

function Alamat(uuidPenjualan: string): string {
    return `/kelola/penjualan/${uuidPenjualan}/faktur-pajak`;
}

function DialogTerbitkan({ uuidPenjualan, saatTutup }: { uuidPenjualan: string; saatTutup: () => void }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [uuid, AturUuid] = useState('');
    const [nama, AturNama] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            Alamat(uuidPenjualan),
            { UuidPelanggan: uuid, Catatan: catatan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    return (
        <DialogFormulir
            judul="Buat Faktur Pajak"
            keterangan="Untuk pembeli yang meminta Faktur Pajak atas transaksi ini. Pembeli harus punya NPWP atau NIK di data pelanggannya."
            galatUmum={props.errors.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Buat Faktur Pajak">
                <PemilihPembeliFakturPajak
                    uuidTerpilih={uuid}
                    namaTerpilih={nama}
                    saatPilih={(u, n) => {
                        AturUuid(u);
                        AturNama(n);
                    }}
                    galat={props.errors.UuidPelanggan}
                />
                <BidangTeksPanjang
                    label="Catatan"
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    galat={props.errors.Catatan}
                    maksimal={500}
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses} disabled={uuid === ''}>
                        Buat Faktur Pajak
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/**
 * Faktur Pajak per transaksi kasir (TAX-07, D-147) di detail penjualan: buat atas permintaan pembeli ber-NPWP/NIK, isi
 * nomor dari Coretax setelah faktur diunggah, atau batalkan. Fakturnya ikut ekspor XML di Laporan › Pajak; angka tidak
 * disalin ke sini karena dihitung dari penjualannya.
 */
export default function PanelFakturPajak({
    uuidPenjualan,
    faktur,
    bolehBuat,
    bolehKelola,
}: {
    uuidPenjualan: string;
    faktur: FakturPajakPenjualan | null;
    /** Penjualan masih berlaku (Lunas) dan memuat pajak. */
    bolehBuat: boolean;
    bolehKelola: boolean;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [dialog, AturDialog] = useState<'terbitkan' | 'batalkan' | null>(null);
    const [nomor, AturNomor] = useState(faktur?.NomorFakturPajak ?? '');
    const [memproses, AturMemproses] = useState(false);
    const aktif = faktur?.Status === 'Diposting';

    const SimpanNomor = () =>
        router.put(
            `${Alamat(uuidPenjualan)}/${faktur?.Uuid ?? ''}/nomor`,
            { NomorFakturPajak: nomor },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <Panel
            judul="Faktur Pajak"
            keterangan="Atas permintaan pembeli ber-NPWP/NIK. Struk tetap sah bagi pembeli lain."
            aksi={
                bolehKelola && !aktif && bolehBuat ? (
                    <Tombol varian="sekunder" onClick={() => AturDialog('terbitkan')}>
                        {faktur === null ? 'Buat Faktur Pajak' : 'Buat faktur baru'}
                    </Tombol>
                ) : undefined
            }
        >
            {faktur === null ? (
                <p className="text-isi text-teks-sekunder">
                    Belum ada Faktur Pajak untuk penjualan ini.
                    {bolehKelola ? '' : ' Mintalah pengguna ber-izin akuntansi membuatkannya.'}
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <LabelStatus
                            jenis={aktif ? 'sukses' : 'netral'}
                            teks={aktif ? 'Faktur Pajak aktif' : 'Dibatalkan'}
                        />
                        <span className="text-isi break-words text-teks-utama">{faktur.NamaPembeli}</span>
                    </div>
                    {faktur.Catatan !== null ? (
                        <p className="text-isi break-words text-teks-sekunder">{faktur.Catatan}</p>
                    ) : null}
                    {faktur.DibuatPada !== null ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Dibuat {FormatTanggalWaktu(faktur.DibuatPada)}
                        </p>
                    ) : null}
                    {!aktif ? (
                        <Pemberitahuan jenis="info" judul="Faktur Pajak dibatalkan">
                            {faktur.AlasanBatal ?? 'Tanpa alasan tercatat.'}
                            {faktur.NomorFakturPajak !== null
                                ? ` Nomor ${faktur.NomorFakturPajak} yang sudah disetujui di Coretax harus dibatalkan atau diganti di Coretax juga.`
                                : ''}
                        </Pemberitahuan>
                    ) : bolehKelola ? (
                        <>
                            <div className="flex flex-col gap-3 sm:max-w-md">
                                <BidangTeks
                                    label="Nomor Faktur Pajak"
                                    nilai={nomor}
                                    saatBerubah={AturNomor}
                                    galat={props.errors.NomorFakturPajak}
                                    maxLength={30}
                                    kode
                                />
                                <Tombol onClick={SimpanNomor} memproses={memproses} varian="sekunder">
                                    Simpan nomor
                                </Tombol>
                            </div>
                            <div className="flex">
                                <Tombol varian="bahaya" onClick={() => AturDialog('batalkan')}>
                                    Batalkan Faktur Pajak
                                </Tombol>
                            </div>
                        </>
                    ) : (
                        <p className="font-mono text-isi break-all text-teks-utama">{faktur.NomorFakturPajak ?? '—'}</p>
                    )}
                </div>
            )}

            {dialog === 'terbitkan' ? (
                <DialogTerbitkan uuidPenjualan={uuidPenjualan} saatTutup={() => AturDialog(null)} />
            ) : null}
            {dialog === 'batalkan' && faktur ? (
                <DialogAlasanGrosir
                    judul="Batalkan Faktur Pajak"
                    keterangan="Penjualannya tidak berubah. Faktur yang sudah bernomor Coretax juga harus dibatalkan di Coretax."
                    labelAksi="Batalkan Faktur Pajak"
                    alamat={`${Alamat(uuidPenjualan)}/${faktur.Uuid}/batalkan`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
        </Panel>
    );
}
