import { Head } from '@inertiajs/react';

import Tombol from '@/Komponen/Formulir/Tombol';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisGajiKaryawan, PropsSlipGaji } from '@/Tipe/Karyawan';

/** Baris rincian: label kiri, Rupiah tabular rata kanan; potongan ditulis dengan tanda minus. */
function BarisNilai({ label, nilai, kurang = false }: { label: string; nilai: string; kurang?: boolean }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-teks-sekunder">{label}</dt>
            <dd className="tabular-nums">
                {kurang ? '− ' : ''}
                {FormatRupiah(nilai)}
            </dd>
        </div>
    );
}

/** Nilai bukan nol (`0.00` dilewati supaya slip tidak penuh baris kosong). */
function CekAda(nilai: string): boolean {
    return !/^0+(\.0+)?$/.test(nilai);
}

function Slip({ baris, props }: { baris: BarisGajiKaryawan; props: PropsSlipGaji }) {
    const { Rekap, Usaha } = props;
    const draf = Rekap.Status === 'Draf';

    return (
        <article
            aria-label={`Slip gaji ${baris.Nama}`}
            className="flex flex-col gap-5 border border-garis p-6 break-after-page print:border-0 print:p-0"
        >
            {draf ? (
                <p className="border-2 border-peringatan p-2 text-center font-bold text-peringatan uppercase">
                    Draf, belum dibayar
                </p>
            ) : null}
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-garis pb-4">
                <div>
                    <p className="text-subjudul font-semibold">{Usaha.Nama ?? ''}</p>
                    {Usaha.Npwp ? <p className="text-teks-sekunder">NPWP {Usaha.Npwp}</p> : null}
                </div>
                <div className="text-right">
                    <p className="text-subjudul font-semibold">Slip Gaji</p>
                    <p>Periode {Rekap.LabelPeriode}</p>
                    {Rekap.TanggalBayar ? <p>Dibayar {FormatTanggal(Rekap.TanggalBayar)}</p> : null}
                </div>
            </header>

            <div>
                <p className="font-semibold">{baris.Nama}</p>
                {baris.Jabatan ? <p className="text-teks-sekunder">{baris.Jabatan}</p> : null}
            </div>

            <div className="grid gap-6 sm:grid-cols-2 print:grid-cols-2">
                <section className="flex flex-col gap-2">
                    <h2 className="text-label font-semibold text-teks-sekunder">Pendapatan</h2>
                    <dl className="flex flex-col gap-1">
                        <BarisNilai
                            label={
                                baris.UpahHarian !== null && baris.HariMasuk !== null
                                    ? `Upah harian (${String(baris.HariMasuk)} hari × ${FormatRupiah(baris.UpahHarian)})`
                                    : 'Gaji pokok'
                            }
                            nilai={baris.GajiPokok}
                        />
                        {CekAda(baris.Komisi) ? <BarisNilai label="Komisi" nilai={baris.Komisi} /> : null}
                        {CekAda(baris.Tambahan) ? (
                            <BarisNilai label="Tambahan (tunjangan)" nilai={baris.Tambahan} />
                        ) : null}
                        {CekAda(baris.Lembur) ? (
                            <BarisNilai label={`Lembur (${String(baris.LemburMenit)} menit)`} nilai={baris.Lembur} />
                        ) : null}
                        <div className="flex justify-between gap-3 border-t border-garis pt-1 font-semibold">
                            <dt>Gaji kotor</dt>
                            <dd className="tabular-nums">{FormatRupiah(baris.Kotor)}</dd>
                        </div>
                    </dl>
                </section>
                <section className="flex flex-col gap-2">
                    <h2 className="text-label font-semibold text-teks-sekunder">Potongan</h2>
                    <dl className="flex flex-col gap-1">
                        <BarisNilai label="Potongan kasbon" nilai={baris.PotonganKasbon} kurang />
                        {CekAda(baris.PotonganTerlambat) ? (
                            <BarisNilai
                                label={`Terlambat (${String(baris.TerlambatMenit)} menit)`}
                                nilai={baris.PotonganTerlambat}
                                kurang
                            />
                        ) : null}
                        {CekAda(baris.PotonganTidakMasuk) ? (
                            <BarisNilai
                                label={`Tidak masuk (${String(baris.HariTidakMasuk)} hari)`}
                                nilai={baris.PotonganTidakMasuk}
                                kurang
                            />
                        ) : null}
                        {CekAda(baris.PotonganLain) ? (
                            <BarisNilai label="Potongan lain" nilai={baris.PotonganLain} kurang />
                        ) : null}
                    </dl>
                </section>
            </div>

            <div className="flex justify-between gap-3 border-y-2 border-teks-utama py-2 text-subjudul font-bold">
                <span>Gaji bersih diterima</span>
                <span className="tabular-nums">{FormatRupiah(baris.Bersih)}</span>
            </div>

            {baris.Catatan ? <p className="text-teks-sekunder">Catatan: {baris.Catatan}</p> : null}
            {CekAda(baris.SisaKasbon) ? (
                <p className="text-teks-sekunder">Sisa kasbon saat slip dicetak: {FormatRupiah(baris.SisaKasbon)}</p>
            ) : null}

            <footer className="mt-6 grid grid-cols-2 gap-8 text-center">
                <div>
                    <p>Dibayar oleh</p>
                    <p className="mt-14 border-t border-garis pt-1" />
                </div>
                <div>
                    <p>Diterima oleh</p>
                    <p className="mt-14 border-t border-garis pt-1">{baris.Nama}</p>
                </div>
            </footer>
        </article>
    );
}

/**
 * Slip gaji per karyawan (F-18 bagian 3, v3.35) dari rekap gaji bulanan: satu slip per halaman kertas, untuk dicetak
 * atau disimpan PDF lalu dibagikan ke karyawan. Tanpa kerangka aplikasi supaya yang keluar di kertas hanya slipnya.
 * Slip draf diberi tanda karena angkanya masih bisa berubah.
 */
export default function HalamanSlipGaji(props: PropsSlipGaji) {
    const satu = props.Baris.length === 1 ? props.Baris[0] : undefined;

    return (
        <main className="mx-auto flex max-w-3xl flex-col gap-6 bg-permukaan p-6 text-isi text-teks-utama print:p-0">
            <Head title={satu ? `Slip gaji ${satu.Nama} ${props.Rekap.Periode}` : `Slip gaji ${props.Rekap.Periode}`} />
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                <JudulHalaman skala="ringkas">
                    {satu ? `Slip gaji ${satu.Nama}` : `Slip gaji ${String(props.Baris.length)} karyawan`}
                </JudulHalaman>
                <div className="ml-auto">
                    <Tombol onClick={() => window.print()}>Cetak atau simpan PDF</Tombol>
                </div>
            </div>
            {props.Baris.map((b) => (
                <Slip key={b.UuidKaryawan} baris={b} props={props} />
            ))}
        </main>
    );
}
