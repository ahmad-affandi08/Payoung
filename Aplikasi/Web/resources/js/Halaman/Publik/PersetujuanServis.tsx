import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import KerangkaPelanggan from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JudulUnit, RincianUnit } from '@/Pustaka/UnitServis';
import { JenisStatusPerintahKerja, type PropsPersetujuanServis } from '@/Tipe/Bengkel';

/**
 * Persetujuan estimasi servis (§9.10) untuk pelanggan tanpa akun, dibuka dari tautan WhatsApp bengkel. Pelanggan
 * mencentang pekerjaan & sparepart yang disetujui (boleh sebagian), lalu menyetujui atau menolak semuanya. Angka
 * dari server; halaman ini tidak menghitung harga. Setelah diputuskan, halaman hanya menampilkan hasilnya.
 */
export default function HalamanPersetujuanServis({
    NamaToko,
    UrlLogo,
    AlamatDasar,
    PerintahKerja: pk,
}: PropsPersetujuanServis) {
    const { props } = usePage<{ Kilat?: string | null; errors: Record<string, string> }>();
    const [dipilih, AturDipilih] = useState<string[]>(pk.Baris.map((b) => b.Uuid));
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const galat = props.errors.Umum ?? props.errors.Baris ?? props.errors['Baris.0'];
    const pilihan = { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };
    const Setujui = () =>
        router.post(`${AlamatDasar}/setujui`, { Baris: dipilih, Catatan: catatan === '' ? null : catatan }, pilihan);
    const Tolak = () => router.post(`${AlamatDasar}/tolak`, { Catatan: catatan === '' ? null : catatan }, pilihan);

    return (
        <KerangkaPelanggan toko={NamaToko} urlLogo={UrlLogo}>
            <Head title={`Persetujuan servis ${pk.Nomor}`} />
            <header className="flex flex-col gap-1 border-b border-garis pb-4">
                <JudulHalaman>Estimasi servis {pk.Kendaraan === null ? '' : JudulUnit(pk.Kendaraan)}</JudulHalaman>
                <p className="text-keterangan text-teks-sekunder">
                    <span className="font-mono">{pk.Nomor}</span> |{' '}
                    {pk.Kendaraan === null ? '' : RincianUnit(pk.Kendaraan)} | a.n. {pk.NamaPelanggan}
                </p>
                <div>
                    <LabelStatus jenis={JenisStatusPerintahKerja(pk.Status)} teks={pk.LabelStatus} />
                </div>
            </header>

            {props.Kilat ? <Pemberitahuan jenis="sukses">{props.Kilat}</Pemberitahuan> : null}
            {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}

            <section
                aria-label="Keluhan & diagnosis"
                className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-4"
            >
                <h2 className="text-subjudul font-semibold text-teks-utama">Keluhan</h2>
                <p className="whitespace-pre-line">{pk.Keluhan}</p>
                {pk.Diagnosis ? (
                    <>
                        <h2 className="text-subjudul font-semibold text-teks-utama">Hasil pemeriksaan</h2>
                        <p className="whitespace-pre-line">{pk.Diagnosis}</p>
                    </>
                ) : null}
                {pk.EstimasiSelesaiPada ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Perkiraan selesai {FormatTanggalWaktu(pk.EstimasiSelesaiPada)}
                    </p>
                ) : null}
            </section>

            <section aria-label="Rincian estimasi" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Rincian pekerjaan & sparepart</h2>
                <ul className="flex flex-col divide-y divide-garis rounded-panel border border-garis bg-permukaan">
                    {pk.Baris.map((b) => (
                        <li key={b.Uuid} className="flex flex-col gap-1 p-3">
                            <div className="flex items-start justify-between gap-3">
                                {pk.BolehDiputuskan ? (
                                    <KotakCentang
                                        label={`${b.Jenis}: ${b.NamaProduk}`}
                                        nilai={dipilih.includes(b.Uuid)}
                                        saatBerubah={(nilai) =>
                                            AturDipilih((lama) =>
                                                nilai ? [...lama, b.Uuid] : lama.filter((u) => u !== b.Uuid),
                                            )
                                        }
                                    />
                                ) : (
                                    <span className="break-words">
                                        {b.Jenis}: {b.NamaProduk}
                                    </span>
                                )}
                                <span className="shrink-0 text-right tabular-nums">{FormatRupiah(b.Subtotal)}</span>
                            </div>
                            <span className="text-keterangan text-teks-sekunder">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)} × {FormatRupiah(b.HargaSatuan)}
                                {b.Diskon !== '0.00' ? ` | diskon ${FormatRupiah(b.Diskon)}` : ''}
                                {!pk.BolehDiputuskan ? (b.Disetujui ? ' | disetujui' : ' | tidak disetujui') : ''}
                            </span>
                        </li>
                    ))}
                </ul>
                <dl className="ml-auto flex w-full max-w-sm flex-col gap-1">
                    {[
                        ['Subtotal', pk.Subtotal],
                        ['Diskon', pk.Diskon],
                        ['Pajak', pk.Pajak],
                    ].map(([label, nilai]) => (
                        <div key={label} className="flex justify-between gap-3">
                            <dt className="text-teks-sekunder">{label}</dt>
                            <dd className="tabular-nums">{FormatRupiah(nilai ?? '0')}</dd>
                        </div>
                    ))}
                    <div className="flex justify-between gap-3 border-t border-garis pt-1 font-semibold">
                        <dt>Perkiraan total semua</dt>
                        <dd className="tabular-nums">{FormatRupiah(pk.Total)}</dd>
                    </div>
                    {!pk.BolehDiputuskan && pk.TotalDisetujui !== '0.00' ? (
                        <div className="flex justify-between gap-3 font-semibold">
                            <dt>Total yang Anda setujui</dt>
                            <dd className="tabular-nums">{FormatRupiah(pk.TotalDisetujui)}</dd>
                        </div>
                    ) : null}
                </dl>
                <p className="text-keterangan text-teks-sekunder">
                    Angka ini perkiraan dari bengkel. Tagihan akhir dibayar di kasir sesuai pekerjaan yang disetujui.
                </p>
            </section>

            {pk.BolehDiputuskan ? (
                <section aria-label="Keputusan" className="flex flex-col gap-3">
                    <BidangTeksPanjang
                        label="Pesan untuk bengkel (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        maksimal={255}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Tombol memproses={memproses} disabled={dipilih.length === 0} onClick={Setujui}>
                            {dipilih.length === pk.Baris.length
                                ? 'Setujui semua'
                                : `Setujui ${String(dipilih.length)} pekerjaan`}
                        </Tombol>
                        <Tombol varian="bahaya" memproses={memproses} onClick={Tolak}>
                            Tolak estimasi
                        </Tombol>
                    </div>
                </section>
            ) : (
                <Pemberitahuan jenis="info" judul="Estimasi sudah diputuskan">
                    {pk.DiputuskanPada ? `Diputuskan ${FormatTanggalWaktu(pk.DiputuskanPada)}. ` : ''}Hubungi {NamaToko}{' '}
                    bila ada perubahan.
                </Pemberitahuan>
            )}
        </KerangkaPelanggan>
    );
}
