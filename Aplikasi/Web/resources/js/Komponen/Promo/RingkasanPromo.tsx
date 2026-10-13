import { BagianRingkasan, BarisRingkasan, TampilYaTidak } from '@/Komponen/Formulir/Wizard/RingkasanWizard';
import type { OpsiNilai, PropsFormulirPromo } from '@/Tipe/Promo';

import {
    KalimatBarang,
    KalimatHari,
    KalimatJam,
    KalimatPeriode,
    KalimatPotongan,
    KalimatRingkasPromo,
    AdaPendanaanPemasok,
    type IsianPromo,
    type KunciLangkahPromo,
    type LangkahPromo,
} from './LangkahPromo';

type PropsRingkasanPromo = {
    isian: IsianPromo;
    langkah: LangkahPromo[];
    namaProduk: Record<string, string>;
    opsi: Pick<
        PropsFormulirPromo,
        | 'OpsiOutlet'
        | 'OpsiTier'
        | 'OpsiKategori'
        | 'OpsiKanal'
        | 'OpsiMetodeBayar'
        | 'OpsiUlangTahun'
        | 'OpsiPeriodeBatas'
        | 'OpsiPemasok'
    >;
    labelJenisAksi: string;
    saatUbah: (kunci: KunciLangkahPromo) => void;
};

const SebutPilihan = (terpilih: string[], opsi: OpsiNilai[], semua: string): string =>
    terpilih.length === 0
        ? semua
        : terpilih.map((nilai) => opsi.find((item) => item.Nilai === nilai)?.Label ?? nilai).join(', ');

/**
 * Ringkasan baca-saja langkah "Tinjau & simpan" promo: satu kalimat utuh di atas, lalu isian per langkah dengan tombol
 * "Ubah" yang kembali ke langkah itu. Kata "tanpa batas" ditulis eksplisit supaya isian kosong tidak terkesan lupa.
 */
export default function RingkasanPromo({
    isian,
    langkah,
    namaProduk,
    opsi,
    labelJenisAksi,
    saatUbah,
}: PropsRingkasanPromo) {
    const Cari = (kunci: KunciLangkahPromo) => langkah.find((item) => item.Kunci === kunci);
    const konteks = { namaProduk, opsiKategori: opsi.OpsiKategori };
    const langkahJenis = Cari('JenisNama');
    const langkahPotongan = Cari('Potongan');
    const langkahBarang = Cari('Barang');
    const langkahWaktu = Cari('Waktu');
    const langkahPelanggan = Cari('Pelanggan');
    const pemasok = opsi.OpsiPemasok.find((item) => item.Nilai === isian.UuidPemasok);

    return (
        <div className="flex flex-col gap-4">
            <p
                className="rounded-kontrol border border-garis bg-permukaan-sorot p-3 text-isi font-semibold text-teks-utama"
                aria-label="Ringkasan promo"
            >
                {KalimatRingkasPromo(isian, konteks)}
            </p>
            {langkahJenis ? (
                <BagianRingkasan langkah={langkahJenis} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Jenis potongan">{labelJenisAksi}</BarisRingkasan>
                    <BarisRingkasan nama="Kode promo">
                        <span className="font-mono">{isian.Kode.toUpperCase()}</span>
                    </BarisRingkasan>
                    <BarisRingkasan nama="Nama promo">{isian.Nama}</BarisRingkasan>
                    <BarisRingkasan nama="Prioritas">{isian.Prioritas === '' ? '0' : isian.Prioritas}</BarisRingkasan>
                    <BarisRingkasan nama="Eksklusif">{TampilYaTidak(isian.Eksklusif)}</BarisRingkasan>
                    <BarisRingkasan nama="Wajib kode voucher">{TampilYaTidak(isian.WajibVoucher)}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahPotongan ? (
                <BagianRingkasan langkah={langkahPotongan} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Potongan">{KalimatPotongan(isian)}</BarisRingkasan>
                    {(isian.JenisAksi === 'BeliXGratisY' || isian.JenisAksi === 'BundelHargaTetap') && (
                        <BarisRingkasan nama="Batas per transaksi">
                            {isian.BatasPerTransaksi === '' ? 'Tanpa batas' : `${isian.BatasPerTransaksi} set`}
                        </BarisRingkasan>
                    )}
                </BagianRingkasan>
            ) : null}
            {langkahBarang ? (
                <BagianRingkasan langkah={langkahBarang} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Berlaku untuk">
                        {KalimatBarang(isian, konteks).replace(/^./, (huruf) => huruf.toUpperCase())}
                    </BarisRingkasan>
                    {isian.JenisAksi !== 'BundelHargaTetap' && isian.JenisAksi !== 'BeliXGratisY' ? (
                        <BarisRingkasan nama="Jumlah barang minimal">
                            {isian.JumlahMinimal === '' ? 'Tanpa minimal' : isian.JumlahMinimal}
                        </BarisRingkasan>
                    ) : null}
                </BagianRingkasan>
            ) : null}
            {langkahWaktu ? (
                <BagianRingkasan langkah={langkahWaktu} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Periode">{KalimatPeriode(isian)}</BarisRingkasan>
                    <BarisRingkasan nama="Hari">{KalimatHari(isian.Hari)}</BarisRingkasan>
                    <BarisRingkasan nama="Jam">{KalimatJam(isian)}</BarisRingkasan>
                    <BarisRingkasan nama="Minimal belanja">
                        {isian.MinimalSubtotal === '' || isian.MinimalSubtotal === '0'
                            ? 'Tanpa minimal'
                            : `Rp ${isian.MinimalSubtotal.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Kuota total">
                        {isian.Kuota === '' ? 'Tanpa batas' : `${isian.Kuota} transaksi`}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Kanal">
                        {SebutPilihan(isian.Kanal, opsi.OpsiKanal, 'Semua kanal')}
                    </BarisRingkasan>
                    {opsi.OpsiTier.length > 0 ? (
                        <BarisRingkasan nama="Tier pelanggan">
                            {SebutPilihan(isian.Tier, opsi.OpsiTier, 'Semua pembeli')}
                        </BarisRingkasan>
                    ) : null}
                    {opsi.OpsiOutlet.length > 1 ? (
                        <BarisRingkasan nama="Outlet">
                            {SebutPilihan(isian.Outlet, opsi.OpsiOutlet, 'Semua outlet')}
                        </BarisRingkasan>
                    ) : null}
                </BagianRingkasan>
            ) : null}
            {langkahPelanggan ? (
                <BagianRingkasan langkah={langkahPelanggan} saatUbah={saatUbah}>
                    {AdaPendanaanPemasok(isian.JenisAksi) ? (
                        <BarisRingkasan nama="Ditanggung pemasok">
                            {pemasok
                                ? `${pemasok.Label} (${isian.PersenDanaPemasok}% dari potongan)`
                                : 'Tidak, ditanggung usaha sendiri'}
                        </BarisRingkasan>
                    ) : null}
                    {opsi.OpsiMetodeBayar.length > 0 ? (
                        <BarisRingkasan nama="Metode bayar">
                            {SebutPilihan(isian.MetodeBayar, opsi.OpsiMetodeBayar, 'Semua metode')}
                        </BarisRingkasan>
                    ) : null}
                    <BarisRingkasan nama="Ulang tahun pelanggan">
                        {isian.UlangTahun === ''
                            ? 'Tanpa syarat ulang tahun'
                            : (opsi.OpsiUlangTahun.find((item) => item.Nilai === isian.UlangTahun)?.Label ??
                                  isian.UlangTahun) +
                              (isian.UlangTahun === 'Rentang' ? `, ${isian.HariUlangTahun} hari` : '')}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Hanya transaksi pertama">
                        {TampilYaTidak(isian.TransaksiPertama)}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Batas pakai per pelanggan">
                        {isian.BatasPerPelanggan === ''
                            ? 'Tanpa batas'
                            : `${isian.BatasPerPelanggan} kali ${(
                                  opsi.OpsiPeriodeBatas.find((item) => item.Nilai === isian.PeriodeBatasPelanggan)
                                      ?.Label ?? ''
                              ).toLowerCase()}`.trim()}
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
        </div>
    );
}
