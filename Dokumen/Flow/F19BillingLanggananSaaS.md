<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### F-19 · Billing Langganan SaaS

> Ini sisi tenant. Sisi pengelola (pembuatan tagihan, verifikasi, dunning, laporan MRR) ada di **P-08**, dan komisi mitra di **P-12**.

- Paket & add-on (§21). Tagihan bulanan/tahunan, invoice PDF, pembayaran via payment gateway (VA, QRIS, e-wallet, kartu).
- Proration saat upgrade di tengah periode (selesai v5.35, D-98), downgrade berlaku periode berikutnya (belum, menunggu BR-P04.4).
- Dunning: pengingat H-7, H-3, H0, H+3 via email & WA; `Tertunggak` 7 hari → `Ditangguhkan`.
- Fase 0–1: tagihan dan aktivasi manual oleh tim Keuangan di Platform Pengelola (verifikasi bukti transfer). Fase 2: gateway & dunning otomatis. Fase 3: faktur pajak langganan.
- Kode referral & reseller/agen (komisi agen).
