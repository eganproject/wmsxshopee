package tech.optisync.gudang

import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.net.Uri
import android.os.Build
import android.webkit.CookieManager
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

/**
 * Unduh APK update dari server Optima lalu buka layar pasang Android.
 * Sebelum dipasang, file dicek: checksum sama dengan yang dicatat server, nama paket
 * sama dengan aplikasi ini, dan nomor build sesuai. Tanda tangan APK dicek oleh Android
 * sendiri: update hanya bisa dipasang bila kuncinya sama dengan versi terpasang.
 */
class AppUpdater(private val context: Context) {

    class Release(val url: String, val versionCode: Long, val sha256: String, val size: Long) {
        companion object {
            fun from(json: JSONObject) = Release(
                url = json.optString("url"),
                versionCode = json.optLong("versionCode"),
                sha256 = json.optString("sha256").lowercase(),
                size = json.optLong("size"),
            )
        }
    }

    private val dir = File(context.cacheDir, "updates")

    /** Unduh (di thread latar). onProgress(diterima, total byte). Mengembalikan file APK yang sudah dicek. */
    fun download(release: Release, onProgress: (Long, Long) -> Unit): File {
        if (!release.url.startsWith("https://") && !release.url.startsWith("http://")) {
            throw IOException("Alamat update tidak valid.")
        }
        if (release.versionCode <= currentVersionCode()) {
            throw IOException("Versi ini sudah terpasang.")
        }

        dir.mkdirs()
        dir.listFiles()?.forEach { it.delete() }
        val file = File(dir, "optima-gudang-${release.versionCode}.apk")

        val connection = (URL(release.url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 20_000
            readTimeout = 60_000
            instanceFollowRedirects = true
            setRequestProperty("User-Agent", "OptimaGudang/${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
            CookieManager.getInstance().getCookie(release.url)?.let { setRequestProperty("Cookie", it) }
        }

        try {
            val status = connection.responseCode
            if (status == 403) throw IOException("Link update kedaluwarsa. Tutup lalu buka lagi aplikasinya.")
            if (status !in 200..299) throw IOException("Server menolak unduhan (HTTP $status).")

            val total = connection.contentLengthLong.takeIf { it > 0 } ?: release.size
            val digest = MessageDigest.getInstance("SHA-256")
            var received = 0L
            var lastReport = 0L

            connection.inputStream.use { input ->
                file.outputStream().use { output ->
                    val buffer = ByteArray(64 * 1024)
                    while (true) {
                        val read = input.read(buffer)
                        if (read < 0) break
                        output.write(buffer, 0, read)
                        digest.update(buffer, 0, read)
                        received += read
                        if (received - lastReport >= 256 * 1024) {
                            lastReport = received
                            onProgress(received, total)
                        }
                    }
                }
            }
            onProgress(received, total)

            val hash = digest.digest().joinToString("") { "%02x".format(it) }
            if (release.sha256.isNotEmpty() && hash != release.sha256) {
                throw IOException("File update rusak saat diunduh. Coba lagi.")
            }

            verify(file, release)
            return file
        } catch (e: Exception) {
            file.delete()
            throw e
        } finally {
            connection.disconnect()
        }
    }

    /** Pastikan APK memang Optima Gudang dengan nomor build yang dijanjikan server. */
    private fun verify(file: File, release: Release) {
        val info = archiveInfo(file) ?: throw IOException("File update bukan APK yang valid.")
        if (info.packageName != context.packageName) {
            throw IOException("APK di server bukan untuk aplikasi ini (${info.packageName}).")
        }
        val code = versionCodeOf(info)
        if (code != release.versionCode) {
            throw IOException("Nomor build APK ($code) tidak sama dengan yang dicatat di server (${release.versionCode}). Periksa versi di halaman Aplikasi Android.")
        }
    }

    /** Android 8+: aplikasi harus diizinkan memasang aplikasi (Instal aplikasi tidak dikenal). */
    fun canInstall(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()

    /** Buka layar pasang Android untuk file yang sudah diunduh. */
    fun installIntent(file: File): Intent {
        val uri: Uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        return Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
    }

    private fun currentVersionCode(): Long =
        versionCodeOf(context.packageManager.getPackageInfo(context.packageName, 0))

    @Suppress("DEPRECATION")
    private fun archiveInfo(file: File): PackageInfo? =
        context.packageManager.getPackageArchiveInfo(file.absolutePath, 0)

    @Suppress("DEPRECATION")
    private fun versionCodeOf(info: PackageInfo): Long =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) info.longVersionCode else info.versionCode.toLong()
}
