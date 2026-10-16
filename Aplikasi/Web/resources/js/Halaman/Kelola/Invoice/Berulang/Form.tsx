import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihPelangganGrosir from '@/Komponen/Grosir/PemilihPelangganGrosir';
import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import IsianBarisInvoice, { BuatBarisKosongInvoice } from '@/Komponen/Invoice/IsianBarisInvoice';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { HitungSubtotalInvoice } from '@/Pustaka/HitungInvoice';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianInvoice, PropsFormInvoiceBerulang } from '@/Tipe/Invoice';

const alamat = `${AlamatInvoice}/berulang`;
const MAKSIMAL_BARIS = 200;

let urut = 0;

function BuatKunci(): string {
    urut += 1;

    return `isian-berulang-${String(urut)}`;
}

/**
 * Form templat invoice berulang (F-21). Isi tagihannya sama dengan form invoice; ditambah jadwal (frekuensi, interval,
 * tanggal mulai, batas) dan mode (draf saja, terbitkan, atau terbitkan lalu kirim WhatsApp). Setelah siklus pertama
 * terbit, jadwal terkunci; isi tagihan tetap bisa diubah dan berlaku mulai invoice berikutnya.
 */
export default function HalamanFormInvoiceBerulang({
    Isian,
    Kebiasaan,
    OpsiOutlet,
    OpsiKelompokPajak,
    OpsiFrekuensi,
    OpsiMode,
    HariIni,
}: PropsFormInvoiceBerulang) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const ubah = Isian !== null;
    const terkunci = Isian?.JadwalTerkunci ?? false;
    const [nama, AturNama] = useState(Isian?.Nama ?? '');
    const [uuidPelanggan, AturPelanggan] = useState(Isian?.UuidPelanggan ?? '');
    const [namaPelanggan, AturNamaPelanggan] = useState(Isian?.NamaPelanggan ?? '');
    const [uuidOutlet, AturOutlet] = useState(
        Isian?.UuidOutlet ?? (OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : ''),
    );
    const [frekuensi, AturFrekuensi] = useState<string>(Isian?.Frekuensi ?? 'Bulanan');
    const [interval, AturInterval] = useState(String(Isian?.Interval ?? 1));
    const [mulai, AturMulai] = useState(Isian?.TanggalMulai ?? HariIni);
    const [berakhir, AturBerakhir] = useState(Isian?.TanggalBerakhir ?? '');
    const [batas, AturBatas] = useState(Isian?.BatasKali ?? '');
    const [mode, AturMode] = useState<string>(Isian?.Mode ?? 'Terbitkan');
    const [termin, AturTermin] = useState(Isian?.TerminHari ?? '');
    const [perihal, AturPerihal] = useState(Isian?.Perihal ?? '');
    const [referensi, AturReferensi] = useState(Isian?.NomorReferensi ?? '');
    const [inklusif, AturInklusif] = useState(Isian?.HargaTermasukPajak ?? false);
    const [kodePph, AturKodePph] = useState(Isian?.KodePph ?? '');
    const [tarifPph, AturTarifPph] = useState(Isian?.TarifPph ?? '');
    const [catatan, AturCatatan] = useState(Isian?.Catatan ?? '');
    const [syarat, AturSyarat] = useState(Isian?.Syarat ?? Kebiasaan?.Syarat ?? '');
    const [infoBayar, AturInfoBayar] = useState(Isian?.InfoPembayaran ?? Kebiasaan?.InfoPembayaran ?? '');
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
    const judul = ubah ? `Ubah templat ${Isian.Nama}` : 'Buat invoice berulang';
    const subtotal = HitungSubtotalInvoice(baris);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const isi = {
            Nama: nama,
            UuidPelanggan: uuidPelanggan,
            UuidOutlet: uuidOutlet,
            Frekuensi: frekuensi,
            Interval: interval === '' ? null : Number(interval),
            TanggalMulai: mulai,
            TanggalBerakhir: berakhir === '' ? null : berakhir,
            BatasKali: batas === '' ? null : Number(batas),
            Mode: mode,
            TerminHari: termin.trim() === '' ? null : Number(termin),
            Perihal: perihal === '' ? null : perihal,
            NomorReferensi: referensi === '' ? null : referensi,
            HargaTermasukPajak: inklusif,
            KodePph: kodePph.trim() === '' ? null : kodePph.trim(),
            TarifPph: tarifPph.trim() === '' ? null : tarifPph.trim(),
            Catatan: catatan === '' ? null : catatan,
            Syarat: syarat === '' ? null : syarat,
            InfoPembayaran: infoBayar === '' ? null : infoBayar,
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
        const pilihan = { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };

        if (ubah) {
            router.put(`${alamat}/${Isian.Uuid}`, isi, pilihan);

            return;
        }

        router.post(alamat, isi, pilihan);
    };

    const siap =
        nama.trim() !== '' &&
        uuidPelanggan !== '' &&
        uuidOutlet !== '' &&
        Number(interval) > 0 &&
        mulai !== '' &&
        baris.length > 0 &&
        baris.every((b) => b.Nama.trim() !== '' && Number(b.Jumlah) > 0 && b.Harga !== '');

    return (
        <TataLetakAplikasi
            judul={judul}
            jejak={[
                { label: 'Invoice', href: AlamatInvoice },
                { label: 'Invoice berulang', href: alamat },
            ]}
        >
            <DaftarGalatServer galat={galat} />
            {terkunci ? (
                <Pemberitahuan jenis="info" judul="Jadwal sudah terkunci">
                    Invoice pertama sudah terbit, jadi frekuensi, interval, dan tanggal mulai tidak bisa diubah. Isi
                    tagihan di bawah tetap bisa diubah dan berlaku mulai invoice berikutnya.
                </Pemberitahuan>
            ) : null}

            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <Panel judul="Jadwal">
                    <div className="flex flex-col gap-4">
                        <BidangTeks
                            label="Nama templat"
                            keterangan="Hanya untuk Anda, misalnya Retainer PT Sumber Makmur."
                            nilai={nama}
                            saatBerubah={AturNama}
                            maxLength={120}
                            galat={galat.Nama}
                            required
                        />
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <BidangPilihan
                                label="Diulang"
                                nilai={frekuensi}
                                opsi={OpsiFrekuensi}
                                saatBerubah={AturFrekuensi}
                                galat={galat.Frekuensi}
                                disabled={terkunci}
                                required
                            />
                            <BidangTeks
                                label="Setiap berapa kali"
                                keterangan="Angka 1 = setiap periode, 2 = selang satu periode."
                                nilai={interval}
                                saatBerubah={(nilai) => AturInterval(nilai.replace(/\D/g, '').slice(0, 2))}
                                inputMode="numeric"
                                galat={galat.Interval}
                                disabled={terkunci}
                                required
                            />
                            <PemilihTanggal
                                label="Invoice pertama"
                                nilai={mulai}
                                saatBerubah={AturMulai}
                                min={ubah ? undefined : HariIni}
                                galat={galat.TanggalMulai}
                                disabled={terkunci}
                                required
                            />
                            <BidangPilihan
                                label="Yang dilakukan sistem"
                                nilai={mode}
                                opsi={OpsiMode}
                                saatBerubah={AturMode}
                                galat={galat.Mode}
                                required
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <PemilihTanggal
                                label="Berhenti setelah tanggal"
                                keterangan="Kosongkan bila tanpa batas tanggal."
                                nilai={berakhir}
                                saatBerubah={AturBerakhir}
                                galat={galat.TanggalBerakhir}
                            />
                            <BidangTeks
                                label="Atau berhenti setelah berapa invoice"
                                keterangan="Kosongkan bila tanpa batas jumlah."
                                nilai={batas}
                                saatBerubah={(nilai) => AturBatas(nilai.replace(/\D/g, '').slice(0, 3))}
                                inputMode="numeric"
                                galat={galat.BatasKali}
                            />
                        </div>
                    </div>
                </Panel>

                <Panel judul="Pelanggan">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <PemilihPelangganGrosir
                            uuidTerpilih={uuidPelanggan}
                            namaTerpilih={namaPelanggan}
                            alamat={`${AlamatInvoice}/pelanggan/cari`}
                            saatPilih={(uuid, nama2) => {
                                AturPelanggan(uuid);
                                AturNamaPelanggan(nama2);
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
                        <BidangTeks
                            label="Termin pembayaran (hari)"
                            keterangan="Kosong = ikut termin di data pelanggan. 0 = bayar langsung."
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
                </Panel>

                <Panel judul="Isi tagihan">
                    <div className="flex flex-col gap-4">
                        <BidangTeks
                            label="Perihal"
                            keterangan="Boleh memakai {periode}, {bulan}, dan {tahun}; diisi sesuai tanggal invoice. Contoh: Maintenance {periode}."
                            nilai={perihal}
                            saatBerubah={AturPerihal}
                            maxLength={150}
                            galat={galat.Perihal}
                        />
                        <IsianBarisInvoice
                            baris={baris}
                            saatBerubah={AturBaris}
                            opsiKelompokPajak={OpsiKelompokPajak}
                            periksa={periksa}
                            galatServer={galat}
                            maksimal={MAKSIMAL_BARIS}
                        />
                        <KotakCentang
                            label="Harga di atas sudah termasuk pajak"
                            nilai={inklusif}
                            saatBerubah={AturInklusif}
                        />
                        <p className="text-isi text-teks-sekunder">
                            Subtotal baris{' '}
                            <span className="font-semibold text-teks-utama tabular-nums">{FormatRupiah(subtotal)}</span>
                            . Pajak, PPh, dan total dihitung sistem tiap siklus dengan tarif yang berlaku pada
                            tanggalnya.
                        </p>
                    </div>
                </Panel>

                <Panel
                    judul="PPh yang dipotong pelanggan"
                    keterangan="Isi bila pelanggan memotong PPh (misalnya PPh 23 atas jasa). Kosongkan bila tidak ada."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <BidangTeks
                            label="Jenis PPh"
                            nilai={kodePph}
                            saatBerubah={AturKodePph}
                            maxLength={20}
                            galat={galat.KodePph}
                        />
                        <BidangTeks
                            label="Tarif PPh (%)"
                            nilai={tarifPph}
                            saatBerubah={(nilai) => AturTarifPph(nilai.replace(/[^\d.,]/g, '').slice(0, 9))}
                            inputMode="decimal"
                            galat={galat.TarifPph}
                        />
                    </div>
                </Panel>

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
                            nilai={infoBayar}
                            saatBerubah={AturInfoBayar}
                            maksimal={500}
                            galat={galat.InfoPembayaran}
                        />
                    </div>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} disabled={!siap}>
                        Simpan templat
                    </Tombol>
                    <Button asChild variant="outline" type="button">
                        <Link href={ubah ? `${alamat}/${Isian.Uuid}` : alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
