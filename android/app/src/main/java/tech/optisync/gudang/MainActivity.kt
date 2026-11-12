package tech.optisync.gudang

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.MediaStore
import android.provider.Settings
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.view.animation.Animation
import android.view.animation.TranslateAnimation
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.ImageButton
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.view.WindowInsetsControllerCompat
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewFeature
import org.json.JSONObject
import java.io.File
import kotlin.concurrent.thread

/**
 * Satu layar WebView yang memuat halaman mobile Optima (/m) dari server.
 * Fitur yang butuh perangkat (kamera scan, foto, printer Bluetooth, getar/bunyi)
 * disediakan lewat [NativeBridge] dengan nama `OptimaNative` di JavaScript.
 */
class MainActivity : AppCompatActivity() {

    lateinit var web: WebView
        private set
    private lateinit var scannerPanel: FrameLayout
    private lateinit var scanHint: TextView
    private lateinit var scanner: ScannerController
    private lateinit var prefs: Prefs

    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var photoUri: Uri? = null
    private var pendingPermission: ((Boolean) -> Unit)? = null
    private var torchOn = false
    private var updating = false
    private var pendingInstall: (() -> Unit)? = null

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
            val callback = pendingPermission
            pendingPermission = null
            callback?.invoke(result.values.all { it })
        }

    private val fileLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val callback = fileCallback ?: return@registerForActivityResult
            fileCallback = null
            if (result.resultCode != RESULT_OK) {
                callback.onReceiveValue(null)
                return@registerForActivityResult
            }
            val data = result.data
            val picked = when {
                data?.clipData != null -> Array(data.clipData!!.itemCount) { data.clipData!!.getItemAt(it).uri }
                data?.data != null -> arrayOf(data.data!!)
                photoUri != null -> arrayOf(photoUri!!)
                else -> null
            }
            callback.onReceiveValue(picked)
        }

    // Kembali dari pengaturan "Instal aplikasi tidak dikenal".
    private val installPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) {
            val next = pendingInstall
            pendingInstall = null
            next?.invoke()
        }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        prefs = Prefs(this)

        web = findViewById(R.id.web)
        scannerPanel = findViewById(R.id.scannerPanel)
        scanHint = findViewById(R.id.scanHint)
        scanner = ScannerController(this, findViewById<PreviewView>(R.id.preview)) { code, format ->
            onScanned(code, format)
        }

        findViewById<ImageButton>(R.id.scanClose).setOnClickListener { closeScanner(true) }
        findViewById<ImageButton>(R.id.scanTorch).setOnClickListener {
            torchOn = !torchOn
            scanner.torch(torchOn)
        }

        applySystemBars(null, null)
        setupWebView()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                when {
                    scannerPanel.visibility == View.VISIBLE -> closeScanner(true)
                    web.canGoBack() -> web.goBack()
                    else -> finish()
                }
            }
        })

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            loadStart()
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        // Tema gelap/terang HP berubah: biarkan halaman menyesuaikan diri.
        js("window.OptimaBridge && OptimaBridge._event('theme', {})")
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        with(web.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            allowFileAccess = true
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString OptimaGudang/${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"
        }
        if (WebViewFeature.isFeatureSupported(WebViewFeature.ALGORITHMIC_DARKENING)) {
            // Halaman punya tema gelap sendiri; jangan digelapkan otomatis oleh WebView.
            WebSettingsCompat.setAlgorithmicDarkeningAllowed(web.settings, false)
        }
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false)

        web.addJavascriptInterface(NativeBridge(this, prefs), "OptimaNative")

        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                val server = prefs.server?.let { Uri.parse(it) }
                val internal = uri.scheme == "file" ||
                    (server != null && uri.host == server.host && (uri.scheme == "http" || uri.scheme == "https"))
                if (internal && !uri.path.orEmpty().endsWith(".apk")) return false
                openExternal(uri.toString())
                return true
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) {
                    view.loadUrl("file:///android_asset/offline.html")
                }
            }
        }

        web.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView,
                callback: ValueCallback<Array<Uri>>,
                params: FileChooserParams,
            ): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                withPermissions(arrayOf(Manifest.permission.CAMERA)) { granted ->
                    launchChooser(params, granted)
                }
                return true
            }
        }

        web.setDownloadListener { url, _, _, _, _ -> openExternal(url) }
    }

    private fun launchChooser(params: WebChromeClient.FileChooserParams, cameraAllowed: Boolean) {
        val acceptsImage = params.acceptTypes.any { it.isBlank() || it.startsWith("image") }
        var camera: Intent? = null
        if (cameraAllowed && acceptsImage) {
            val dir = File(cacheDir, "photos").apply { mkdirs() }
            val file = File.createTempFile("foto-", ".jpg", dir)
            photoUri = FileProvider.getUriForFile(this, "$packageName.files", file)
            camera = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
                .putExtra(MediaStore.EXTRA_OUTPUT, photoUri)
                .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
        } else {
            photoUri = null
        }

        val intent = when {
            camera != null && params.isCaptureEnabled -> camera
            camera != null -> Intent.createChooser(params.createIntent(), "Ambil foto").putExtra(Intent.EXTRA_INITIAL_INTENTS, arrayOf(camera))
            else -> params.createIntent()
        }

        try {
            fileLauncher.launch(intent)
        } catch (e: Exception) {
            fileCallback?.onReceiveValue(null)
            fileCallback = null
            toast("Kamera / galeri tidak bisa dibuka")
        }
    }

    fun loadStart() {
        val server = prefs.server
        if (server == null) {
            web.loadUrl("file:///android_asset/setup.html")
        } else {
            web.loadUrl("$server/m")
        }
    }

    fun openExternal(url: String) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        } catch (e: Exception) {
            toast("Tidak ada aplikasi untuk membuka tautan ini")
        }
    }

    fun withPermissions(permissions: Array<String>, onResult: (Boolean) -> Unit) {
        val missing = permissions.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isEmpty()) {
            onResult(true)
            return
        }
        pendingPermission = onResult
        permissionLauncher.launch(missing.toTypedArray())
    }

    fun bluetoothPermissions(): Array<String> =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) arrayOf(Manifest.permission.BLUETOOTH_CONNECT) else emptyArray()

    /** Buka kamera scan. mode "full" = penuh layar, "top" = bagian atas, halaman tetap terlihat di bawahnya. */
    fun openScanner(options: JSONObject) {
        withPermissions(arrayOf(Manifest.permission.CAMERA)) { granted ->
            if (!granted) {
                toast("Izin kamera ditolak. Scan pakai scanner atau ketik manual.")
                js("window.OptimaBridge && OptimaBridge._event('scanner', {open:false})")
                return@withPermissions
            }
            val top = options.optString("mode", "full") == "top"
            val height = if (top) (resources.displayMetrics.heightPixels * options.optDouble("ratio", 0.34)).toInt() else ViewGroup.LayoutParams.MATCH_PARENT
            scannerPanel.layoutParams = (scannerPanel.layoutParams as FrameLayout.LayoutParams).apply { this.height = height }
            (web.layoutParams as FrameLayout.LayoutParams).topMargin = if (top) height else 0
            web.requestLayout()

            val frame = findViewById<View>(R.id.scanFrame)
            frame.layoutParams = (frame.layoutParams as FrameLayout.LayoutParams).apply {
                this.height = dp(if (top) 110 else 180)
                this.width = dp(if (top) 290 else 290)
            }

            scanHint.text = options.optString("hint", "Arahkan kamera ke barcode")
            scanHint.visibility = if (top) View.GONE else View.VISIBLE
            scannerPanel.visibility = View.VISIBLE
            animateScanLine()
            window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
            scanner.continuous = options.optBoolean("continuous", false)
            scanner.start()
            js("window.OptimaBridge && OptimaBridge._event('scanner', {open:true})")
        }
    }

    fun closeScanner(notify: Boolean) {
        if (scannerPanel.visibility != View.VISIBLE) return
        scanner.stop()
        torchOn = false
        findViewById<View>(R.id.scanLine).clearAnimation()
        scannerPanel.visibility = View.GONE
        (web.layoutParams as FrameLayout.LayoutParams).topMargin = 0
        web.requestLayout()
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (notify) js("window.OptimaBridge && OptimaBridge._event('scanner', {open:false})")
    }

    private fun onScanned(code: String, format: String) {
        Feedback.play(this, "scan")
        val payload = JSONObject().put("code", code).put("format", format)
        js("window.OptimaBridge && OptimaBridge._event('scan', $payload)")
        if (!scanner.continuous) closeScanner(true)
    }

    private fun animateScanLine() {
        val line = findViewById<View>(R.id.scanLine)
        val anim = TranslateAnimation(
            Animation.RELATIVE_TO_PARENT, 0f, Animation.RELATIVE_TO_PARENT, 0f,
            Animation.RELATIVE_TO_PARENT, -0.32f, Animation.RELATIVE_TO_PARENT, 0.32f,
        ).apply {
            duration = 1100
            repeatMode = Animation.REVERSE
            repeatCount = Animation.INFINITE
        }
        line.startAnimation(anim)
    }

    /**
     * Unduh update lalu buka layar pasang Android. done(ok, pesan) dipanggil saat layar
     * pasang terbuka atau saat gagal. Bila izin memasang aplikasi belum ada, pengguna
     * diarahkan ke pengaturannya dulu lalu pemasangan dilanjutkan.
     */
    fun startUpdate(release: AppUpdater.Release, done: (Boolean, String) -> Unit) {
        if (updating) {
            done(false, "Update sedang diunduh.")
            return
        }
        updating = true
        val updater = AppUpdater(this)
        thread {
            val file = try {
                updater.download(release) { received, total ->
                    js("window.OptimaBridge && OptimaBridge._event('update-progress', {received:$received,total:$total})")
                }
            } catch (e: Exception) {
                updating = false
                done(false, e.message ?: "Gagal mengunduh update.")
                return@thread
            }
            runOnUiThread {
                updating = false
                val install = {
                    try {
                        startActivity(updater.installIntent(file))
                        done(true, "")
                    } catch (e: Exception) {
                        done(false, "Layar pasang Android tidak bisa dibuka.")
                    }
                }
                if (updater.canInstall()) {
                    install()
                } else {
                    toast("Izinkan Optima Gudang memasang aplikasi, lalu tekan kembali.")
                    pendingInstall = {
                        if (updater.canInstall()) install() else done(false, "Izin \"Instal aplikasi tidak dikenal\" belum diberikan untuk Optima Gudang.")
                    }
                    try {
                        installPermissionLauncher.launch(
                            Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")),
                        )
                    } catch (e: Exception) {
                        pendingInstall = null
                        done(false, "Buka Pengaturan HP lalu izinkan Optima Gudang memasang aplikasi.")
                    }
                }
            }
        }
    }

    /** Warna status bar & navigasi mengikuti halaman (terang / gelap). */
    fun applySystemBars(color: String?, light: Boolean?) {
        val night = (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        val isLight = light ?: !night
        val parsed = try {
            color?.let { Color.parseColor(it) }
        } catch (e: IllegalArgumentException) {
            null
        } ?: if (isLight) Color.parseColor("#F5F5F5") else Color.parseColor("#121212")
        @Suppress("DEPRECATION")
        window.statusBarColor = parsed
        @Suppress("DEPRECATION")
        window.navigationBarColor = parsed
        WindowInsetsControllerCompat(window, window.decorView).apply {
            isAppearanceLightStatusBars = isLight
            isAppearanceLightNavigationBars = isLight
        }
    }

    /** Jalankan JavaScript di halaman (selalu dari UI thread). */
    fun js(script: String) {
        runOnUiThread { web.evaluateJavascript(script, null) }
    }

    fun toast(message: String) {
        runOnUiThread { Toast.makeText(this, message, Toast.LENGTH_LONG).show() }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
