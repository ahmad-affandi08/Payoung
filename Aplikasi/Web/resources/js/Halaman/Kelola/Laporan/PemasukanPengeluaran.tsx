import { router } from '@inertiajs/react';
import { useState } from 'react';

import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import DialogUnduhBuku from '@/Komponen/Laporan/DialogUnduhBuku';
import GrafikUangHarian from '@/Komponen/Laporan/GrafikUangHarian';
import Panel from '@/Komponen/Kelola/Panel';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { CariPeriodeAktif, DaftarPeriode, FrasaPembanding, HitungPeriode } from '@/Pustaka/PeriodeLaporan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisUang, KelompokUang, PropsPemasukanPengeluaran } from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/pemasukan-pengeluaran';

type ArahAngka = 'masuk' | 'keluar' | 'selisih';

/** "naik 4,2% dari bulan lalu" dan warna teksnya. Uang keluar yang naik bukan kabar baik, jadi nadanya berbeda. */
function Perubahan(persen: string | null, arah: ArahAngka, frasa: string): { teks: string; kelas: string } {
    if (persen === null) {
        return { teks: 'Belum ada pembanding', kelas: 'text-teks-sekunder' };
    }

    const turun = persen.startsWith('-');
    const angka = FormatPersen(turun ? persen.slice(1) : persen);

    if (/^-?0+(\.0+)?$/.test(persen)) {
        return { teks: `sama dengan ${frasa.replace('dari ', '')}`, kelas: 'text-teks-sekunder' };
    }

    const baik = arah === 'keluar' ? turun : !turun;

    return {
        teks: `${turun ? 'turun' : 'naik'} ${angka}% ${frasa}`,
        kelas: baik ? 'text-sukses' : 'text-peringatan',
    };
}

function KartuAngka({
    label,
    nilai,
    persen,
    arah,
    frasa,
    utama = false,
}: {
    label: string;
    nilai: string;
    persen: string | null;
    arah: ArahAngka;
    frasa: string;
    utama?: boolean;
}) {
    const perubahan = Perubahan(persen, arah, frasa);

    return (
        <div
            className={cn(
                'flex flex-col gap-1 rounded-panel border p-4',
                utama ? 'border-brand bg-brand text-brand-teks' : 'border-garis bg-permukaan text-teks-utama',
            )}
        >
            <dt className={cn('text-isi', utama ? 'text-brand-teks' : 'text-teks-sekunder')}>{label}</dt>
            <dd className="text-judul font-semibold tabular-nums">{FormatRupiah(nilai)}</dd>
            <dd className={cn('text-keterangan', utama ? 'text-brand-teks' : perubahan.kelas)}>{perubahan.teks}</dd>
        </div>
    );
}

function PanelKelompok({
    judul,
    kosong,
    kelompok,
    rincian,
    kelasBatang,
}: {
    judul: string;
    kosong: string;
    kelompok: KelompokUang[];
    rincian: BarisUang[];
    kelasBatang: string;
}) {
    return (
        <Panel judul={judul}>
            {kelompok.length === 0 ? (
                <p className="text-isi text-teks-sekunder">{kosong}</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {kelompok.map((k) => (
                        <li key={k.Kelompok} className="flex flex-col gap-1">
                            <div className="flex items-baseline justify-between gap-3 text-isi text-teks-utama">
                                <span>{k.Kelompok}</span>
                                <span className="font-semibold tabular-nums">{FormatRupiah(k.Nilai)}</span>
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-brand-tint" aria-hidden="true">
                                <div
                                    className={cn('h-2 rounded-full', kelasBatang)}
                                    style={{ width: `${String(Math.min(100, Number(k.Porsi ?? '0')))}%` }}
                                />
                            </div>
                            <span className="text-keterangan text-teks-sekunder">
                                {k.Porsi === null ? '' : `${FormatPersen(k.Porsi)}% dari total`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {rincian.length === 0 ? null : (
                <details className="group border-t border-garis pt-3">
                    <summary className="cursor-pointer text-isi font-semibold text-brand">Lihat rincian</summary>
                    <ul className="mt-2 flex flex-col gap-1.5">
                        {rincian.map((b) => (
                            <li
                                key={`${b.Kelompok}|${b.Label}`}
                                className="flex items-baseline justify-between gap-3 text-isi text-teks-utama"
                            >
                                <span className="min-w-0 break-words">{b.Label}</span>
                                <span className="tabular-nums">{FormatRupiah(b.Nilai)}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </Panel>
    );
}

function BarisCatatan({ label, keterangan, nilai }: { label: string; keterangan: string; nilai: string }) {
    return (
        <li className="flex items-baseline justify-between gap-3 border-t border-garis pt-2 first:border-t-0 first:pt-0">
            <div className="flex min-w-0 flex-col">
                <span className="text-isi text-teks-utama">{label}</span>
                <span className="text-keterangan text-teks-sekunder">{keterangan}</span>
            </div>
            <span className="text-isi font-semibold tabular-nums text-teks-utama">{FormatRupiah(nilai)}</span>
        </li>
    );
}

/**
 * D-255 Pemasukan & Pengeluaran: uang yang benar-benar masuk dan keluar kas & bank pada satu periode, dalam bahasa
 * pemilik usaha. Belanja stok dihitung penuh sebagai uang keluar dan HPP tidak dipakai, supaya angkanya bisa
 * dicocokkan dengan saldo rekening. Pilihan cepat periode di atas, saringan rentang bebas dan ekspor di bawahnya.
 */
export default function HalamanPemasukanPengeluaran({ Saring, Laporan, OpsiOutlet }: PropsPemasukanPengeluaran) {
    const { Periode, Ringkasan } = Laporan;
    const frasa = FrasaPembanding(Periode.Pembanding);
    const aktif = CariPeriodeAktif(Saring.Dari, Saring.Sampai);
    const kosong = Laporan.Masuk.length === 0 && Laporan.Keluar.length === 0;
    const adaDiLuar = Laporan.DiLuarUsahaRincian.length > 0;
    const [bukaUnduh, AturBukaUnduh] = useState(false);
    const hariRentang = Periode.Hari;

    const Pilih = (dari: string, sampai: string) =>
        router.get(
            `${alamat}?${BuatQueryLaporan({ ...Saring, Dari: dari, Sampai: sampai })}`,
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <TataLetakAplikasi judul="Pemasukan & Pengeluaran">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Uang yang benar-benar masuk dan keluar dari kas dan bank Anda. Belanja stok dihitung sebagai uang keluar
                saat dibayar, jadi angkanya bisa dicocokkan dengan saldo rekening.
            </p>

            <div className="flex flex-wrap gap-2" role="group" aria-label="Pilihan cepat periode">
                {DaftarPeriode.map((p) => (
                    <Button
                        key={p.kunci}
                        type="button"
                        size="sm"
                        variant={aktif === p.kunci ? 'default' : 'outline'}
                        aria-pressed={aktif === p.kunci}
                        onClick={() => {
                            const rentang = HitungPeriode(p.kunci);
                            Pilih(rentang.Dari, rentang.Sampai);
                        }}
                    >
                        {p.label}
                    </Button>
                ))}
                <div className="sm:ml-auto">
                    <Tombol varian="sekunder" onClick={() => AturBukaUnduh(true)}>
                        Unduh laporan lengkap
                    </Tombol>
                </div>
            </div>
            {bukaUnduh ? (
                <DialogUnduhBuku
                    alamat={alamat}
                    saring={Saring}
                    hariRentang={hariRentang}
                    tawarkanPerOutlet={OpsiOutlet.length > 1 && Saring.Outlet === ''}
                    saatTutup={() => AturBukaUnduh(false)}
                />
            ) : null}

            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                ekspor={`${alamat}/ekspor?${BuatQueryLaporan(Saring)}`}
            />

            {Laporan.AdaAkunKas ? null : (
                <Pemberitahuan jenis="peringatan" judul="Belum ada akun kas atau bank">
                    Petakan akun kas outlet, kas brankas, atau bank di halaman pemetaan akun agar uang masuk dan keluar
                    bisa dihitung.
                </Pemberitahuan>
            )}
            {Laporan.BelumDikelompokkan === '0.00' ? null : (
                <Pemberitahuan jenis="peringatan" judul="Ada uang yang belum bisa dikelompokkan">
                    Sebesar {FormatRupiah(Laporan.BelumDikelompokkan)} berasal dari transaksi yang belum dikenali
                    jenisnya. Angkanya tetap dihitung, hanya kelompoknya belum jelas.
                </Pemberitahuan>
            )}

            <dl aria-label="Ringkasan pemasukan dan pengeluaran" className="grid gap-3 md:grid-cols-3">
                <KartuAngka
                    label="Uang masuk"
                    nilai={Ringkasan.Masuk}
                    persen={Ringkasan.PerubahanMasuk}
                    arah="masuk"
                    frasa={frasa}
                />
                <KartuAngka
                    label="Uang keluar"
                    nilai={Ringkasan.Keluar}
                    persen={Ringkasan.PerubahanKeluar}
                    arah="keluar"
                    frasa={frasa}
                />
                <KartuAngka
                    label="Selisih"
                    nilai={Ringkasan.Selisih}
                    persen={Ringkasan.PerubahanSelisih}
                    arah="selisih"
                    frasa={frasa}
                    utama
                />
            </dl>

            {kosong ? (
                <Panel judul="Belum ada uang masuk atau keluar">
                    <p className="text-isi text-teks-sekunder">
                        Pada periode ini belum ada uang yang masuk atau keluar dari kas dan bank. Coba ganti periode
                        atau pilih outlet lain.
                    </p>
                </Panel>
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    <PanelKelompok
                        judul="Uang masuk dari mana"
                        kosong="Belum ada uang masuk."
                        kelompok={Laporan.KelompokMasuk}
                        rincian={Laporan.Masuk}
                        kelasBatang="bg-brand"
                    />
                    <PanelKelompok
                        judul="Uang keluar ke mana"
                        kosong="Belum ada uang keluar."
                        kelompok={Laporan.KelompokKeluar}
                        rincian={Laporan.Keluar}
                        kelasBatang="bg-aksen"
                    />
                </div>
            )}

            <div className="grid gap-3 lg:grid-cols-2">
                <Panel
                    judul={Laporan.Grafik.Satuan === 'Hari' ? 'Per hari' : 'Per bulan'}
                    keterangan="Batang hijau uang masuk, batang oranye uang keluar."
                >
                    <GrafikUangHarian
                        titik={Laporan.Grafik.Titik}
                        satuan={Laporan.Grafik.Satuan}
                        judul={`Uang masuk dan keluar per ${Laporan.Grafik.Satuan === 'Hari' ? 'hari' : 'bulan'}`}
                    />
                </Panel>
                <Panel judul="Perlu Anda tahu" keterangan="Angka di luar hitungan di atas, per akhir periode.">
                    <ul className="flex flex-col gap-2">
                        <BarisCatatan
                            label="Hutang ke pemasok belum dibayar"
                            keterangan="Belum keluar dari kas, jadi belum masuk uang keluar"
                            nilai={Laporan.Catatan.Hutang}
                        />
                        <BarisCatatan
                            label="Piutang belum diterima"
                            keterangan="Sudah dijual, uangnya belum masuk"
                            nilai={Laporan.Catatan.Piutang}
                        />
                        <BarisCatatan
                            label="Dana QRIS dan ojol menunggu cair"
                            keterangan="Sudah dibayar pembeli, belum tiba di rekening"
                            nilai={Laporan.Catatan.MenungguCair}
                        />
                        {adaDiLuar ? (
                            <li className="flex flex-col gap-1 border-t border-garis pt-2">
                                <span className="text-isi font-semibold text-teks-utama">
                                    Bukan pendapatan atau biaya usaha
                                </span>
                                <span className="text-keterangan text-teks-sekunder">
                                    Modal, prive, pinjaman, dan beli aset tidak ikut dihitung dalam selisih.
                                </span>
                                <ul className="flex flex-col gap-1">
                                    {Laporan.DiLuarUsahaRincian.map((b) => (
                                        <li
                                            key={`${b.Kelompok}|${b.Label}`}
                                            className="flex items-baseline justify-between gap-3 text-isi text-teks-utama"
                                        >
                                            <span className="min-w-0 break-words">{b.Label}</span>
                                            <span className="tabular-nums">{FormatRupiah(b.Nilai)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ) : null}
                    </ul>
                    {Laporan.Kecocokan.Cocok ? (
                        <p className="text-isi font-semibold text-sukses">
                            Cocok dengan perubahan saldo kas dan bank ({FormatRupiah(Ringkasan.PerubahanKas)})
                        </p>
                    ) : (
                        <Pemberitahuan jenis="peringatan" judul="Belum cocok dengan saldo kas dan bank">
                            Ada selisih {FormatRupiah(Laporan.Kecocokan.Selisih)} antara hitungan laporan dan perubahan
                            saldo kas dan bank. Hubungi dukungan bila angka ini tidak hilang.
                        </Pemberitahuan>
                    )}
                </Panel>
            </div>
        </TataLetakAplikasi>
    );
}
