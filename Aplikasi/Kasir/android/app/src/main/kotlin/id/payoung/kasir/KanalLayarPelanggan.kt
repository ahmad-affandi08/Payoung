package id.payoung.kasir

import android.app.Activity
import android.app.Presentation
import android.content.Context
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Typeface
import android.hardware.display.DisplayManager
import android.os.Bundle
import android.util.TypedValue
import android.view.Display
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import io.flutter.FlutterInjector
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import org.json.JSONObject

/**
 * Layar pelanggan di layar kedua (PRD §17.2.5a `PortLayar`, v2.01): POS all-in-one dua layar (Sunmi T2/D2s, iMin D4,
 * dll.) dan monitor HDMI kedua memakai Android *presentation display*. Dipanggil dari Dart lewat kanal
 * `id.payoung.kasir/layar-pelanggan`:
 * - `CekTersedia` → bool (ada display kategori presentation)
 * - `Tampilkan` {Isi: JSON `IsiLayarPelanggan` + `Warna`} → tampilkan/perbarui
 * - `Tutup` → tutup layar kedua
 * Isi berupa teks yang sudah diformat Dart (QRIS dinamis: `ModulQr` = matriks modul "0/1" per baris, v2.05); warna dikirim dari token desain Dart (tidak ada warna lepas di sini).
 */
class KanalLayarPelanggan(private val aktivitas: Activity, messenger: BinaryMessenger) : MethodChannel.MethodCallHandler {
    companion object {
        const val NAMA_KANAL = "id.payoung.kasir/layar-pelanggan"
    }

    private val kanal = MethodChannel(messenger, NAMA_KANAL)
    private var presentasi: PresentasiPelanggan? = null

    init {
        kanal.setMethodCallHandler(this)
    }

    fun Tutup() {
        kanal.setMethodCallHandler(null)
        presentasi?.dismiss()
        presentasi = null
    }

    private fun CariDisplay(): Display? {
        val manajer = aktivitas.getSystemService(Context.DISPLAY_SERVICE) as? DisplayManager ?: return null
        return manajer.getDisplays(DisplayManager.DISPLAY_CATEGORY_PRESENTATION).firstOrNull()
    }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "CekTersedia" -> result.success(CariDisplay() != null)
            "Tampilkan" -> {
                val isi = call.argument<String>("Isi")
                if (isi == null) {
                    result.error("ArgumenTidakValid", "Isi layar pelanggan wajib diisi.", null)
                    return
                }
                try {
                    Tampilkan(JSONObject(isi))
                    result.success(presentasi != null)
                } catch (galat: Exception) {
                    result.error("GagalTampil", "Layar pelanggan tidak bisa ditampilkan: ${galat.message}", null)
                }
            }
            "Tutup" -> {
                presentasi?.dismiss()
                presentasi = null
                result.success(null)
            }
            else -> result.notImplemented()
        }
    }

    private fun Tampilkan(isi: JSONObject) {
        val display = CariDisplay()
        if (display == null) {
            presentasi?.dismiss()
            presentasi = null
            return
        }
        val lama = presentasi
        if (lama == null || lama.display.displayId != display.displayId || !lama.isShowing) {
            lama?.dismiss()
            presentasi = PresentasiPelanggan(aktivitas, display).also {
                try {
                    it.show()
                } catch (_: WindowManager.InvalidDisplayException) {
                    presentasi = null
                    return
                }
            }
        }
        presentasi?.Perbarui(isi)
    }
}

/** Tampilan layar kedua: nama toko, daftar item, ringkasan, total besar, dan pesan. */
private class PresentasiPelanggan(konteks: Context, display: Display) : Presentation(konteks, display) {
    private lateinit var akar: LinearLayout
    private lateinit var judul: TextView
    private lateinit var daftar: LinearLayout
    private lateinit var ringkasan: LinearLayout
    private lateinit var labelTotal: TextView
    private lateinit var total: TextView
    private lateinit var pesan: TextView
    private lateinit var qr: TampilanQr
    private var huruf: Typeface = Typeface.DEFAULT
    private var hurufTebal: Typeface = Typeface.DEFAULT_BOLD

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        try {
            val pemuat = FlutterInjector.instance().flutterLoader()
            huruf = Typeface.createFromAsset(context.assets, pemuat.getLookupKeyForAsset("assets/fonts/Lato-Regular.ttf", "sistem_desain"))
            hurufTebal = Typeface.createFromAsset(context.assets, pemuat.getLookupKeyForAsset("assets/fonts/Lato-Bold.ttf", "sistem_desain"))
        } catch (_: Exception) {
            // Font bawaan sistem bila aset tidak ditemukan.
        }
        val jarak = Dp(24)
        akar = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(jarak, jarak, jarak, jarak)
        }
        judul = Teks(28f, tebal = true)
        daftar = LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
        val gulir = ScrollView(context).apply { addView(daftar) }
        ringkasan = LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
        labelTotal = Teks(28f, tebal = true)
        total = Teks(56f, tebal = true).apply { gravity = Gravity.END }
        pesan = Teks(28f).apply { gravity = Gravity.CENTER }
        qr = TampilanQr(context).apply { visibility = View.GONE }
        val barisTotal = LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            addView(labelTotal, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
            addView(total)
        }
        akar.addView(judul)
        akar.addView(gulir, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f))
        akar.addView(ringkasan)
        akar.addView(barisTotal)
        akar.addView(pesan)
        akar.addView(qr, LinearLayout.LayoutParams(Dp(280), Dp(280)).apply { gravity = Gravity.CENTER_HORIZONTAL })
        setContentView(akar)
    }

    private fun Dp(nilai: Int): Int =
        TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, nilai.toFloat(), context.resources.displayMetrics).toInt()

    private fun Teks(ukuranSp: Float, tebal: Boolean = false): TextView = TextView(context).apply {
        setTextSize(TypedValue.COMPLEX_UNIT_SP, ukuranSp)
        typeface = if (tebal) hurufTebal else huruf
    }

    private fun Baris(nama: String, rincian: String?, nilai: String, warnaTeks: Int, warnaSekunder: Int, ukuran: Float): View {
        val kiri = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            addView(Teks(ukuran).apply { text = nama; setTextColor(warnaTeks) })
            if (!rincian.isNullOrEmpty()) {
                addView(Teks(ukuran * 0.75f).apply { text = rincian; setTextColor(warnaSekunder) })
            }
        }
        return LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, Dp(6), 0, Dp(6))
            addView(kiri, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
            addView(Teks(ukuran).apply { text = nilai; setTextColor(warnaTeks); gravity = Gravity.END })
        }
    }

    fun Perbarui(isi: JSONObject) {
        val warna = isi.optJSONObject("Warna")
        val latar = warna?.optInt("Latar") ?: 0xFFFFFFFF.toInt()
        val teks = warna?.optInt("Teks") ?: 0xFF000000.toInt()
        val sekunder = warna?.optInt("TeksSekunder") ?: teks
        val aksen = warna?.optInt("Aksen") ?: teks
        akar.setBackgroundColor(latar)

        judul.text = isi.optString("NamaToko")
        judul.setTextColor(teks)

        daftar.removeAllViews()
        val baris = isi.optJSONArray("Baris")
        for (i in 0 until (baris?.length() ?: 0)) {
            val b = baris!!.getJSONObject(i)
            daftar.addView(Baris(b.optString("Nama"), b.optString("Rincian").takeIf { !b.isNull("Rincian") }, b.optString("Nilai"), teks, sekunder, 22f))
        }
        ringkasan.removeAllViews()
        val daftarRingkasan = isi.optJSONArray("Ringkasan")
        for (i in 0 until (daftarRingkasan?.length() ?: 0)) {
            val r = daftarRingkasan!!.getJSONObject(i)
            ringkasan.addView(Baris(r.optString("Nama"), null, r.optString("Nilai"), sekunder, sekunder, 20f))
        }

        val adaTotal = !isi.isNull("Total")
        labelTotal.visibility = if (adaTotal) View.VISIBLE else View.GONE
        total.visibility = if (adaTotal) View.VISIBLE else View.GONE
        labelTotal.text = isi.optString("LabelTotal")
        labelTotal.setTextColor(teks)
        total.text = if (adaTotal) isi.optString("Total") else ""
        total.setTextColor(aksen)

        val adaPesan = !isi.isNull("Pesan")
        pesan.visibility = if (adaPesan) View.VISIBLE else View.GONE
        pesan.text = if (adaPesan) isi.optString("Pesan") else ""
        pesan.setTextColor(teks)

        // QRIS dinamis (v2.05): matriks modul dari Dart ("0/1" per baris), digambar tanpa pustaka QR.
        val modul = isi.optJSONArray("ModulQr")
        if (modul == null || modul.length() == 0) {
            qr.visibility = View.GONE
        } else {
            qr.Atur(List(modul.length()) { modul.getString(it) }, latar, teks)
            qr.visibility = View.VISIBLE
        }
    }
}

/** Kode QR dari matriks modul dengan zona tenang 4 modul. */
private class TampilanQr(konteks: Context) : View(konteks) {
    private var modul: List<String> = emptyList()
    private val kuasModul = Paint().apply { isAntiAlias = false }
    private var warnaLatar = 0

    fun Atur(baru: List<String>, latar: Int, gelap: Int) {
        modul = baru
        warnaLatar = latar
        kuasModul.color = gelap
        invalidate()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (modul.isEmpty()) return
        canvas.drawColor(warnaLatar)
        val jumlah = modul.size + 8
        val sisi = minOf(width, height).toFloat() / jumlah
        for ((r, baris) in modul.withIndex()) {
            for ((c, nilai) in baris.withIndex()) {
                if (nilai == '1') {
                    val x = (c + 4) * sisi
                    val y = (r + 4) * sisi
                    canvas.drawRect(x, y, x + sisi + 0.5f, y + sisi + 0.5f, kuasModul)
                }
            }
        }
    }
}
