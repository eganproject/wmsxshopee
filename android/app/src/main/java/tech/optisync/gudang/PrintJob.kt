package tech.optisync.gudang

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import android.util.Base64
import android.webkit.CookieManager
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/**
 * Satu pekerjaan cetak: gambar (label dari halaman) atau URL (PDF resi marketplace)
 * diubah jadi bitmap hitam-putih lalu dikirim sebagai perintah ESC/POS atau TSPL.
 */
class PrintJob(private val activity: MainActivity, private val job: JSONObject) {
    private val printer = job.optJSONObject("printer") ?: throw IllegalArgumentException("Pilih printer dulu di menu Cetak Label.")
    private val address = printer.optString("address").ifBlank { throw IllegalArgumentException("Pilih printer dulu di menu Cetak Label.") }
    private val lang = printer.optString("lang", "escpos")
    private val widthMm = printer.optDouble("widthMm", 58.0)
    private val gapMm = printer.optDouble("gapMm", 2.0)
    private val dotsPerMm = printer.optDouble("dpmm", 8.0)
    private val copies = job.optInt("copies", 1).coerceIn(1, 500)

    fun run() {
        val pages = when {
            job.has("image") -> listOf(decodeImage(job.getString("image")))
            job.has("url") -> download(job.getString("url"))
            else -> throw IllegalArgumentException("Tidak ada yang dicetak.")
        }
        val widthDots = (widthMm * dotsPerMm).toInt() / 8 * 8
        val out = ByteArrayOutputStream()
        repeat(copies) {
            pages.forEach { page ->
                val mono = Raster.from(page, widthDots)
                out.write(if (lang == "tspl") Raster.tspl(mono, widthMm, dotsPerMm, gapMm) else Raster.escpos(mono))
            }
        }
        BluetoothPrinter.send(activity, address, out.toByteArray())
    }

    private fun decodeImage(data: String): Bitmap {
        val base64 = data.substringAfter("base64,", data)
        val bytes = Base64.decode(base64, Base64.DEFAULT)
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size) ?: throw IOException("Gambar label tidak terbaca.")
    }

    /** Unduh pakai cookie login WebView. PDF dirender per halaman, gambar langsung dipakai. */
    private fun download(url: String): List<Bitmap> {
        val connection = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 15000
            readTimeout = 30000
            CookieManager.getInstance().getCookie(url)?.let { setRequestProperty("Cookie", it) }
            setRequestProperty("Accept", "application/pdf,image/*")
            setRequestProperty("User-Agent", "OptimaGudang/${BuildConfig.VERSION_NAME}")
        }
        try {
            if (connection.responseCode !in 200..299) throw IOException("Label tidak bisa diambil (HTTP ${connection.responseCode}).")
            val type = connection.contentType.orEmpty()
            val bytes = connection.inputStream.use { it.readBytes() }
            return when {
                type.contains("pdf") || bytes.startsWith("%PDF") -> renderPdf(bytes)
                type.startsWith("image/") -> listOf(BitmapFactory.decodeByteArray(bytes, 0, bytes.size) ?: throw IOException("Gambar label tidak terbaca."))
                else -> throw IOException("Label ini bukan PDF. Cetak dari Optima web.")
            }
        } finally {
            connection.disconnect()
        }
    }

    private fun renderPdf(bytes: ByteArray): List<Bitmap> {
        val file = File.createTempFile("label-", ".pdf", activity.cacheDir)
        file.writeBytes(bytes)
        val widthPx = (widthMm * dotsPerMm).toInt()
        try {
            ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY).use { descriptor ->
                PdfRenderer(descriptor).use { renderer ->
                    return (0 until renderer.pageCount).map { index ->
                        renderer.openPage(index).use { page ->
                            val height = (page.height.toDouble() * widthPx / page.width).toInt()
                            val bitmap = Bitmap.createBitmap(widthPx, height, Bitmap.Config.ARGB_8888)
                            bitmap.eraseColor(Color.WHITE)
                            page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_PRINT)
                            bitmap
                        }
                    }
                }
            }
        } finally {
            file.delete()
        }
    }

    private fun ByteArray.startsWith(prefix: String): Boolean =
        size >= prefix.length && prefix.indices.all { this[it] == prefix[it].code.toByte() }
}

/** Bitmap 1-bit: satu baris = widthBytes byte, bit 1 = titik hitam. */
class MonoBitmap(val widthBytes: Int, val height: Int, val data: ByteArray)

object Raster {
    fun from(source: Bitmap, widthDots: Int): MonoBitmap {
        val height = maxOf(1, (source.height.toDouble() * widthDots / source.width).toInt())
        val scaled = Bitmap.createBitmap(widthDots, height, Bitmap.Config.ARGB_8888)
        Canvas(scaled).apply {
            drawColor(Color.WHITE)
            drawBitmap(Bitmap.createScaledBitmap(source, widthDots, height, true), 0f, 0f, Paint(Paint.FILTER_BITMAP_FLAG))
        }
        val widthBytes = widthDots / 8
        val data = ByteArray(widthBytes * height)
        val row = IntArray(widthDots)
        for (y in 0 until height) {
            scaled.getPixels(row, 0, widthDots, 0, y, widthDots, 1)
            for (x in 0 until widthDots) {
                val pixel = row[x]
                val luminance = (Color.red(pixel) * 299 + Color.green(pixel) * 587 + Color.blue(pixel) * 114) / 1000
                if (Color.alpha(pixel) > 128 && luminance < 150) {
                    val index = y * widthBytes + x / 8
                    data[index] = (data[index].toInt() or (0x80 shr (x % 8))).toByte()
                }
            }
        }
        return MonoBitmap(widthBytes, height, data)
    }

    /** ESC/POS: GS v 0 per potongan 128 baris (aman untuk buffer printer kecil). */
    fun escpos(bitmap: MonoBitmap): ByteArray {
        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(0x1B, 0x40))
        var y = 0
        while (y < bitmap.height) {
            val rows = minOf(128, bitmap.height - y)
            out.write(byteArrayOf(0x1D, 0x76, 0x30, 0x00,
                (bitmap.widthBytes and 0xFF).toByte(), (bitmap.widthBytes shr 8 and 0xFF).toByte(),
                (rows and 0xFF).toByte(), (rows shr 8 and 0xFF).toByte()))
            out.write(bitmap.data, y * bitmap.widthBytes, rows * bitmap.widthBytes)
            y += rows
        }
        out.write(byteArrayOf(0x1B, 0x64, 0x04))
        return out.toByteArray()
    }

    /** TSPL (printer label seperti Xprinter seri label): bit 0 = hitam, jadi dibalik. */
    fun tspl(bitmap: MonoBitmap, widthMm: Double, dotsPerMm: Double, gapMm: Double): ByteArray {
        val heightMm = bitmap.height / dotsPerMm
        val out = ByteArrayOutputStream()
        val header = "SIZE ${fmt(widthMm)} mm,${fmt(heightMm)} mm\r\nGAP ${fmt(gapMm)} mm,0 mm\r\nDIRECTION 1\r\nCLS\r\n" +
            "BITMAP 0,0,${bitmap.widthBytes},${bitmap.height},0,"
        out.write(header.toByteArray(Charsets.US_ASCII))
        out.write(ByteArray(bitmap.data.size) { (bitmap.data[it].toInt() xor 0xFF).toByte() })
        out.write("\r\nPRINT 1,1\r\n".toByteArray(Charsets.US_ASCII))
        return out.toByteArray()
    }

    private fun fmt(value: Double): String = String.format(java.util.Locale.US, "%.1f", value)
}
