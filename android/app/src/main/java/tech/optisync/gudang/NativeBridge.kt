package tech.optisync.gudang

import android.webkit.JavascriptInterface
import org.json.JSONArray
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Fungsi perangkat yang bisa dipanggil halaman lewat `window.OptimaNative`.
 * Hasil yang butuh waktu (izin, cetak) dikirim balik lewat
 * `OptimaBridge._resolve(id, hasil)` di JavaScript.
 */
class NativeBridge(private val activity: MainActivity, private val prefs: Prefs) {

    @JavascriptInterface
    fun appInfo(): String = JSONObject()
        .put("versionCode", BuildConfig.VERSION_CODE)
        .put("versionName", BuildConfig.VERSION_NAME)
        .put("server", prefs.server ?: JSONObject.NULL)
        .put("defaultServer", BuildConfig.DEFAULT_SERVER)
        .toString()

    /** Simpan alamat server dari layar awal lalu buka Optima. */
    @JavascriptInterface
    fun saveServer(url: String): String {
        val normalized = Prefs.normalize(url) ?: return "Alamat server tidak valid."
        prefs.server = normalized
        activity.runOnUiThread {
            activity.web.clearHistory()
            activity.loadStart()
        }
        return ""
    }

    @JavascriptInterface
    fun changeServer() {
        prefs.server = null
        activity.runOnUiThread { activity.loadStart() }
    }

    @JavascriptInterface
    fun reload() {
        activity.runOnUiThread { activity.loadStart() }
    }

    @JavascriptInterface
    fun openScanner(options: String) {
        val json = try { JSONObject(options) } catch (e: Exception) { JSONObject() }
        activity.runOnUiThread { activity.openScanner(json) }
    }

    @JavascriptInterface
    fun closeScanner() {
        activity.runOnUiThread { activity.closeScanner(false) }
    }

    /** kind: scan | ok | warn | error */
    @JavascriptInterface
    fun feedback(kind: String) {
        Feedback.play(activity, kind)
    }

    @JavascriptInterface
    fun setSystemBars(color: String, light: Boolean) {
        activity.runOnUiThread { activity.applySystemBars(color, light) }
    }

    @JavascriptInterface
    fun openExternal(url: String) {
        activity.runOnUiThread { activity.openExternal(url) }
    }

    @JavascriptInterface
    fun toast(message: String) {
        activity.toast(message)
    }

    /** Daftar printer Bluetooth yang sudah dipasangkan (pairing) di pengaturan HP. */
    @JavascriptInterface
    fun listPrinters(id: String) {
        activity.runOnUiThread {
            activity.withPermissions(activity.bluetoothPermissions()) { granted ->
                if (!granted) {
                    resolve(id, false, "Izin Bluetooth ditolak.", null)
                    return@withPermissions
                }
                thread {
                    try {
                        resolve(id, true, "", BluetoothPrinter.paired(activity))
                    } catch (e: Exception) {
                        resolve(id, false, e.message ?: "Bluetooth tidak tersedia.", null)
                    }
                }
            }
        }
    }

    /**
     * Cetak ke printer thermal. job (JSON):
     * { printer: {address, lang: "escpos"|"tspl", widthMm, heightMm?, gapMm?},
     *   image?: "data:image/png;base64,...", url?: "https://.../label.pdf", copies?: 1 }
     */
    @JavascriptInterface
    fun print(id: String, job: String) {
        val json = try { JSONObject(job) } catch (e: Exception) {
            resolve(id, false, "Data cetak tidak valid.", null)
            return
        }
        activity.runOnUiThread {
            activity.withPermissions(activity.bluetoothPermissions()) { granted ->
                if (!granted) {
                    resolve(id, false, "Izin Bluetooth ditolak.", null)
                    return@withPermissions
                }
                thread {
                    try {
                        PrintJob(activity, json).run()
                        resolve(id, true, "", null)
                    } catch (e: Exception) {
                        resolve(id, false, e.message ?: "Gagal mencetak.", null)
                    }
                }
            }
        }
    }

    private fun resolve(id: String, ok: Boolean, message: String, data: JSONArray?) {
        val payload = JSONObject().put("ok", ok).put("message", message).put("data", data ?: JSONObject.NULL)
        activity.js("window.OptimaBridge && OptimaBridge._resolve(${JSONObject.quote(id)}, $payload)")
    }
}
