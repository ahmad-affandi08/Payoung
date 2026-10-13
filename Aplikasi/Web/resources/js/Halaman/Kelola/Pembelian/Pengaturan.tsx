import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsPengaturanPembelian } from '@/Tipe/Pembelian';

/** Nilai desimal tampilan tanpa ekor nol (`5000000.00` → `5000000`, `2.50` → `2.5`). */
function RapikanDesimal(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}

/**
 * F-04 fase 1: pengaturan pembelian tenant: batas total PO yang butuh persetujuan (§19.2, bawaan Rp 5.000.000) dan
 * toleransi penerimaan melebihi jumlah PO (BR-04.1, bawaan 0%). Izin `pembelian.po.setujui`.
 */
export default function HalamanPengaturanPembelian({ Pengaturan }: PropsPengaturanPembelian) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const batasAwal = RapikanDesimal(Pengaturan.BatasPersetujuanPo);
    const toleransiAwal = RapikanDesimal(Pengaturan.ToleransiPenerimaanPersen);
    const [batas, AturBatas] = useState(batasAwal);
    const [toleransi, AturToleransi] = useState(toleransiAwal);
    const [drafOtomatis, AturDrafOtomatis] = useState(Pengaturan.DrafPoOtomatis);
    const [sumberDraf, AturSumberDraf] = useState<string>(Pengaturan.SumberDrafPo);
    const [cakupanDraf, AturCakupanDraf] = useState(String(Pengaturan.HariCakupanDrafPo));
    const [memproses, AturMemproses] = useState(false);
    const berubah =
        batas !== batasAwal ||
        toleransi !== toleransiAwal ||
        drafOtomatis !== Pengaturan.DrafPoOtomatis ||
        sumberDraf !== Pengaturan.SumberDrafPo ||
        cakupanDraf !== String(Pengaturan.HariCakupanDrafPo);

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.put(
            `${AlamatPembelian}/pengaturan`,
            {
                BatasPersetujuanPo: batas === '' ? '0' : batas,
                ToleransiPenerimaanPersen: toleransi === '' ? '0' : toleransi,
                DrafPoOtomatis: drafOtomatis,
                SumberDrafPo: sumberDraf,
                HariCakupanDrafPo: Number(cakupanDraf),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );
    };

    return (
        <TataLetakAplikasi judul="Pengaturan pembelian">
            <DaftarGalatServer galat={galat} kecuali={['BatasPersetujuanPo', 'ToleransiPenerimaanPersen']} />
            <form
                onSubmit={Simpan}
                noValidate
                aria-label="Pengaturan pembelian"
                className="grid gap-4 lg:grid-cols-2 lg:items-start"
            >
                <Panel
                    judul="Persetujuan pesanan pembelian"
                    idJudul="judul-batas-po"
                    keterangan="Pesanan dengan total di atas batas ini harus disetujui pengguna lain yang punya izin menyetujui pesanan pembelian."
                >
                    <BidangUang
                        label="Batas total tanpa persetujuan"
                        nilai={batas}
                        saatBerubah={AturBatas}
                        galat={galat.BatasPersetujuanPo}
                        required
                        keterangan="Bawaan Rp 5.000.000. Isi 0 bila semua pesanan harus disetujui."
                    />
                </Panel>
                <Panel
                    judul="Toleransi penerimaan barang"
                    idJudul="judul-toleransi"
                    keterangan="Batas barang diterima melebihi jumlah pesanan, dalam persen per baris."
                >
                    <BidangJumlah
                        label="Toleransi lebih dari pesanan"
                        nilai={toleransi}
                        saatBerubah={AturToleransi}
                        desimal={2}
                        digitBulat={3}
                        akhiran="%"
                        galat={galat.ToleransiPenerimaanPersen}
                        required
                        keterangan="Bawaan 0%: penerimaan tidak boleh melebihi jumlah pesanan."
                    />
                </Panel>
                <Panel
                    judul="Draf pesanan otomatis"
                    idJudul="judul-draf-otomatis"
                    className="lg:col-span-2"
                    keterangan="Setiap pagi sistem menyiapkan draf pesanan pembelian ke pemasok dan harga pembelian terakhir. Draf tetap diperiksa dan diajukan oleh Anda."
                >
                    <KotakCentang
                        label="Siapkan draf pesanan pembelian otomatis"
                        nilai={drafOtomatis}
                        saatBerubah={AturDrafOtomatis}
                    />
                    <BidangPilihan
                        label="Dasar perhitungan jumlah"
                        nilai={sumberDraf}
                        opsi={[
                            { Nilai: 'Minimum', Label: 'Stok di bawah minimum' },
                            { Nilai: 'Perkiraan', Label: 'Perkiraan pemakaian' },
                        ]}
                        saatBerubah={AturSumberDraf}
                        galat={galat.SumberDrafPo}
                        disabled={!drafOtomatis}
                        required
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        {sumberDraf === 'Perkiraan'
                            ? 'Jumlah dihitung dari rata-rata pemakaian 28 hari terakhir (penjualan, bahan resep, produksi, barang terbuang) dikali cakupan hari di bawah, dikurangi stok sekarang dan pesanan yang belum diterima. Berlaku juga untuk barang tanpa stok minimum.'
                            : 'Barang dipesan sampai stok maksimum (atau 2 kali stok minimum) bila stoknya sudah sama dengan atau di bawah stok minimum.'}
                    </p>
                    {sumberDraf === 'Perkiraan' ? (
                        <BidangPilihan
                            label="Cakupan kebutuhan"
                            nilai={cakupanDraf}
                            opsi={[
                                { Nilai: '7', Label: '7 hari ke depan' },
                                { Nilai: '14', Label: '14 hari ke depan' },
                                { Nilai: '30', Label: '30 hari ke depan' },
                            ]}
                            saatBerubah={AturCakupanDraf}
                            galat={galat.HariCakupanDrafPo}
                            disabled={!drafOtomatis}
                            required
                        />
                    ) : null}
                </Panel>
                <div className="lg:col-span-2">
                    <Tombol type="submit" memproses={memproses} disabled={!berubah}>
                        Simpan pengaturan
                    </Tombol>
                </div>
            </form>
        </TataLetakAplikasi>
    );
}
