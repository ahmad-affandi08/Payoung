import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import PesanFiturLoyalti from '@/Komponen/Pelanggan/PesanFiturLoyalti';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsPengaturanLoyalti } from '@/Tipe/Pelanggan';

/**
 * F-16b: pengaturan poin loyalti tenant (aktif, belanja per poin, masa berlaku, periode evaluasi tier) dan penukaran
 * poin sebagai diskon (nilai per poin, minimal tukar).
 */
export default function HalamanPengaturanLoyalti({ Pengaturan, FiturAktif, Izin }: PropsPengaturanLoyalti) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState({
        Aktif: Pengaturan.Aktif,
        BelanjaPerPoin: Pengaturan.BelanjaPerPoin.replace(/\.00$/, ''),
        MasaBerlakuBulan: String(Pengaturan.MasaBerlakuBulan),
        BulanEvaluasiTier: String(Pengaturan.BulanEvaluasiTier),
        NilaiTukarPoin: Pengaturan.NilaiTukarPoin.replace(/\.00$/, ''),
        MinimalTukarPoin: String(Pengaturan.MinimalTukarPoin),
    });
    const [memproses, AturMemproses] = useState(false);
    const nonaktif = !Izin.Kelola;

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.put(
            '/kelola/pelanggan/loyalti',
            {
                Aktif: isian.Aktif,
                BelanjaPerPoin: isian.BelanjaPerPoin === '' ? '0' : isian.BelanjaPerPoin,
                MasaBerlakuBulan: Number(isian.MasaBerlakuBulan || '0'),
                BulanEvaluasiTier: Number(isian.BulanEvaluasiTier || '0'),
                NilaiTukarPoin: isian.NilaiTukarPoin === '' ? '0' : isian.NilaiTukarPoin,
                MinimalTukarPoin: Number(isian.MinimalTukarPoin || '0'),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Pengaturan loyalti">
            {FiturAktif ? null : <PesanFiturLoyalti />}
            {Izin.Kelola ? null : <PesanHanyaLihat izin="pelanggan.kelola" objek="pengaturan loyalti" />}
            <Card className="gap-4 rounded-panel p-4 shadow-none">
                <form
                    onSubmit={Simpan}
                    className="flex flex-col gap-4"
                    aria-label="Formulir pengaturan loyalti"
                    noValidate
                >
                    <KotakCentang
                        label="Aktifkan poin loyalti untuk pelanggan"
                        nilai={isian.Aktif}
                        saatBerubah={(nilai) => {
                            if (!nonaktif) {
                                AturIsian({ ...isian, Aktif: nilai });
                            }
                        }}
                    />
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangUang
                            label="Belanja untuk 1 poin"
                            nilai={isian.BelanjaPerPoin}
                            saatBerubah={(nilai) => AturIsian({ ...isian, BelanjaPerPoin: nilai })}
                            galat={galat.BelanjaPerPoin}
                            required
                            keterangan="Poin = total belanja ÷ angka ini, dibulatkan ke bawah, dikali pengali tier. Minimal Rp 100."
                            disabled={nonaktif}
                        />
                        <BidangJumlah
                            label="Masa berlaku poin"
                            nilai={isian.MasaBerlakuBulan}
                            saatBerubah={(nilai) => AturIsian({ ...isian, MasaBerlakuBulan: nilai })}
                            desimal={0}
                            digitBulat={2}
                            akhiran="bulan"
                            keterangan="Poin yang tidak dipakai hangus otomatis, yang paling lama lebih dulu. 1–60 bulan."
                            galat={galat.MasaBerlakuBulan}
                            required
                            disabled={nonaktif}
                        />
                        <BidangJumlah
                            label="Periode evaluasi tier"
                            nilai={isian.BulanEvaluasiTier}
                            saatBerubah={(nilai) => AturIsian({ ...isian, BulanEvaluasiTier: nilai })}
                            desimal={0}
                            digitBulat={2}
                            akhiran="bulan"
                            keterangan="Tier dinilai dari total belanja selama periode ini. 1–24 bulan."
                            galat={galat.BulanEvaluasiTier}
                            required
                            disabled={nonaktif}
                        />
                        <BidangUang
                            label="Nilai 1 poin saat ditukar"
                            nilai={isian.NilaiTukarPoin}
                            saatBerubah={(nilai) => AturIsian({ ...isian, NilaiTukarPoin: nilai })}
                            galat={galat.NilaiTukarPoin}
                            keterangan="Kasir menukar poin sebagai potongan harga sebelum pajak. Minimal Rp 1, tidak boleh melebihi belanja untuk 1 poin."
                            disabled={nonaktif}
                        />
                        <BidangJumlah
                            label="Minimal poin sekali tukar"
                            nilai={isian.MinimalTukarPoin}
                            saatBerubah={(nilai) => AturIsian({ ...isian, MinimalTukarPoin: nilai })}
                            desimal={0}
                            digitBulat={6}
                            akhiran="poin"
                            keterangan="Penukaran poin hanya bisa saat kasir online. 1–100.000 poin."
                            galat={galat.MinimalTukarPoin}
                            disabled={nonaktif}
                        />
                    </div>
                    {isian.BelanjaPerPoin !== '' && Number(isian.BelanjaPerPoin) > 0 ? (
                        <Pemberitahuan jenis="info">
                            Contoh: belanja {FormatRupiah('250000')} mendapat{' '}
                            {Math.floor(250000 / Number(isian.BelanjaPerPoin)).toLocaleString('id-ID')} poin (tier ×1).
                            {isian.NilaiTukarPoin !== '' && Number(isian.NilaiTukarPoin) > 0
                                ? ` Menukar 100 poin memberi potongan ${FormatRupiah(String(100 * Number(isian.NilaiTukarPoin)))}.`
                                : null}
                        </Pemberitahuan>
                    ) : null}
                    {Izin.Kelola ? (
                        <div>
                            <Tombol type="submit" memproses={memproses}>
                                Simpan pengaturan loyalti
                            </Tombol>
                        </div>
                    ) : null}
                </form>
            </Card>
        </TataLetakAplikasi>
    );
}
