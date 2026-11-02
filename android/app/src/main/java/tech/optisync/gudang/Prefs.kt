package tech.optisync.gudang

import android.content.Context

/** Pengaturan lokal aplikasi: alamat server Optima. */
class Prefs(context: Context) {
    private val store = context.getSharedPreferences("optima", Context.MODE_PRIVATE)

    var server: String?
        get() = store.getString("server", null)
        set(value) {
            store.edit().putString("server", value).apply()
        }

    companion object {
        /** Rapikan alamat: tambah https:// bila tidak ada skema, buang garis miring & "/m" di akhir. */
        fun normalize(input: String): String? {
            var url = input.trim()
            if (url.isEmpty()) return null
            if (!url.startsWith("http://") && !url.startsWith("https://")) url = "https://$url"
            url = url.trimEnd('/')
            if (url.endsWith("/m")) url = url.removeSuffix("/m")
            val uri = android.net.Uri.parse(url)
            if (uri.host.isNullOrBlank()) return null
            return url
        }
    }
}
