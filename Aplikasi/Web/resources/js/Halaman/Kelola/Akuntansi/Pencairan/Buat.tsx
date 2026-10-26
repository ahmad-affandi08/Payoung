import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import {
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalValid,
    JumlahkanDesimal,
    KalikanDesimal,
    KurangiDesimal,
    SkalaUang,
} from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatPencairan } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/pencairan';

/**
 * Formulir pencairan dana non-tunai (F-08, BR-08.4, J-08.1).
 *
 * Daftar pembayaran yang bisa dipilih **selalu datang dari server** (metode + outlet + batas tanggal), karena yang boleh
 * dicairkan adalah isi akun kliring saat itu — bukan apa pun yang kebetulan masih ada di layar. Karena itu mengganti
 * metode/outlet memuat ulang halamannya alih-alih menyaring di peramban.
 *
 * Yang diisi operator cuma **jumlah yang masuk rekening**; potongan platformnya dihitung sebagai selisih, dan perkiraan
 * dari pengaturan metode ditampilkan di sebelahnya supaya selisih yang tidak wajar langsung terlihat sebelum dicatat.
 */
export default function HalamanBuatPencairan({
    OpsiMetode,
    OpsiOutlet,
    OpsiAkun,
    Terpilih,
    Pembayaran,
    HariIni,
    MaksimalBaris,
}: PropsBuatPencairan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [dipilih, AturDipilih] = useState<string[]>([]);
    const [uuidAkun, AturUuidAkun] = useState(OpsiAkun.length === 1 ? (OpsiAkun[0]?.Uuid ?? '') : '');
    const [tanggal, AturTanggal] = useState(HariIni);
    const [jumlahBersih, AturJumlahBersih] = useState('');
    const [referensi, AturReferensi] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const metode = OpsiMetode.find((m) => m.Uuid === Terpilih.Metode) ?? null;
    const baris = Pembayaran.Data;
    const terpilih = baris.filter((p) => dipilih.includes(p.Uuid));
    const kotor =
        terpilih.length === 0 ? '0.00' : BulatkanDesimal(JumlahkanDesimal(terpilih.map((p) => p.Jumlah)), SkalaUang);
    const bersihValid = CekDesimalValid(jumlahBersih) && BandingkanDesimal(jumlahBersih, '0') >= 0;
    const biaya = bersihValid ? KurangiDesimal(kotor, jumlahBersih) : null;
    // Perkiraan potongan dari pengaturan metode: persen dari nilai transaksi + biaya tetap per transaksi. Hanya
    // pembanding — yang dibukukan selalu selisih uang yang benar-benar masuk (server menghitungnya sendiri).
    const diharapkan =
        metode === null
            ? '0.00'
            : BulatkanDesimal(
                  JumlahkanDesimal([
                      KalikanDesimal(kotor, KalikanDesimal(metode.PersenBiaya, '0.01', 8), SkalaUang),
                      KalikanDesimal(metode.BiayaTetap, String(terpilih.length), SkalaUang),
                  ]),
                  SkalaUang,
              );

    /** Memuat ulang daftar pembayaran dari server saat metode, outlet, atau batas tanggal berubah. */
    const MuatUlang = (perubahan: Partial<{ metode: string; outlet: string; sampai: string }>) => {
        const isi = {
            metode: perubahan.metode ?? Terpilih.Metode,
            outlet: perubahan.outlet ?? Terpilih.Outlet,
            sampai: perubahan.sampai ?? Terpilih.Sampai,
        };
        AturDipilih([]);
        router.get(`${alamat}/buat`, isi, { preserveState: false, replace: true });
    };

    const Alihkan = (uuid: string) =>
        AturDipilih((lama) => (lama.includes(uuid) ? lama.filter((u) => u !== uuid) : [...lama, uuid]));
    const PilihSemua = () => AturDipilih(baris.map((p) => p.Uuid));

    const siap = terpilih.length > 0 && bersihValid && uuidAkun !== '' && Terpilih.Outlet !== '';

    const Kirim = () =>
        router.post(
            alamat,
            {
                UuidMetodePembayaran: Terpilih.Metode,
                UuidOutlet: Terpilih.Outlet,
                UuidAkunTujuan: uuidAkun,
                Tanggal: tanggal,
                JumlahBersih: jumlahBersih,
                Referensi: referensi === '' ? null : referensi,
                Catatan: catatan === '' ? null : catatan,
                UuidPembayaran: terpilih.map((p) => p.Uuid),
            },
            { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakAplikasi judul="Catat pencairan dana">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Cocokkan setoran yang masuk ke rekening dengan pembayaran non-tunai yang menunggu pencairan. Selisihnya
                dibukukan sebagai potongan platform.
            </p>
            <DaftarGalatServer galat={props.errors} />

            <Panel judul="Pilih sumbernya">
                <div className="grid gap-4 sm:grid-cols-3">
                    <BidangOutlet
                        nilai={Terpilih.Outlet}
                        opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                        saatBerubah={(nilai) => MuatUlang({ outlet: nilai })}
                        galat={props.errors.UuidOutlet}
                    />
                    <BidangPilihan
                        label="Metode pembayaran"
                        nilai={Terpilih.Metode}
                        opsi={OpsiMetode.map((m) => ({ Nilai: m.Uuid, Label: m.Nama }))}
                        saatBerubah={(nilai) => MuatUlang({ metode: nilai })}
                        galat={props.errors.UuidMetodePembayaran}
                        kosong="Pilih metode"
                        required
                    />
                    <PemilihTanggal
                        label="Transaksi sampai"
                        nilai={Terpilih.Sampai}
                        saatBerubah={(nilai) => MuatUlang({ sampai: nilai })}
                        keterangan="Batasi daftar sampai tanggal transaksi tertentu. Boleh dikosongkan."
                    />
                </div>
            </Panel>

            <Panel judul="Pembayaran yang dicairkan">
                {Terpilih.Metode === '' || Terpilih.Outlet === '' ? (
                    <p className="text-isi text-teks-sekunder">
                        Pilih metode dan outletnya dulu. Daftar pembayaran yang menunggu pencairan akan tampil otomatis.
                    </p>
                ) : baris.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Tidak ada pembayaran yang menunggu pencairan untuk pilihan ini. Semuanya sudah dicairkan.
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        {Pembayaran.Terpotong ? (
                            <Pemberitahuan jenis="peringatan" judul="Daftarnya dipotong">
                                Hanya {MaksimalBaris} pembayaran terlama yang ditampilkan. Cairkan yang ini dulu, lalu
                                buka halaman ini kembali untuk sisanya.
                            </Pemberitahuan>
                        ) : null}
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <Button type="button" variant="outline" className="h-8" onClick={PilihSemua}>
                                Pilih semua ({baris.length})
                            </Button>
                            <p className="text-isi text-teks-sekunder">
                                Menunggu pencairan:{' '}
                                <span className="font-semibold tabular-nums text-teks-utama">
                                    {FormatRupiah(Pembayaran.Total)}
                                </span>
                            </p>
                        </div>
                        <ul className="flex flex-col gap-2">
                            {baris.map((p) => (
                                <li
                                    key={p.Uuid}
                                    className="flex items-start gap-3 rounded-panel border border-garis p-3"
                                >
                                    <Checkbox
                                        id={`bayar-${p.Uuid}`}
                                        checked={dipilih.includes(p.Uuid)}
                                        onCheckedChange={() => Alihkan(p.Uuid)}
                                        aria-label={`Pilih pembayaran ${p.NomorPenjualan}`}
                                    />
                                    <label htmlFor={`bayar-${p.Uuid}`} className="flex min-w-0 flex-col gap-0.5">
                                        <span className="font-mono font-semibold break-all text-teks-utama">
                                            {p.NomorPenjualan}
                                        </span>
                                        <span className="text-keterangan text-teks-sekunder">
                                            {FormatTanggal(p.TanggalPenjualan)} | {FormatRupiah(p.Jumlah)}
                                            {p.StatusPenjualan === 'Lunas' ? '' : ` | ${p.LabelStatusPenjualan}`}
                                            {p.RefEksternal ? ` | ${p.RefEksternal}` : ''}
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Panel>

            <Panel judul="Setoran yang masuk">
                <div className="grid gap-4 sm:grid-cols-2">
                    <BidangPilihan
                        label="Akun penerima"
                        nilai={uuidAkun}
                        opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} | ${a.Nama}` }))}
                        saatBerubah={AturUuidAkun}
                        galat={props.errors.UuidAkunTujuan}
                        kosong="Pilih akun kas/bank"
                        required
                    />
                    <PemilihTanggal
                        label="Tanggal masuk rekening"
                        nilai={tanggal}
                        saatBerubah={AturTanggal}
                        galat={props.errors.Tanggal}
                        required
                    />
                    <BidangUang
                        label="Jumlah yang masuk rekening"
                        nilai={jumlahBersih}
                        saatBerubah={AturJumlahBersih}
                        keterangan="Angka di mutasi rekening, bukan nilai transaksinya."
                        galat={props.errors.JumlahBersih}
                        required
                    />
                    <BidangTeks
                        label="Referensi setoran"
                        nilai={referensi}
                        saatBerubah={AturReferensi}
                        keterangan="Nomor mutasi atau ID settlement dari platform. Boleh dikosongkan."
                        galat={props.errors.Referensi}
                        maxLength={100}
                        kode
                    />
                </div>
                <BidangTeksPanjang
                    label="Catatan"
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    galat={props.errors.Catatan}
                    maksimal={500}
                />

                <dl className="mt-3 ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">Nilai transaksi terpilih ({terpilih.length})</dt>
                        <dd className="text-right tabular-nums">{FormatRupiah(kotor)}</dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">Masuk rekening</dt>
                        <dd className="text-right tabular-nums">{FormatRupiah(bersihValid ? jumlahBersih : '0')}</dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3 border-t border-garis pt-1">
                        <dt className="font-semibold text-teks-utama">
                            {biaya !== null && BandingkanDesimal(biaya, '0') < 0
                                ? 'Kelebihan setor'
                                : 'Potongan platform'}
                        </dt>
                        <dd className="text-subjudul text-right font-semibold tabular-nums">
                            {FormatRupiah(biaya === null ? '0' : biaya.replace('-', ''))}
                        </dd>
                    </div>
                    {metode !== null ? (
                        <div className="flex items-baseline justify-between gap-3">
                            <dt className="text-keterangan text-teks-sekunder">
                                Perkiraan dari pengaturan {metode.Nama} ({metode.PersenBiaya}% +{' '}
                                {FormatRupiah(metode.BiayaTetap)}/transaksi)
                            </dt>
                            <dd className="text-keterangan text-right tabular-nums text-teks-sekunder">
                                {FormatRupiah(diharapkan)}
                            </dd>
                        </div>
                    ) : null}
                    {biaya !== null && BandingkanDesimal(biaya, '0') < 0 ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Setorannya lebih besar daripada nilai transaksinya. Kelebihannya dibukukan sebagai
                            Pendapatan Lain supaya terlihat dan bisa ditanyakan, bukan disembunyikan sebagai biaya
                            negatif.
                        </p>
                    ) : null}
                </dl>
            </Panel>

            <BilahAksiForm>
                <Button asChild variant="outline" type="button">
                    <Link href={alamat}>Batal</Link>
                </Button>
                <Tombol type="button" onClick={Kirim} memproses={memproses} disabled={!siap}>
                    Catat pencairan
                </Tombol>
            </BilahAksiForm>
        </TataLetakAplikasi>
    );
}
