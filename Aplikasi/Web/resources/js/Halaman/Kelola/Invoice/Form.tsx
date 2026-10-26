import { useQuery } from '@tanstack/react-query';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihPelangganGrosir from '@/Komponen/Grosir/PemilihPelangganGrosir';
import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import IsianBarisInvoice, { BuatBarisKosongInvoice } from '@/Komponen/Invoice/IsianBarisInvoice';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import { AmbilHasilCari } from '@/Komponen/Katalog/PemilihProduk';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { HitungSubtotalInvoice } from '@/Pustaka/HitungInvoice';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianInvoice, PropsFormInvoice, UangMukaTersedia } from '@/Tipe/Invoice';

const MAKSIMAL_BARIS = 200;

let urut = 0;

function BuatKunci(): string {
    urut += 1;

    return `isian-invoice-${String(urut)}`;
}

/**
 * Form draf invoice (F-21, D-166). Menyimpan draf tidak membuat nomor, jurnal, atau piutang; semuanya baru terjadi saat
 * diterbitkan. Total, pajak, PPh, dan potongan uang muka dihitung server dari baris dan tampil di halaman invoice, jadi di
 * sini hanya ada pratinjau subtotal.
 */
export default function HalamanFormInvoice({
    Isian,
    Jenis,
    Kebiasaan,
    OpsiOutlet,
    OpsiKelompokPajak,
    UangMukaAwal,
    HariIni,
}: PropsFormInvoice) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const ubah = Isian !== null;
    const uangMuka = Jenis === 'UangMuka';
    const [uuidPelanggan, AturPelanggan] = useState(Isian?.UuidPelanggan ?? '');
    const [namaPelanggan, AturNamaPelanggan] = useState(Isian?.NamaPelanggan ?? '');
    const [uuidOutlet, AturOutlet] = useState(
        Isian?.UuidOutlet ?? (OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : ''),
    );
    const [tanggal, AturTanggal] = useState(Isian?.Tanggal ?? HariIni);
    const [termin, AturTermin] = useState(Isian === null ? '' : String(Isian.TerminHari));
    // Termin dari data pelanggan yang terisi otomatis; diganti lagi bila pelanggan lain dipilih dan belum diubah manual.
    const [terminOtomatis, AturTerminOtomatis] = useState('');
    const [perihal, AturPerihal] = useState(Isian?.Perihal ?? '');
    const [referensi, AturReferensi] = useState(Isian?.NomorReferensi ?? '');
    const [inklusif, AturInklusif] = useState(Isian?.HargaTermasukPajak ?? false);
    const [kodePph, AturKodePph] = useState(Isian?.KodePph ?? '');
    const [tarifPph, AturTarifPph] = useState(Isian?.TarifPph ?? '');
    const [catatan, AturCatatan] = useState(Isian?.Catatan ?? '');
    const [syarat, AturSyarat] = useState(Isian?.Syarat ?? Kebiasaan?.Syarat ?? '');
    const [infoBayar, AturInfoBayar] = useState(Isian?.InfoPembayaran ?? Kebiasaan?.InfoPembayaran ?? '');
    const [dipilihDp, AturDipilihDp] = useState<string[]>(Isian?.UuidUangMuka ?? []);
    const [baris, AturBaris] = useState<BarisIsianInvoice[]>(() =>
        Isian === null
            ? [BuatBarisKosongInvoice()]
            : Isian.Baris.map((b) => ({
                  ...b,
                  Kunci: BuatKunci(),
                  Jumlah: b.Jumlah.replace(/\.0+$/, ''),
                  Harga: b.Harga.replace(/\.00$/, ''),
                  Diskon: b.Diskon === '0.00' ? '' : b.Diskon.replace(/\.00$/, ''),
              })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const judul = ubah
        ? `Ubah draf ${Isian.Nomor ?? 'invoice'}`
        : uangMuka
          ? 'Buat invoice uang muka (DP)'
          : 'Buat invoice';
    const kueriDp = useQuery({
        queryKey: KunciKueri.Invoice.UangMukaTersedia(uuidPelanggan, Isian?.Uuid ?? ''),
        queryFn: ({ signal }) =>
            AmbilHasilCari<{ Data: UangMukaTersedia[] }>(
                `${AlamatInvoice}/uang-muka?${new URLSearchParams({ pelanggan: uuidPelanggan, ...(Isian ? { untuk: Isian.Uuid } : {}) }).toString()}`,
                signal,
            ),
        enabled: !uangMuka && uuidPelanggan !== '',
        staleTime: 0,
        initialData: !uangMuka && Isian !== null && UangMukaAwal.length > 0 ? { Data: UangMukaAwal } : undefined,
    });
    const daftarDp = !uangMuka && uuidPelanggan !== '' ? (kueriDp.data?.Data ?? []) : [];
    const subtotal = HitungSubtotalInvoice(baris);

    const Kirim = (peristiwa: FormEvent, terbitkan: boolean) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const isi = {
            Jenis,
            UuidPelanggan: uuidPelanggan,
            UuidOutlet: uuidOutlet,
            Tanggal: tanggal,
            TerminHari: termin.trim() === '' ? null : Number(termin),
            Perihal: perihal === '' ? null : perihal,
            NomorReferensi: referensi === '' ? null : referensi,
            HargaTermasukPajak: inklusif,
            KodePph: uangMuka || kodePph.trim() === '' ? null : kodePph.trim(),
            TarifPph: uangMuka || tarifPph.trim() === '' ? null : tarifPph.trim(),
            Catatan: catatan === '' ? null : catatan,
            Syarat: syarat === '' ? null : syarat,
            InfoPembayaran: infoBayar === '' ? null : infoBayar,
            UuidUangMuka: uangMuka ? [] : dipilihDp.filter((u) => daftarDp.some((dp) => dp.Uuid === u)),
            Lanjutkan: terbitkan,
            Baris: baris.map((b) => ({
                UuidProduk: b.UuidProduk,
                Nama: b.Nama,
                Keterangan: b.Keterangan === '' ? null : b.Keterangan,
                JenisBaris: b.JenisBaris,
                SimbolSatuan: b.SimbolSatuan === '' ? null : b.SimbolSatuan,
                Jumlah: b.Jumlah,
                Harga: b.Harga,
                Diskon: b.Diskon === '' ? null : b.Diskon,
                UuidKelompokPajak: b.UuidKelompokPajak,
                HargaTermasukPajak: b.HargaTermasukPajak,
            })),
        };
        const pilihan = {
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (ubah) {
            router.put(`${AlamatInvoice}/${Isian.Uuid}`, isi, pilihan);

            return;
        }

        router.post(AlamatInvoice, isi, pilihan);
    };

    const siap =
        uuidPelanggan !== '' &&
        uuidOutlet !== '' &&
        baris.length > 0 &&
        baris.every((b) => b.Nama.trim() !== '' && Number(b.Jumlah) > 0 && b.Harga !== '');

    const AturDp = (uuid: string, terpilih: boolean) =>
        AturDipilihDp(terpilih ? [...dipilihDp, uuid] : dipilihDp.filter((u) => u !== uuid));

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Invoice', href: AlamatInvoice }]}>
            <DaftarGalatServer galat={galat} />
            {uangMuka ? (
                <Pemberitahuan jenis="info" judul="Invoice uang muka">
                    Dipakai untuk meminta DP sebelum pekerjaan dimulai. Saat invoice akhir dibuat, DP yang sudah terbit
                    dipotong dari tagihan. DP dicatat sebagai pendapatan diterima di muka sampai invoice akhir terbit.
                </Pemberitahuan>
            ) : null}

            <form onSubmit={(e) => Kirim(e, false)} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <Panel judul="Pelanggan & tanggal">
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <PemilihPelangganGrosir
                                uuidTerpilih={uuidPelanggan}
                                namaTerpilih={namaPelanggan}
                                alamat={`${AlamatInvoice}/pelanggan/cari`}
                                saatPilih={(uuid, nama, terminHari) => {
                                    AturPelanggan(uuid);
                                    AturNamaPelanggan(nama);
                                    if (
                                        !ubah &&
                                        terminHari !== undefined &&
                                        (termin === '' || termin === terminOtomatis)
                                    ) {
                                        AturTermin(String(terminHari));
                                        AturTerminOtomatis(String(terminHari));
                                    }
                                    AturDipilihDp([]);
                                }}
                                galat={galat.UuidPelanggan}
                            />
                            <BidangOutlet
                                label="Outlet penerbit"
                                nilai={uuidOutlet}
                                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: `${o.Nama} (${o.Kode})` }))}
                                saatBerubah={AturOutlet}
                                galat={galat.UuidOutlet}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <PemilihTanggal
                                label="Tanggal invoice"
                                nilai={tanggal}
                                saatBerubah={AturTanggal}
                                galat={galat.Tanggal}
                                required
                            />
                            <BidangTeks
                                label="Termin pembayaran (hari)"
                                keterangan="Terisi dari data pelanggan. Ubah bila berbeda. 0 = bayar langsung."
                                nilai={termin}
                                saatBerubah={(nilai) => AturTermin(nilai.replace(/\D/g, '').slice(0, 3))}
                                inputMode="numeric"
                                galat={galat.TerminHari}
                            />
                            <BidangTeks
                                label="No. PO / referensi pelanggan"
                                nilai={referensi}
                                saatBerubah={AturReferensi}
                                maxLength={60}
                                kode
                                galat={galat.NomorReferensi}
                            />
                        </div>
                        <BidangTeks
                            label="Perihal"
                            keterangan="Judul yang tampil di invoice, misalnya Jasa konsultasi September 2026."
                            nilai={perihal}
                            saatBerubah={AturPerihal}
                            maxLength={150}
                            galat={galat.Perihal}
                        />
                    </div>
                </Panel>

                <Panel judul="Baris invoice">
                    <div className="flex flex-col gap-4">
                        <IsianBarisInvoice
                            baris={baris}
                            saatBerubah={AturBaris}
                            opsiKelompokPajak={OpsiKelompokPajak}
                            periksa={periksa}
                            galatServer={galat}
                            maksimal={MAKSIMAL_BARIS}
                            sembunyikanDiskon={uangMuka}
                        />
                        <KotakCentang
                            label="Harga di atas sudah termasuk pajak"
                            nilai={inklusif}
                            saatBerubah={AturInklusif}
                        />
                        <p className="text-isi text-teks-sekunder">
                            Subtotal baris{' '}
                            <span className="font-semibold text-teks-utama tabular-nums">{FormatRupiah(subtotal)}</span>
                            . Pajak, PPh, dan total akhir dihitung sistem dan tampil setelah draf disimpan.
                        </p>
                    </div>
                </Panel>

                {uangMuka ? null : (
                    <>
                        {uuidPelanggan !== '' && daftarDp.length > 0 ? (
                            <Panel
                                judul="Potong uang muka"
                                keterangan="Invoice uang muka terbit milik pelanggan ini yang belum dipotong. DP yang dicentang mengurangi tagihan."
                            >
                                <ul className="flex flex-col gap-1">
                                    {daftarDp.map((dp) => (
                                        <li key={dp.Uuid}>
                                            <KotakCentang
                                                label={`${dp.Nomor} | ${FormatRupiah(dp.Total)}`}
                                                nilai={dipilihDp.includes(dp.Uuid)}
                                                saatBerubah={(terpilih) => AturDp(dp.Uuid, terpilih)}
                                            />
                                        </li>
                                    ))}
                                </ul>
                                {galat.UuidUangMuka ? (
                                    <p className="text-keterangan text-bahaya">{galat.UuidUangMuka}</p>
                                ) : null}
                            </Panel>
                        ) : null}

                        <Panel
                            judul="PPh yang dipotong pelanggan"
                            keterangan="Isi bila pelanggan Anda memotong PPh (misalnya PPh 23 atas jasa). Tarif diisi sesuai ketentuan atau bukti potong. Sistem tidak menebaknya."
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <BidangTeks
                                    label="Jenis PPh"
                                    keterangan="Contoh: PPh 23, PPh 4(2)."
                                    nilai={kodePph}
                                    saatBerubah={AturKodePph}
                                    maxLength={20}
                                    galat={galat.KodePph}
                                />
                                <BidangTeks
                                    label="Tarif PPh (%)"
                                    keterangan="Contoh: 2 untuk 2%."
                                    nilai={tarifPph}
                                    saatBerubah={(nilai) => AturTarifPph(nilai.replace(/[^\d.,]/g, '').slice(0, 9))}
                                    inputMode="decimal"
                                    galat={galat.TarifPph}
                                />
                            </div>
                        </Panel>
                    </>
                )}

                <Panel judul="Catatan & cara bayar">
                    <div className="grid gap-4 lg:grid-cols-3">
                        <BidangTeksPanjang
                            label="Catatan untuk pelanggan"
                            nilai={catatan}
                            saatBerubah={AturCatatan}
                            maksimal={1000}
                            galat={galat.Catatan}
                        />
                        <BidangTeksPanjang
                            label="Syarat & ketentuan"
                            nilai={syarat}
                            saatBerubah={AturSyarat}
                            maksimal={1000}
                            galat={galat.Syarat}
                        />
                        <BidangTeksPanjang
                            label="Cara pembayaran"
                            keterangan="Nomor rekening atau petunjuk transfer."
                            nilai={infoBayar}
                            saatBerubah={AturInfoBayar}
                            maksimal={500}
                            galat={galat.InfoPembayaran}
                        />
                    </div>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="button" memproses={memproses} disabled={!siap} onClick={(e) => Kirim(e, true)}>
                        Simpan & terbitkan
                    </Tombol>
                    <Tombol type="submit" varian="sekunder" memproses={memproses} disabled={!siap}>
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline" type="button">
                        <Link href={ubah ? `${AlamatInvoice}/${Isian.Uuid}` : AlamatInvoice}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
