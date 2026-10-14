import TabTautan from '@/Komponen/Navigasi/TabTautan';

/**
 * Tab "Deposit & gift card" (CRM-06): dua halaman satu rumah di menu Pelanggan, supaya anggaran navigasi D-27 (7
 * sub-menu per grup) tidak bertambah. `aktif` = halaman yang sedang dibuka.
 */
export default function TabDeposit({ aktif }: { aktif: 'isi-deposit' | 'gift-card' }) {
    return (
        <TabTautan
            label="Deposit dan gift card"
            tab={[
                { label: 'Isi deposit', href: '/kelola/pelanggan/isi-deposit', aktif: aktif === 'isi-deposit' },
                { label: 'Gift card', href: '/kelola/pelanggan/gift-card', aktif: aktif === 'gift-card' },
            ]}
        />
    );
}
