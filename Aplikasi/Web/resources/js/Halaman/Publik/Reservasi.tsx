import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import KerangkaPelanggan from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihSlot from '@/Komponen/Reservasi/PemilihSlot';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import type { LayananReservasi } from '@/Tipe/Reservasi';

type PropsReservasiPublik = {
    Aktif: boolean;
    Slug: string;
    Toko?: { Nama: string };
    Outlet?: { Uuid: string; Nama: string }[];
    Layanan?: LayananReservasi[];
    Staf?: { Uuid: string; Nama: string }[];
    HariIni?: string;
    TanggalTerakhir?: string;
    KonfirmasiOtomatis?: boolean;
};

/**
 * F-07 mode service (SLS-07): reservasi online pelanggan tanpa login. Jam kosong dihitung server dari jadwal staf;
 * setelah terkirim pelanggan dibawa ke halaman status (bisa dibatalkan dari sana).
 */
export default function HalamanReservasiPublik(props: PropsReservasiPublik) {
    const outlet = props.Outlet ?? [];
    const layanan = props.Layanan ?? [];
    const staf = props.Staf ?? [];
    const formulir = useForm({
        Outlet: '',
        UuidLayanan: '',
        Tanggal: props.HariIni ?? '',
        UuidStaf: '',
        Jam: '',
        NamaPelanggan: '',
        NoHp: '',
        Catatan: '',
        Setuju: false,
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const Kirim = (e: FormEvent) => {
        e.preventDefault();
        formulir.post(`/${props.Slug}/reservasi`);
    };

    if (!props.Aktif) {
        return (
            <KerangkaPelanggan>
                <Head title="Reservasi" />
                <JudulHalaman>Reservasi online belum dibuka</JudulHalaman>
                <p className="text-teks-sekunder">Hubungi toko langsung untuk membuat janji.</p>
            </KerangkaPelanggan>
        );
    }

    return (
        <KerangkaPelanggan toko={props.Toko?.Nama}>
            <Head title={`Reservasi ${props.Toko?.Nama ?? ''}`} />
            <header className="flex flex-col gap-1 border-b border-garis pb-3">
                <p className="text-label text-teks-sekunder">Reservasi online</p>
                <JudulHalaman className="break-words">{props.Toko?.Nama}</JudulHalaman>
            </header>
            {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
            {layanan.length === 0 ? (
                <p className="text-teks-sekunder">Belum ada layanan yang bisa dipesan online.</p>
            ) : (
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <BidangOutlet
                        nilai={d.Outlet}
                        opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                        saatBerubah={(nilai) => formulir.setData({ ...d, Outlet: nilai, Jam: '' })}
                        galat={galat.Outlet}
                        sembunyiBilaTunggal
                    />
                    <BidangPilihan
                        label="Layanan"
                        nilai={d.UuidLayanan}
                        opsi={layanan.map((l) => ({
                            Nilai: l.Uuid,
                            Label: l.Nama,
                            Keterangan: `${String(l.DurasiMenit)} menit${l.Harga ? ` | ${FormatRupiah(l.Harga)}` : ''}`,
                        }))}
                        saatBerubah={(nilai) => formulir.setData({ ...d, UuidLayanan: nilai, Jam: '' })}
                        galat={galat.UuidLayanan}
                        required
                    />
                    <BidangPilihan
                        label="Staf"
                        nilai={d.UuidStaf}
                        opsi={[
                            { Nilai: '', Label: 'Siapa saja' },
                            ...staf.map((s) => ({ Nilai: s.Uuid, Label: s.Nama })),
                        ]}
                        saatBerubah={(nilai) => formulir.setData({ ...d, UuidStaf: nilai, Jam: '' })}
                    />
                    <PemilihTanggal
                        label="Tanggal"
                        nilai={d.Tanggal}
                        min={props.HariIni}
                        max={props.TanggalTerakhir}
                        saatBerubah={(nilai) => formulir.setData({ ...d, Tanggal: nilai, Jam: '' })}
                        galat={galat.Tanggal}
                        required
                    />
                    <PemilihSlot
                        alamat={`/${props.Slug}/reservasi/slot`}
                        outlet={d.Outlet}
                        layanan={d.UuidLayanan}
                        tanggal={d.Tanggal}
                        staf={d.UuidStaf}
                        nilai={d.Jam}
                        saatPilih={(jam) => formulir.setData('Jam', jam)}
                        galat={galat.Jam}
                    />
                    <BidangTeks
                        label="Nama"
                        nilai={d.NamaPelanggan}
                        saatBerubah={(nilai) => formulir.setData('NamaPelanggan', nilai)}
                        galat={galat.NamaPelanggan}
                        required
                    />
                    <BidangTeks
                        label="Nomor WhatsApp"
                        nilai={d.NoHp}
                        saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                        galat={galat.NoHp}
                        inputMode="tel"
                        keterangan="Pengingat dikirim ke nomor ini sehari sebelumnya."
                        required
                    />
                    <BidangTeks
                        label="Catatan (opsional)"
                        nilai={d.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        galat={galat.Catatan}
                    />
                    <KotakCentang
                        label="Saya setuju nama & nomor WhatsApp dipakai toko untuk reservasi dan pengingat."
                        nilai={d.Setuju}
                        saatBerubah={(nilai) => formulir.setData('Setuju', nilai)}
                    />
                    {galat.Setuju ? <p className="text-keterangan text-bahaya">{galat.Setuju}</p> : null}
                    <Tombol type="submit" memproses={formulir.processing} disabled={d.Jam === '' || !d.Setuju}>
                        {props.KonfirmasiOtomatis ? 'Pesan sekarang' : 'Kirim permintaan reservasi'}
                    </Tombol>
                </form>
            )}
        </KerangkaPelanggan>
    );
}
